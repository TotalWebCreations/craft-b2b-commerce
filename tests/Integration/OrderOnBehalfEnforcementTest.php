<?php

use craft\commerce\elements\Order;
use craft\elements\User;
use totalwebcreations\b2bcommerce\elements\Company;
use totalwebcreations\b2bcommerce\enums\BudgetPeriod;
use totalwebcreations\b2bcommerce\enums\CompanyRole;
use totalwebcreations\b2bcommerce\Plugin;

/**
 * Builds a completed order for the member carrying a single priced line item, so the budget
 * enforcer has spend to measure. Runs the completion backstop as a site request.
 */
function behalfOrderFor(User $member, float $price): Order
{
    $order = new Order();
    $order->number = md5(uniqid((string) mt_rand(), true));

    if (!craftApp()->getElements()->saveElement($order)) {
        throw new RuntimeException('Could not save order: ' . implode(', ', $order->getFirstErrors()));
    }

    trackElement($order);
    $order->setCustomer($member);

    $variant = createTestVariant('BEHALF-' . substr(uniqid(), -6), $price);
    Plugin::getInstance()->quickOrder->addResolvedPurchasable($order, $variant->id, 1, $variant->sku);
    craftApp()->getElements()->saveElement($order);

    return $order;
}

it('enforces the MEMBER budget on an on-behalf order, not the rep', function () {
    $company = createTestCompany(Company::STATUS_APPROVED, 'Elevation Co');
    $member = createTestUser('emember_' . uniqid() . '@example.test');
    Plugin::getInstance()->companyMembers->addUserToCompany($member->id, $company->id, CompanyRole::Purchaser);

    // The member has a tiny budget; the rep has none. The member's cap must govern.
    Plugin::getInstance()->budgets->setBudget($company, $member->id, 1.0, BudgetPeriod::Monthly);

    $order = behalfOrderFor($member, 500.0);

    $refused = false;

    // The impersonated identity IS the member, exactly as after actAs().
    asIdentity($member, function () use ($order, &$refused) {
        asSiteRequest(function () use ($order, &$refused) {
            try {
                Plugin::getInstance()->budgetEnforcer->enforceBudget($order);
            } catch (\Throwable) {
                $refused = true;
            } finally {
                Plugin::getInstance()->budgetEnforcer->releaseBudgetLock($order);
            }
        });
    });

    expect($refused)->toBeTrue();
});

it('does not stamp a non-rep admin who impersonated via the native flow', function () {
    $company = createTestCompany(Company::STATUS_APPROVED, 'NoStamp Co');
    $member = createTestUser('nsmember_' . uniqid() . '@example.test');
    Plugin::getInstance()->companyMembers->addUserToCompany($member->id, $company->id, CompanyRole::Purchaser);

    // An admin with Craft's impersonate permission but NO b2b_rep_companies assignment.
    $admin = createTestUser('nsadmin_' . uniqid() . '@example.test');
    $admin->admin = true;
    craftApp()->getElements()->saveElement($admin);
    craftApp()->getUserPermissions()->saveUserPermissions($admin->id, ['impersonateUsers']);
    $admin = craftApp()->getUsers()->getUserById($admin->id);

    $userSession = impersonationTestUser();

    try {
        $userSession->setImpersonatorId($admin->id);
        // Even though getImpersonatorId() resolves (admin has impersonateUsers), canActFor is false.
        expect(Plugin::getInstance()->salesReps->resolveActingRepId($company))->toBeNull();
    } finally {
        $userSession->setImpersonatorId(null);
    }
});
