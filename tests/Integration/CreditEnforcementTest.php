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

it('completes an invoice order that stays within the credit limit', function () {
    $company = enforcementCompany(500.0);
    $order = invoiceOrderForNewMember($company, 40.0);

    expect($order->markAsComplete())->toBeTrue()
        ->and(orderCompletedInDb($order->id))->toBeTrue()
        ->and(orderCompanyRowExists($order->id))->toBeTrue();
});

it('refuses an invoice order that exceeds the credit limit', function () {
    $company = enforcementCompany(50.0);
    $order = invoiceOrderForNewMember($company, 60.0);

    $refused = false;

    try {
        $order->markAsComplete();
    } catch (Throwable) {
        $refused = true;
    }

    // markAsComplete() flips the in-memory isCompleted flag before firing
    // EVENT_BEFORE_COMPLETE_ORDER, so the abort is observed by the row never being persisted as
    // completed (the _returnCart short-circuit), not by the in-memory flag.
    expect($refused)->toBeTrue()
        ->and(orderCompletedInDb($order->id))->toBeFalse()
        ->and(orderCompanyRowExists($order->id))->toBeFalse()
        ->and($order->getErrors('customerId'))
        ->toBe(["This order exceeds your company's credit limit."]);
});

it('refuses a second invoice order once it would push the company over the limit', function () {
    $company = enforcementCompany(50.0);

    $first = invoiceOrderForNewMember($company, 40.0);
    expect($first->markAsComplete())->toBeTrue();

    $second = invoiceOrderForNewMember($company, 20.0);
    $refused = false;

    try {
        $second->markAsComplete();
    } catch (Throwable) {
        $refused = true;
    }

    expect($refused)->toBeTrue()
        ->and(orderCompletedInDb($second->id))->toBeFalse()
        ->and($second->getErrors('customerId'))
        ->toBe(["This order exceeds your company's credit limit."]);
});

it('refuses completion when the company credit lock cannot be acquired', function () {
    $company = enforcementCompany(500.0);
    $order = invoiceOrderForNewMember($company, 10.0);

    $mutex = Craft::$app->getMutex();
    $lockName = "b2b-credit-{$company->id}";

    expect($mutex->acquire($lockName))->toBeTrue();

    $refused = false;

    try {
        try {
            $order->markAsComplete();
        } catch (Throwable) {
            $refused = true;
        }
    } finally {
        $mutex->release($lockName);
    }

    expect($refused)->toBeTrue()
        ->and(orderCompletedInDb($order->id))->toBeFalse()
        ->and($order->getErrors('customerId'))
        ->toBe(['Could not verify your company credit limit. Please try again.']);
});

it('still credit-checks a partially paid invoice order for its remaining balance', function () {
    // Remainder within the limit completes despite carrying a payment: this proves the check runs
    // on the remainder rather than skipping paid orders the way the account-status backstop does.
    $withinCompany = enforcementCompany(50.0);
    $withinOrder = invoiceOrderForNewMember($withinCompany, 60.0);
    recordCreditPurchase($withinOrder, 20.0);

    expect($withinOrder->getTotalPaid())->toBeGreaterThan(0)
        ->and($withinOrder->getOutstandingBalance())->toBe(40.0)
        ->and($withinOrder->markAsComplete())->toBeTrue()
        ->and(orderCompanyRowExists($withinOrder->id))->toBeTrue();

    // Same shape but the remainder still exceeds the limit, so the payment does not buy a bypass.
    $overCompany = enforcementCompany(50.0);
    $overOrder = invoiceOrderForNewMember($overCompany, 100.0);
    recordCreditPurchase($overOrder, 40.0);

    $refused = false;

    try {
        $overOrder->markAsComplete();
    } catch (Throwable) {
        $refused = true;
    }

    expect($overOrder->getTotalPaid())->toBeGreaterThan(0)
        ->and($refused)->toBeTrue()
        ->and(orderCompletedInDb($overOrder->id))->toBeFalse();
});
