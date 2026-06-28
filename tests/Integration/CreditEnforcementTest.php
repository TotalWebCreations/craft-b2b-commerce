<?php

use craft\commerce\elements\Order;
use craft\commerce\Plugin as Commerce;
use craft\db\Query;
use totalwebcreations\b2bcommerce\elements\Company;
use totalwebcreations\b2bcommerce\enums\CompanyRole;
use totalwebcreations\b2bcommerce\Plugin;

/**
 * Creates a tracked, approved company allowed to pay on account with the given credit limit.
 */
function enforcementCompany(?float $creditLimit): Company
{
    $company = createTestCompany(Company::STATUS_APPROVED);
    $company->allowInvoicePayment = true;
    $company->creditLimit = $creditLimit;

    if (!craftApp()->getElements()->saveElement($company)) {
        throw new RuntimeException('Could not save test company: ' . implode(', ', $company->getFirstErrors()));
    }

    return $company;
}

/**
 * Builds a tracked, incomplete invoice-gateway order priced at $price for a fresh member of the
 * company. The caller drives completion so refusal (a thrown exception from markAsComplete) can be
 * asserted. Reuses the shared invoice gateway fixture from CreditBalanceTest.
 */
function invoiceOrderForNewMember(Company $company, float $price): Order
{
    $user = createTestUser('creditenf_' . uniqid() . '@example.test');
    Plugin::getInstance()->companyMembers->addUserToCompany($user->id, $company->id, CompanyRole::Admin);

    $variant = createTestVariant('CRDENF-' . uniqid(), $price);

    $order = new Order();
    $order->number = md5(uniqid((string) mt_rand(), true));
    $order->setCustomer($user);
    $order->gatewayId = creditTestInvoiceGateway()->id;

    $lineItem = Commerce::getInstance()->getLineItems()->resolveLineItem($order, $variant->id);
    $lineItem->qty = 1;
    $order->addLineItem($lineItem);

    if (!craftApp()->getElements()->saveElement($order)) {
        throw new RuntimeException('Could not save test order: ' . implode(', ', $order->getFirstErrors()));
    }

    trackElement($order);

    return $order;
}

/**
 * Reports whether the order is persisted as completed in the database.
 */
function orderCompletedInDb(int $orderId): bool
{
    return (new Query())
        ->from('{{%commerce_orders}}')
        ->where(['id' => $orderId, 'isCompleted' => true])
        ->exists();
}

/**
 * Completes the order as if from the storefront -- a non-console, non-CP request, the only origin
 * credit enforcement acts on -- and reports whether completion was refused by a thrown exception.
 * (asSiteRequest is defined in OrderCompanyTest.php and shared across the suite.)
 *
 * Only the REFUSAL path can be driven this way: enforcement throws in EVENT_BEFORE_COMPLETE_ORDER,
 * before the completion save. A would-be SUCCESSFUL completion cannot be driven here -- the save's
 * order-history write reaches Craft::$app->getResponse()->isSent, and this console harness has a
 * console Response with no such property once the request is faked non-console. Success and the
 * lock hand-off are therefore exercised through the service methods directly (see below).
 */
function refuseCompletionAsSiteRequest(Order $order): bool
{
    $refused = false;

    asSiteRequest(function () use ($order, &$refused) {
        try {
            $order->markAsComplete();
        } catch (Throwable) {
            $refused = true;
        }
    });

    return $refused;
}

/**
 * Runs the credit enforcer's before-completion check directly under a faked storefront request and
 * reports whether it refused (threw). On a pass the enforcer leaves the per-company lock held for
 * the after-handler; callers must release it (releaseCreditLock) to clean up.
 */
function enforceAsSiteRequest(Order $order): bool
{
    $refused = false;

    asSiteRequest(function () use ($order, &$refused) {
        try {
            Plugin::getInstance()->creditEnforcer->enforceCreditLimit($order);
        } catch (Throwable) {
            $refused = true;
        }
    });

    return $refused;
}

it('allows an invoice order that stays within the credit limit', function () {
    $company = enforcementCompany(500.0);
    $order = invoiceOrderForNewMember($company, 40.0);

    // Enforcement lets the completion through (does not throw). It leaves the per-company lock held
    // for the after-handler, so release it to clean up.
    expect(enforceAsSiteRequest($order))->toBeFalse();
    Plugin::getInstance()->creditEnforcer->releaseCreditLock($order);
});

it('refuses an invoice order that exceeds the credit limit', function () {
    $company = enforcementCompany(50.0);
    $order = invoiceOrderForNewMember($company, 60.0);

    $refused = refuseCompletionAsSiteRequest($order);

    // markAsComplete() flips the in-memory isCompleted flag before firing
    // EVENT_BEFORE_COMPLETE_ORDER, so the abort is observed by the row never being persisted as
    // completed (the _returnCart short-circuit), not by the in-memory flag.
    expect($refused)->toBeTrue()
        ->and(orderCompletedInDb($order->id))->toBeFalse()
        ->and(orderCompanyRowExists($order->id))->toBeFalse()
        ->and($order->getErrors('customerId'))
        ->toBe(["This order exceeds your company's credit limit."]);
});

it('skips credit enforcement on console and control-panel completions', function () {
    // The default test harness is a console request, so enforcement is skipped: an over-limit order
    // completes anyway. This is the deliberate merchant-initiated override (an admin completing an
    // order from the CP editor); only the storefront path is hard-enforced.
    $company = enforcementCompany(50.0);
    $order = invoiceOrderForNewMember($company, 500.0);

    expect($order->markAsComplete())->toBeTrue()
        ->and(orderCompletedInDb($order->id))->toBeTrue();
});

it('refuses a second invoice order once it would push the company over the limit', function () {
    $company = enforcementCompany(50.0);

    // The first order completes in console (enforcement skipped) but still links with isInvoice set,
    // building the company's outstanding balance to 40.
    $first = invoiceOrderForNewMember($company, 40.0);
    expect($first->markAsComplete())->toBeTrue()
        ->and(orderCompanyRowExists($first->id))->toBeTrue();

    // The second order (20) would push the company to 60, past its 50 limit, so the storefront
    // check refuses it.
    $second = invoiceOrderForNewMember($company, 20.0);

    expect(refuseCompletionAsSiteRequest($second))->toBeTrue()
        ->and(orderCompletedInDb($second->id))->toBeFalse()
        ->and($second->getErrors('customerId'))
        ->toBe(["This order exceeds your company's credit limit."]);
});

it('holds the company credit lock after the before-check and releases it in the after-handler', function () {
    // The full mutex lifecycle: acquired in EVENT_BEFORE_COMPLETE_ORDER on a passing check, kept
    // held (NOT released) so it spans the completion save and the b2b_order_company link write,
    // then released only by the after-handler -- wired AFTER linkCompany. Asserted at the service
    // boundary because this console harness cannot drive a full completion under a site request.
    $company = enforcementCompany(500.0);
    $order = invoiceOrderForNewMember($company, 40.0);

    $mutex = Craft::$app->getMutex();
    $lockName = "b2b-credit-{$company->id}";
    $enforcer = Plugin::getInstance()->creditEnforcer;

    expect($mutex->isAcquired($lockName))->toBeFalse();

    // Before-phase: a passing credit check hands the lock to the after-handler rather than releasing.
    expect(enforceAsSiteRequest($order))->toBeFalse()
        ->and($mutex->isAcquired($lockName))->toBeTrue();

    // After-phase: the release handler frees it, and a stray second release is a harmless no-op.
    $enforcer->releaseCreditLock($order);
    expect($mutex->isAcquired($lockName))->toBeFalse();

    $enforcer->releaseCreditLock($order);
    expect($mutex->isAcquired($lockName))->toBeFalse();
});

it('refuses completion when the company credit lock cannot be acquired', function () {
    $company = enforcementCompany(500.0);
    $order = invoiceOrderForNewMember($company, 10.0);

    $mutex = Craft::$app->getMutex();
    $lockName = "b2b-credit-{$company->id}";

    // In-process contention: pre-acquiring the same name on the mutex singleton makes the
    // enforcer's acquire() short-circuit to false via yii Mutex::_locks. This proves the REFUSAL
    // path, not cross-process DB locking (the test env uses NullMutex; the outer _locks guard is
    // what serialises here).
    expect($mutex->acquire($lockName))->toBeTrue();

    $refused = false;

    try {
        $refused = refuseCompletionAsSiteRequest($order);
    } finally {
        $mutex->release($lockName);
    }

    expect($refused)->toBeTrue()
        ->and(orderCompletedInDb($order->id))->toBeFalse()
        ->and($order->getErrors('customerId'))
        ->toBe(['Could not verify your company credit limit. Please try again.']);
});

it('still credit-checks a partially paid invoice order for its remaining balance', function () {
    // Remainder within the limit is allowed despite the payment: this proves the check runs on the
    // remainder rather than skipping paid orders the way the account-status backstop does.
    $withinCompany = enforcementCompany(50.0);
    $withinOrder = invoiceOrderForNewMember($withinCompany, 60.0);
    recordCreditPurchase($withinOrder, 20.0);

    expect($withinOrder->getTotalPaid())->toBeGreaterThan(0)
        ->and($withinOrder->getOutstandingBalance())->toBe(40.0)
        ->and(enforceAsSiteRequest($withinOrder))->toBeFalse();
    Plugin::getInstance()->creditEnforcer->releaseCreditLock($withinOrder);

    // Same shape but the remainder still exceeds the limit, so the payment does not buy a bypass.
    $overCompany = enforcementCompany(50.0);
    $overOrder = invoiceOrderForNewMember($overCompany, 100.0);
    recordCreditPurchase($overOrder, 40.0);

    $refused = refuseCompletionAsSiteRequest($overOrder);

    expect($overOrder->getTotalPaid())->toBeGreaterThan(0)
        ->and($refused)->toBeTrue()
        ->and(orderCompletedInDb($overOrder->id))->toBeFalse();
});
