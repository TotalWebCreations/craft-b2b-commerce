<?php

use craft\commerce\elements\Order;
use craft\db\Query;
use craft\helpers\Cp;
use totalwebcreations\b2bcommerce\enums\CompanyRole;
use totalwebcreations\b2bcommerce\Plugin;
use yii\base\Exception;

function completedInDb(int $orderId): bool
{
    return (new Query())
        ->from('{{%commerce_orders}}')
        ->where(['id' => $orderId, 'isCompleted' => true])
        ->exists();
}

/**
 * Warms `Cp::requestedSite()`'s internal static cache while this is still a genuine console
 * request. `markAsComplete()` renders the order's reference format (`{{number[:7]}}`) BEFORE
 * `EVENT_BEFORE_COMPLETE_ORDER` fires, and that render resolves the `requestedSite` Twig global,
 * which calls `Cp::requestedSite()`. That method memoizes into a private static the first time it
 * runs; called now, with `getIsConsoleRequest()` still true, it takes the branch that skips
 * `getQueryParam()` entirely and caches the current site. Called later under `asSiteRequest()`
 * (which only flips the `getIsConsoleRequest()` flag on the same console Request instance, not the
 * request class), it would otherwise call `getQueryParam()` on a console Request -- a method it
 * doesn't have -- and throw a `TypeError` unrelated to the guard under test. Priming here makes a
 * real `markAsComplete()` deterministic in isolation instead of depending on some earlier test in
 * the full suite having warmed the same static cache first.
 */
function warmRequestedSiteCache(): void
{
    Cp::requestedSite();
}

/**
 * Runs the PO-required backstop directly under a faked storefront request and reports whether it
 * refused (threw). Mirrors CreditEnforcementTest's enforceAsSiteRequest(). Used for the non-throwing
 * branches, where only proof that the guard stands down is needed; the throwing branch below drives
 * a real markAsComplete() instead so it is proof of the actual completion being refused, not just of
 * the service method in isolation.
 */
function enforcePoAsSiteRequest(Order $order): bool
{
    $refused = false;

    asSiteRequest(function () use ($order, &$refused) {
        try {
            Plugin::getInstance()->orderReferences->enforceRequiredPoNumber($order);
        } catch (Throwable) {
            $refused = true;
        }
    });

    return $refused;
}

it('refuses completion when the company requires a PO and none is set', function () {
    $user = createTestUser('poreq_' . uniqid() . '@example.test');
    $company = createTestCompany('approved');
    $company->requirePoNumber = true;
    craftApp()->getElements()->saveElement($company);
    Plugin::getInstance()->companyMembers->addUserToCompany($user->id, $company->id, CompanyRole::Admin);

    $order = createTestOrder($user);

    warmRequestedSiteCache();

    asSiteRequest(function () use ($order) {
        expect(fn () => $order->markAsComplete())
            ->toThrow(Exception::class, 'A purchase order number is required for this order.');
    });

    expect(completedInDb($order->id))->toBeFalse()
        ->and($order->getErrors('customerId'))
        ->toBe(['A purchase order number is required for this order.']);
});

it('completes once a PO number is set', function () {
    $user = createTestUser('pook_' . uniqid() . '@example.test');
    $company = createTestCompany('approved');
    $company->requirePoNumber = true;
    craftApp()->getElements()->saveElement($company);
    Plugin::getInstance()->companyMembers->addUserToCompany($user->id, $company->id, CompanyRole::Admin);

    $order = createTestOrder($user);
    Plugin::getInstance()->orderReferences->setPoNumber($order, 'PO-5005');

    // The storefront-scoped backstop stands down once a PO is present.
    expect(enforcePoAsSiteRequest($order))->toBeFalse();

    expect($order->markAsComplete())->toBeTrue()
        ->and(completedInDb($order->id))->toBeTrue()
        ->and($order->b2bPoNumber)->toBe('PO-5005');
});

it('does not require a PO for a company without the toggle', function () {
    $user = createTestUser('nopo_' . uniqid() . '@example.test');
    $company = createTestCompany('approved');
    Plugin::getInstance()->companyMembers->addUserToCompany($user->id, $company->id, CompanyRole::Admin);

    $order = createTestOrder($user);

    // No PO required, so the storefront-scoped backstop stands down.
    expect(enforcePoAsSiteRequest($order))->toBeFalse();

    expect($order->markAsComplete())->toBeTrue()
        ->and(completedInDb($order->id))->toBeTrue();
});

it('does not require a PO for an order with no company', function () {
    $order = createTestOrder(null);

    // A guest order has no customer at all, so the guard fails open just like the sibling guards.
    expect(enforcePoAsSiteRequest($order))->toBeFalse();

    expect($order->markAsComplete())->toBeTrue()
        ->and(completedInDb($order->id))->toBeTrue();
});
