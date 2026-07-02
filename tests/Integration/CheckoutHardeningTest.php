<?php

use craft\commerce\elements\Order;
use craft\db\Query;
use craft\elements\User;
use totalwebcreations\b2bcommerce\elements\Company;
use totalwebcreations\b2bcommerce\enums\CompanyRole;
use totalwebcreations\b2bcommerce\Plugin;
use yii\base\InvalidArgumentException;

/*
|--------------------------------------------------------------------------
| Checkout hardening sweep — server-side company-status enforcement
|--------------------------------------------------------------------------
|
| Spec requirement: every checkout path validates company status server-side.
| The plugin enforces this through two shared primitives rather than one
| `canOrder()` method:
|   - PriceVisibility::canPurchase()  (src/services/PriceVisibility.php:27) —
|     the approved/blocked/pending/no-company predicate, active only when the
|     `hidePricesForGuests` setting is on.
|   - CompanyMembers::getCompanyForUser() (…/CompanyMembers.php:131) — returns
|     null once a membership row is gone, which is what makes a mid-flow
|     membership removal fail closed everywhere.
|
| The add-to-cart family (add-to-cart, quick order, order lists, reorder) all
| funnel through ONE choke point: the EVENT_BEFORE_ADD_LINE_ITEM handler at
| src/Plugin.php:207, which calls canPurchase(currentUser). Order completion is
| guarded by four EVENT_BEFORE_COMPLETE_ORDER handlers (src/Plugin.php:319-377):
| quote-accepted veto, approval backstop, account-status backstop
| (OrderCompanyLink::enforcePurchasePolicy) and the credit/invoice fail-closed
| backstop (CreditEnforcer::enforceCreditLimit).
|
| COVERAGE MATRIX  (✅ direct test · guard decision verified via the shared
| predicate/choke point that this flow funnels through · + = gap filled here)
|
| flow ↓ / condition →   | blocked company                | pending company                | membership removed mid-flow
| -----------------------|--------------------------------|--------------------------------|-------------------------------
| add-to-cart            | PriceVisibilityTest             | PriceVisibilityTest             | PriceVisibilityTest
|                        |  'hides prices … blocked' (:55) |  'hides prices … pending' (:45) |  'hides prices … without a company' (:37)
|                        |  + QuickOrderTest 'adds nothing … guard blocks a line' (:87) surfaces the choke-point message.
| quick order           | ↑ same choke point (Plugin.php:207); guard decision = canPurchase, covered by PriceVisibilityTest;
| order lists add        |   message plumbing covered by QuickOrderTest:87. Real EVENT_BEFORE_ADD_LINE_ITEM add is a web-only
| reorder               |   Commerce mutation (getIsSecureConnection) not drivable in the console harness — see QuickOrderTest:87 note.
| quote request          | + THIS FILE 'refuses a quote request from a member of a blocked company'
|                        |                                 | QuoteRequestTest 'refuses … pending company' (:100) | QuoteRequestTest 'refuses … user without a company' (:92)
| quote accept           | by design: acceptByToken re-checks membership only (authorizeTokenAccess, Quotes.php:606), NOT status.
|                        | A blocked/pending company is caught by the completion backstop below (defense-in-depth), covered by
|                        | OrderCompanyTest:75 + THIS FILE completion tests. Wrong-company token: QuoteAcceptanceTest:56.
| approval submit        | + THIS FILE 'refuses to submit … from a member of a blocked company'
|                        |                                 | NeedsApprovalTest 'never gates … company is not approved' (:100, STATUS_PENDING) | NeedsApprovalTest 'never gates a user with no company' (:93)
| order completion       | OrderCompanyTest 'refuses … blocked customer on a site request' (:75); paid-exempt (:109); abort coupling (:145)
| (non-invoice)          | + THIS FILE 'refuses … pending company'        | + THIS FILE membership-removed (hidePrices on) refused; (hidePrices off) completes as a normal customer
| order completion       | InvoiceGateway unavailable: pending (InvoiceGatewayTest:79), guest (:72); approved+flag available (:95).
| (invoice)              | Fail-closed backstop on membership removal mid-flow: CreditEnforcementTest 'refuses … no company' (:222, I4);
|                        | pay-on-account toggled off (:239); over limit (:123,:150); credit lock unacquirable (:195).
|
| Gaps found and filled in this file: quote request / blocked; approval submit /
| blocked; order completion / pending (non-invoice); and the membership-removed
| mid-flow non-invoice completion pair (backstop fires under hidePricesForGuests,
| stays dormant without it — a company-less customer is then simply a normal
| customer, which is the correct non-invoice behaviour).
*/

/**
 * Builds a tracked, saved cart owned by the given customer (or a guest when null).
 * The customer is what the completion backstops read.
 */
function hardeningCartFor(?User $customer): Order
{
    $order = new Order();
    $order->number = md5(uniqid((string) mt_rand(), true));

    if ($customer !== null) {
        $order->setCustomer($customer);
    }

    if (!craftApp()->getElements()->saveElement($order)) {
        throw new RuntimeException('Could not save hardening cart: ' . implode(', ', $order->getFirstErrors()));
    }

    trackElement($order);

    return $order;
}

/**
 * Runs the callback with `hidePricesForGuests` set to the given value, restoring it afterwards.
 */
function withHidePrices(bool $enabled, callable $callback): void
{
    $plugin = Plugin::getInstance();
    Craft::$app->getPlugins()->savePluginSettings($plugin, ['hidePricesForGuests' => $enabled]);

    try {
        $callback();
    } finally {
        Craft::$app->getPlugins()->savePluginSettings($plugin, ['hidePricesForGuests' => false]);
    }
}

/**
 * Attempts to complete the order inside a faked site request, reporting whether it was refused.
 */
function attemptCompletionAsSiteRequest(Order $order): bool
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
 * Reports whether the order is persisted as completed.
 */
function hardeningCompletedInDb(int $orderId): bool
{
    return (new Query())
        ->from('{{%commerce_orders}}')
        ->where(['id' => $orderId, 'isCompleted' => true])
        ->exists();
}

/*
|--------------------------------------------------------------------------
| Quote request — blocked company (gap fill)
|--------------------------------------------------------------------------
*/

it('refuses a quote request from a member of a blocked company', function () {
    [$user] = quoteMember(Company::STATUS_BLOCKED);
    $cart = quoteCartWithItem();

    expect(fn () => Plugin::getInstance()->quotes->requestQuote($cart, $user, null))
        ->toThrow(InvalidArgumentException::class, 'Only approved company members can request quotes.');
});

/*
|--------------------------------------------------------------------------
| Approval submit — blocked company (gap fill)
|--------------------------------------------------------------------------
*/

it('refuses to submit an order for approval from a member of a blocked company', function () {
    // needsApproval() short-circuits to false for any non-approved company, so submitForApproval
    // refuses a blocked member — a blocked company can never reach the approval queue. The cart
    // carries a line item so the refusal comes from the status guard, not the empty-cart check.
    [$user] = quoteMember(Company::STATUS_BLOCKED);
    $cart = hardeningCartFor($user);
    $variant = createTestVariant('HARD-APR-' . substr(uniqid(), -6), 500.0);
    Plugin::getInstance()->quickOrder->addResolvedPurchasable($cart, $variant->id, 1, $variant->sku);

    expect(fn () => Plugin::getInstance()->approvals->submitForApproval($cart, $user))
        ->toThrow(InvalidArgumentException::class, 'This order does not require approval.');
});

/*
|--------------------------------------------------------------------------
| Order completion — pending company (gap fill; sibling of the blocked case)
|--------------------------------------------------------------------------
*/

it('refuses to complete a non-invoice order for a pending company on a site request', function () {
    $user = createTestUser('pendingcomplete_' . uniqid() . '@example.test');
    $company = createTestCompany(Company::STATUS_PENDING);
    Plugin::getInstance()->companyMembers->addUserToCompany($user->id, $company->id, CompanyRole::Admin);

    $order = hardeningCartFor($user);
    $refused = false;

    withHidePrices(true, function () use ($order, &$refused) {
        $refused = attemptCompletionAsSiteRequest($order);
    });

    expect($refused)->toBeTrue()
        ->and(hardeningCompletedInDb($order->id))->toBeFalse()
        ->and($order->getErrors('customerId'))->not->toBeEmpty();
});

/*
|--------------------------------------------------------------------------
| Membership removed mid-flow — non-invoice completion
|--------------------------------------------------------------------------
| The brief's explicit trace: the account-status backstop only fires while
| hidePricesForGuests is on. Without it, a customer who lost their company is
| just a normal customer and non-invoice completion is allowed — which is
| correct (the invoice path has its own fail-closed backstop, covered by
| CreditEnforcementTest:222).
*/

it('refuses a non-invoice completion when the customer lost their company and prices are hidden', function () {
    $user = createTestUser('lostcompany_hidden_' . uniqid() . '@example.test');
    $company = createTestCompany(Company::STATUS_APPROVED);
    Plugin::getInstance()->companyMembers->addUserToCompany($user->id, $company->id, CompanyRole::Admin);

    $order = hardeningCartFor($user);

    // Membership removed after the cart was built but before completion.
    Plugin::getInstance()->companyMembers->removeUserFromCompany($user->id, $company->id);

    $refused = false;

    withHidePrices(true, function () use ($order, &$refused) {
        $refused = attemptCompletionAsSiteRequest($order);
    });

    expect($refused)->toBeTrue()
        ->and(hardeningCompletedInDb($order->id))->toBeFalse()
        ->and($order->getErrors('customerId'))->not->toBeEmpty();
});

it('leaves a company-less customer non-invoice completion to proceed when prices are not hidden', function () {
    $user = createTestUser('lostcompany_open_' . uniqid() . '@example.test');
    $company = createTestCompany(Company::STATUS_APPROVED);
    Plugin::getInstance()->companyMembers->addUserToCompany($user->id, $company->id, CompanyRole::Admin);

    $order = hardeningCartFor($user);

    Plugin::getInstance()->companyMembers->removeUserFromCompany($user->id, $company->id);

    // With hidePricesForGuests off the account-status backstop is dormant: it must NOT refuse a
    // company-less customer on a site request. The backstop is exercised directly here because
    // driving a full successful completion through a faked site request hits unrelated
    // console/web-response friction in the harness (mirrors OrderCompanyTest's paid-exempt case).
    withHidePrices(false, function () use ($order) {
        asSiteRequest(function () use ($order) {
            Plugin::getInstance()->orderCompanyLink->enforcePurchasePolicy($order);
        });
    });

    expect($order->getErrors('customerId'))->toBeEmpty();

    // And a company-less customer completing is simply a normal customer: completion succeeds and
    // no company receivable is linked (identical to the guest case in OrderCompanyTest).
    expect($order->markAsComplete())->toBeTrue()
        ->and(hardeningCompletedInDb($order->id))->toBeTrue()
        ->and((new Query())->from('{{%b2b_order_company}}')->where(['orderId' => $order->id])->exists())->toBeFalse();
});
