<?php

use craft\commerce\elements\Order;
use totalwebcreations\b2bcommerce\elements\Company;
use totalwebcreations\b2bcommerce\enums\CompanyRole;
use totalwebcreations\b2bcommerce\Plugin;

/**
 * Creates a tracked member of a company with the given role, approval threshold and status.
 *
 * @return array{0: \craft\elements\User, 1: Company}
 */
function approvalMember(
    CompanyRole $role,
    ?float $threshold,
    string $status = Company::STATUS_APPROVED,
): array {
    $company = createTestCompany($status, 'Approval Co');
    $company->approvalThreshold = $threshold;

    if (!craftApp()->getElements()->saveElement($company)) {
        throw new RuntimeException('Could not save approval company: ' . implode(', ', $company->getFirstErrors()));
    }

    $user = createTestUser('approval_' . uniqid() . '@example.test');
    Plugin::getInstance()->companyMembers->addUserToCompany($user->id, $company->id, $role);

    return [$user, $company];
}

/**
 * Creates a tracked order whose total price equals the given amount (a single line item
 * priced at $total, qty 1, no tax or shipping in the test environment).
 */
function approvalOrder(float $total): Order
{
    $order = new Order();
    $order->number = md5(uniqid((string) mt_rand(), true));

    if (!craftApp()->getElements()->saveElement($order)) {
        throw new RuntimeException('Could not save approval order: ' . implode(', ', $order->getFirstErrors()));
    }

    trackElement($order);

    $variant = createTestVariant('APPR-' . substr(uniqid(), -6), $total);
    Plugin::getInstance()->quickOrder->addResolvedPurchasable($order, $variant->id, 1, $variant->sku);
    craftApp()->getElements()->saveElement($order);

    return $order;
}

it('gates a purchaser strictly above the threshold', function (float $threshold, float $total, bool $expected) {
    [$user] = approvalMember(CompanyRole::Purchaser, $threshold);
    $order = approvalOrder($total);

    expect($order->getTotalPrice())->toBe($total)
        ->and(Plugin::getInstance()->approvals->needsApproval($order, $user))->toBe($expected);
})->with([
    'below threshold' => [500.0, 400.0, false],
    'exactly at threshold is placed directly' => [500.0, 500.0, false],
    'above threshold' => [500.0, 600.0, true],
]);

it('gates every purchaser order when the threshold is zero', function (float $total) {
    [$user] = approvalMember(CompanyRole::Purchaser, 0.0);
    $order = approvalOrder($total);

    expect(Plugin::getInstance()->approvals->needsApproval($order, $user))->toBeTrue();
})->with([400.0, 500.0, 600.0]);

it('never gates a purchaser when the threshold is null', function (float $total) {
    [$user] = approvalMember(CompanyRole::Purchaser, null);
    $order = approvalOrder($total);

    expect(Plugin::getInstance()->approvals->needsApproval($order, $user))->toBeFalse();
})->with([400.0, 500.0, 600.0]);

it('never gates approvers or admins regardless of threshold or amount', function (CompanyRole $role, ?float $threshold, float $total) {
    [$user] = approvalMember($role, $threshold);
    $order = approvalOrder($total);

    expect(Plugin::getInstance()->approvals->needsApproval($order, $user))->toBeFalse();
})->with([
    'approver, null threshold' => [CompanyRole::Approver, null, 600.0],
    'approver, zero threshold' => [CompanyRole::Approver, 0.0, 600.0],
    'approver, over threshold' => [CompanyRole::Approver, 500.0, 600.0],
    'admin, null threshold' => [CompanyRole::Admin, null, 600.0],
    'admin, zero threshold' => [CompanyRole::Admin, 0.0, 600.0],
    'admin, over threshold' => [CompanyRole::Admin, 500.0, 600.0],
]);

it('never gates a user with no company', function () {
    $user = createTestUser('nocompany_' . uniqid() . '@example.test');
    $order = approvalOrder(600.0);

    expect(Plugin::getInstance()->approvals->needsApproval($order, $user))->toBeFalse();
});

it('never gates a purchaser whose company is not approved', function () {
    [$user] = approvalMember(CompanyRole::Purchaser, 0.0, Company::STATUS_PENDING);
    $order = approvalOrder(600.0);

    expect(Plugin::getInstance()->approvals->needsApproval($order, $user))->toBeFalse();
});

it('is byte-for-byte the legacy threshold gate when the company has no tiers', function (float $threshold, float $total, bool $expected) {
    [$user] = approvalMember(CompanyRole::Purchaser, $threshold);
    $order = approvalOrder($total);

    expect(Plugin::getInstance()->approvals->needsApproval($order, $user))->toBe($expected);
})->with([
    'below threshold, no tiers' => [500.0, 400.0, false],
    'exactly at threshold, no tiers' => [500.0, 500.0, false],
    'above threshold, no tiers' => [500.0, 600.0, true],
]);

it('arms the gate on the lowest tier band even when the company sets no single threshold', function () {
    // No approvalThreshold (null) — legacy gate is off — but a tier at 1000 arms it at/above 1000.
    [$user, $company] = approvalMember(CompanyRole::Purchaser, null);
    Plugin::getInstance()->approvalTiers->setTier($company->id, 1, 1000.0, 'approver', false);

    expect(Plugin::getInstance()->approvals->needsApproval(approvalOrder(999.0), $user))->toBeFalse()
        ->and(Plugin::getInstance()->approvals->needsApproval(approvalOrder(1000.0), $user))->toBeTrue()
        ->and(Plugin::getInstance()->approvals->needsApproval(approvalOrder(1500.0), $user))->toBeTrue();
});

it('still never gates an admin, even with tiers configured', function () {
    [$admin, $company] = approvalMember(CompanyRole::Admin, null);
    Plugin::getInstance()->approvalTiers->setTier($company->id, 1, 100.0, 'approver', false);

    expect(Plugin::getInstance()->approvals->needsApproval(approvalOrder(5000.0), $admin))->toBeFalse();
});
