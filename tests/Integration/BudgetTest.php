<?php

use craft\commerce\elements\Order;
use craft\commerce\Plugin as Commerce;
use craft\elements\User;
use totalwebcreations\b2bcommerce\elements\Company;
use totalwebcreations\b2bcommerce\enums\BudgetPeriod;
use totalwebcreations\b2bcommerce\enums\CompanyRole;
use totalwebcreations\b2bcommerce\Plugin;
use totalwebcreations\b2bcommerce\variables\B2bVariable;
use yii\base\InvalidArgumentException;

// createTestCompany/createTestUser/asSiteRequest live in helpers.php; creditTestInvoiceGateway(),
// creditTestManualGateway(), setOrderStatusHandle() in CreditBalanceTest.php;
// orderCompletedInDb(), refuseCompletionAsSiteRequest() in CreditEnforcementTest.php — all loaded
// globally by the suite.

const BUDGET_MESSAGE = 'This order exceeds your spending budget.';

/**
 * Message the credit gate refuses with, reused to prove budget/credit independence.
 */
const BUDGET_CREDIT_MESSAGE = "This order exceeds your company's credit limit.";

/**
 * Creates a tracked, approved company with a fresh member, returning both.
 *
 * @return array{0: User, 1: Company}
 */
function budgetMember(?float $creditLimit = null): array
{
    $company = createTestCompany(Company::STATUS_APPROVED, 'Budget Co');
    $company->allowInvoicePayment = true;
    $company->creditLimit = $creditLimit;

    if (!craftApp()->getElements()->saveElement($company)) {
        throw new RuntimeException('Could not save budget company: ' . implode(', ', $company->getFirstErrors()));
    }

    $user = createTestUser('budget_' . uniqid() . '@example.test');
    Plugin::getInstance()->companyMembers->addUserToCompany($user->id, $company->id, CompanyRole::Admin);

    return [$user, $company];
}

/**
 * Builds a tracked cart (incomplete order) priced at $price for the given user, optionally on a
 * gateway. The caller drives completion or feeds it to the pure gate decision.
 */
function budgetCart(User $user, float $price, ?int $gatewayId = null): Order
{
    $variant = createTestVariant('BUDCART-' . uniqid(), $price);

    $order = new Order();
    $order->number = md5(uniqid((string) mt_rand(), true));
    $order->setCustomer($user);

    if ($gatewayId !== null) {
        $order->gatewayId = $gatewayId;
    }

    $lineItem = Commerce::getInstance()->getLineItems()->resolveLineItem($order, $variant->id);
    $lineItem->qty = 1;
    $order->addLineItem($lineItem);

    if (!craftApp()->getElements()->saveElement($order)) {
        throw new RuntimeException('Could not save budget cart: ' . implode(', ', $order->getFirstErrors()));
    }

    trackElement($order);

    return $order;
}

/**
 * Completes a tracked order priced at $price for the given user. Completing in the console harness
 * skips enforcement but still links the company (Order::EVENT_AFTER_COMPLETE_ORDER), so the order
 * counts towards the member's spend exactly as a real checkout would.
 */
function budgetCompletedOrder(User $user, float $price, ?int $gatewayId = null): Order
{
    $order = budgetCart($user, $price, $gatewayId ?? creditTestManualGateway()->id);

    if (!$order->markAsComplete()) {
        throw new RuntimeException('Could not complete budget order.');
    }

    return $order;
}

/**
 * Backdates an order's dateOrdered straight in the table. getSpent reads dateOrdered only through
 * its SQL filter, so a raw update is enough and sidesteps a full re-save.
 */
function setOrderDateOrdered(Order $order, string $utcDatetime): void
{
    craftApp()->getDb()->createCommand()
        ->update('{{%commerce_orders}}', ['dateOrdered' => $utcDatetime], ['id' => $order->id])
        ->execute();
}

/**
 * Runs the budget completion backstop directly under a faked storefront request and reports whether
 * it refused (threw). On a pass it leaves the per-member lock held for the after-handler; callers
 * must release it (releaseBudgetLock) to clean up.
 */
function budgetEnforceAsSiteRequest(Order $order): bool
{
    $refused = false;

    asSiteRequest(function () use ($order, &$refused) {
        try {
            Plugin::getInstance()->budgetEnforcer->enforceBudget($order);
        } catch (Throwable) {
            $refused = true;
        }
    });

    return $refused;
}

$now = fn (): DateTime => new DateTime('now');

// --- service: getBudget / setBudget / removeBudget ---

it('returns null for a member with no budget', function () {
    [$user, $company] = budgetMember();

    expect(Plugin::getInstance()->budgets->getBudget($company->id, $user->id))->toBeNull();
});

it('sets and reads back a member budget', function () {
    [$user, $company] = budgetMember();

    Plugin::getInstance()->budgets->setBudget($company, $user->id, 250.0, BudgetPeriod::Monthly);
    $budget = Plugin::getInstance()->budgets->getBudget($company->id, $user->id);

    expect((float) $budget['amount'])->toBe(250.0)
        ->and($budget['period'])->toBe('monthly');
});

it('refuses to set a budget for a non-member', function () {
    $company = createTestCompany(Company::STATUS_APPROVED, 'Budget Co');
    $stranger = createTestUser('stranger_' . uniqid() . '@example.test');

    expect(fn () => Plugin::getInstance()->budgets->setBudget($company, $stranger->id, 100.0, BudgetPeriod::Monthly))
        ->toThrow(InvalidArgumentException::class);
});

it('removes a member budget', function () {
    [$user, $company] = budgetMember();
    Plugin::getInstance()->budgets->setBudget($company, $user->id, 100.0, BudgetPeriod::Monthly);

    Plugin::getInstance()->budgets->removeBudget($company, $user->id);

    expect(Plugin::getInstance()->budgets->getBudget($company->id, $user->id))->toBeNull();
});

// --- service: getSpent ---

it('sums only this member completed orders for this company', function () use ($now) {
    [$user, $company] = budgetMember();
    Plugin::getInstance()->budgets->setBudget($company, $user->id, 1000.0, BudgetPeriod::Monthly);

    budgetCompletedOrder($user, 30.0);
    budgetCompletedOrder($user, 20.0);

    expect(Plugin::getInstance()->budgets->getSpent($company->id, $user->id, $now()))->toBe(50.0);
});

it('excludes an incomplete cart from spend', function () use ($now) {
    [$user, $company] = budgetMember();
    Plugin::getInstance()->budgets->setBudget($company, $user->id, 1000.0, BudgetPeriod::Monthly);

    budgetCompletedOrder($user, 30.0);
    budgetCart($user, 500.0); // never completed

    expect(Plugin::getInstance()->budgets->getSpent($company->id, $user->id, $now()))->toBe(30.0);
});

it('does not count another member spend towards this member', function () use ($now) {
    [$user, $company] = budgetMember();
    Plugin::getInstance()->budgets->setBudget($company, $user->id, 1000.0, BudgetPeriod::Monthly);

    $other = createTestUser('budgetother_' . uniqid() . '@example.test');
    Plugin::getInstance()->companyMembers->addUserToCompany($other->id, $company->id, CompanyRole::Purchaser);
    budgetCompletedOrder($other, 90.0);

    budgetCompletedOrder($user, 30.0);

    expect(Plugin::getInstance()->budgets->getSpent($company->id, $user->id, $now()))->toBe(30.0);
});

it('excludes a settled (cancelled) order from spend', function () use ($now) {
    [$user, $company] = budgetMember();
    Plugin::getInstance()->budgets->setBudget($company, $user->id, 1000.0, BudgetPeriod::Monthly);

    $active = budgetCompletedOrder($user, 30.0);
    $cancelled = budgetCompletedOrder($user, 100.0);
    setOrderStatusHandle($cancelled, 'cancelled');

    expect(Plugin::getInstance()->budgets->getSpent($company->id, $user->id, $now()))->toBe($active->getTotalPrice());
});

it('drops an order from a previous month once the monthly period resets', function () use ($now) {
    [$user, $company] = budgetMember();
    Plugin::getInstance()->budgets->setBudget($company, $user->id, 100.0, BudgetPeriod::Monthly);

    $old = budgetCompletedOrder($user, 40.0);
    setOrderDateOrdered($old, '2020-01-15 12:00:00');

    // The old order fell in a previous month, so this period's spend is zero and the full budget is
    // available again.
    expect(Plugin::getInstance()->budgets->getSpent($company->id, $user->id, $now()))->toBe(0.0)
        ->and(Plugin::getInstance()->budgets->canAfford($company->id, $user->id, 100.0, $now()))->toBeTrue();
});

it('counts an old order under a None (all-time) budget', function () use ($now) {
    [$user, $company] = budgetMember();
    Plugin::getInstance()->budgets->setBudget($company, $user->id, 100.0, BudgetPeriod::None);

    $old = budgetCompletedOrder($user, 40.0);
    setOrderDateOrdered($old, '2020-01-15 12:00:00');

    // None never resets: the old order still counts against the lifetime cap.
    expect(Plugin::getInstance()->budgets->getSpent($company->id, $user->id, $now()))->toBe(40.0);
});

// --- service: canAfford ---

it('treats a member with no budget as unlimited', function () use ($now) {
    [$user, $company] = budgetMember();
    budgetCompletedOrder($user, 5000.0);

    expect(Plugin::getInstance()->budgets->canAfford($company->id, $user->id, 5000.0, $now()))->toBeTrue();
});

it('allows a charge that lands exactly on the budget', function () use ($now) {
    [$user, $company] = budgetMember();
    Plugin::getInstance()->budgets->setBudget($company, $user->id, 50.0, BudgetPeriod::Monthly);
    budgetCompletedOrder($user, 40.0);

    expect(Plugin::getInstance()->budgets->canAfford($company->id, $user->id, 10.0, $now()))->toBeTrue();
});

it('refuses a charge that pushes past the budget', function () use ($now) {
    [$user, $company] = budgetMember();
    Plugin::getInstance()->budgets->setBudget($company, $user->id, 50.0, BudgetPeriod::Monthly);
    budgetCompletedOrder($user, 40.0);

    expect(Plugin::getInstance()->budgets->canAfford($company->id, $user->id, 11.0, $now()))->toBeFalse();
});

// --- payment-time gate ---

it('refuses payment for a member over their spending budget', function () {
    [$user, $company] = budgetMember();
    Plugin::getInstance()->budgets->setBudget($company, $user->id, 50.0, BudgetPeriod::Monthly);
    budgetCompletedOrder($user, 40.0);

    $cart = budgetCart($user, 20.0);

    expect(Plugin::getInstance()->paymentGate->paymentRefusalReason($cart))->toBe(BUDGET_MESSAGE);
});

it('allows payment for a member within their spending budget', function () {
    [$user, $company] = budgetMember();
    Plugin::getInstance()->budgets->setBudget($company, $user->id, 50.0, BudgetPeriod::Monthly);
    budgetCompletedOrder($user, 40.0);

    $cart = budgetCart($user, 5.0);

    expect(Plugin::getInstance()->paymentGate->paymentRefusalReason($cart))->toBeNull();
});

it('never refuses payment for a member with no budget', function () {
    [$user] = budgetMember();
    $cart = budgetCart($user, 100000.0);

    expect(Plugin::getInstance()->paymentGate->paymentRefusalReason($cart))->toBeNull();
});

// --- completion backstop ---

it('refuses completion for a member over their spending budget', function () {
    [$user, $company] = budgetMember();
    Plugin::getInstance()->budgets->setBudget($company, $user->id, 50.0, BudgetPeriod::Monthly);
    budgetCompletedOrder($user, 40.0);

    $cart = budgetCart($user, 20.0);

    expect(refuseCompletionAsSiteRequest($cart))->toBeTrue()
        ->and(orderCompletedInDb($cart->id))->toBeFalse()
        ->and($cart->getErrors('customerId'))->toBe([BUDGET_MESSAGE]);
});

it('lets an in-budget completion through and hands the lock to the after-handler', function () {
    [$user, $company] = budgetMember();
    Plugin::getInstance()->budgets->setBudget($company, $user->id, 500.0, BudgetPeriod::Monthly);
    budgetCompletedOrder($user, 40.0);

    $cart = budgetCart($user, 20.0);

    $mutex = Craft::$app->getMutex();
    $lockName = "b2b-budget-{$company->id}-{$user->id}";

    expect(budgetEnforceAsSiteRequest($cart))->toBeFalse()
        ->and($mutex->isAcquired($lockName))->toBeTrue();

    Plugin::getInstance()->budgetEnforcer->releaseBudgetLock($cart);
    expect($mutex->isAcquired($lockName))->toBeFalse();
});

it('does not enforce a budget on a member who has none', function () {
    [$user] = budgetMember();
    $cart = budgetCart($user, 100000.0);

    expect(budgetEnforceAsSiteRequest($cart))->toBeFalse();
});

// --- independence of the budget and credit caps ---

it('refuses on credit even when the member is within budget', function () {
    // Company credit limit 50, member budget a roomy 500. A prior invoice order (40) drives the
    // company's outstanding balance, and a fresh 20 invoice cart stays within budget (60 <= 500) but
    // pushes the company over its credit limit (60 > 50), so credit refuses.
    [$user, $company] = budgetMember(50.0);
    Plugin::getInstance()->budgets->setBudget($company, $user->id, 500.0, BudgetPeriod::Monthly);
    budgetCompletedOrder($user, 40.0, creditTestInvoiceGateway()->id);

    $cart = budgetCart($user, 20.0, creditTestInvoiceGateway()->id);

    expect(Plugin::getInstance()->paymentGate->paymentRefusalReason($cart))->toBe(BUDGET_CREDIT_MESSAGE);
});

it('refuses on budget even when the company is within its credit limit', function () {
    // Company credit limit a roomy 5000, member budget 50. A prior 40 order plus a fresh 20 cart on a
    // non-invoice gateway pushes the member over budget (60 > 50) while the company credit is never
    // in play, so the budget gate refuses.
    [$user, $company] = budgetMember(5000.0);
    Plugin::getInstance()->budgets->setBudget($company, $user->id, 50.0, BudgetPeriod::Monthly);
    budgetCompletedOrder($user, 40.0);

    $cart = budgetCart($user, 20.0, creditTestManualGateway()->id);

    expect(Plugin::getInstance()->paymentGate->paymentRefusalReason($cart))->toBe(BUDGET_MESSAGE);
});

it('allows an invoice order under both the member budget and the company credit limit', function () {
    // Company credit limit 500, member budget 500. A prior 40 invoice order plus a fresh 20 invoice
    // cart stay under the budget (60 <= 500) AND under the credit limit (60 <= 500), so both gates
    // pass and payment is not refused — the both-pass corner of the budget/credit matrix.
    [$user, $company] = budgetMember(500.0);
    Plugin::getInstance()->budgets->setBudget($company, $user->id, 500.0, BudgetPeriod::Monthly);
    budgetCompletedOrder($user, 40.0, creditTestInvoiceGateway()->id);

    $cart = budgetCart($user, 20.0, creditTestInvoiceGateway()->id);

    expect(Plugin::getInstance()->paymentGate->paymentRefusalReason($cart))->toBeNull();
});

// --- storefront variable: getMemberBudget ---

it('exposes the member budget shape to the current user', function () {
    [$user, $company] = budgetMember();
    Plugin::getInstance()->budgets->setBudget($company, $user->id, 100.0, BudgetPeriod::Monthly);
    budgetCompletedOrder($user, 30.0);

    $variable = new B2bVariable();

    asSummaryIdentity($user, function () use ($variable) {
        $budget = $variable->getMemberBudget();

        expect($budget)->not->toBeNull()
            ->and($budget['amount'])->toBe(100.0)
            ->and($budget['period'])->toBe('monthly')
            ->and($budget['spent'])->toBe(30.0)
            ->and($budget['remaining'])->toBe(70.0);
    });
});

it('reports no member budget for a member who has none', function () {
    [$user] = budgetMember();
    $variable = new B2bVariable();

    asSummaryIdentity($user, function () use ($variable) {
        expect($variable->getMemberBudget())->toBeNull();
    });
});

it('reports no member budget for a visitor without a company', function () {
    $user = createTestUser('budget_nocompany_' . uniqid() . '@example.test');
    $variable = new B2bVariable();

    asSummaryIdentity($user, function () use ($variable) {
        expect($variable->getMemberBudget())->toBeNull();
    });
});
