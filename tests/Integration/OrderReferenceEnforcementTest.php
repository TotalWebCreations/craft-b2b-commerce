<?php

use craft\commerce\elements\Order;
use craft\db\Query;
use totalwebcreations\b2bcommerce\enums\CompanyRole;
use totalwebcreations\b2bcommerce\Plugin;

function completedInDb(int $orderId): bool
{
    return (new Query())
        ->from('{{%commerce_orders}}')
        ->where(['id' => $orderId, 'isCompleted' => true])
        ->exists();
}

/**
 * Runs the PO-required backstop directly under a faked storefront request and reports whether it
 * refused (threw). Mirrors CreditEnforcementTest's enforceAsSiteRequest(): a would-be SUCCESSFUL
 * completion cannot be driven through a full markAsComplete() under asSiteRequest() in this harness
 * -- Commerce's reference-format rendering (`{{number[:7]}}`) reaches craft\helpers\Cp::requestedSite(),
 * which calls Craft::$app->getRequest()->getQueryParam() once getIsConsoleRequest() is faked false,
 * and the console Request used by this test harness has no such method. So the "does not block"
 * branch is exercised by calling the enforcement method directly (proving it doesn't throw), and the
 * actual completion + PO carry-through is then driven in the harness's real (console) context, where
 * the backstop is a no-op by design (console/CP completions are the merchant override) but the save
 * itself and the persisted b2bPoNumber are genuinely exercised.
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
    $refused = false;

    asSiteRequest(function () use ($order, &$refused) {
        try {
            $order->markAsComplete();
        } catch (Throwable) {
            $refused = true;
        }
    });

    expect($refused)->toBeTrue()
        ->and(completedInDb($order->id))->toBeFalse()
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
