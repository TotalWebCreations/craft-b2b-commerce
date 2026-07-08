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

it('arms the tier gate money-safely at the exact boundary, in lockstep with requiredLevels', function () {
    // The tier check must use the same money-safe comparison (bccomp, scale 4) that
    // ApprovalTiers::requiredLevels() uses, so a total that lands exactly on the lowest tier's
    // minAmount is never missed by a raw float boundary compare, and the two methods can never
    // disagree about whether a tier applies.
    [$user, $company] = approvalMember(CompanyRole::Purchaser, null);
    Plugin::getInstance()->approvalTiers->setTier($company->id, 1, 1000.0, 'approver', false);

    $order = approvalOrder(1000.0);

    expect(Plugin::getInstance()->approvals->needsApproval($order, $user))->toBeTrue()
        ->and(Plugin::getInstance()->approvalTiers->requiredLevels($company->id, $order->getTotalPrice()))->not->toBeEmpty();

    // Craft Commerce rounds a purchasable's price to currency precision (2dp) before the order
    // total is ever computed, so a sub-cent float-drift total (e.g. 999.9999999997) cannot occur
    // in a real order; 999.99 is the closest genuine just-under-boundary total available here, and
    // it must stay consistent with requiredLevels() the same way the exact boundary does above.
    $belowOrder = approvalOrder(999.99);

    expect(Plugin::getInstance()->approvals->needsApproval($belowOrder, $user))->toBeFalse()
        ->and(Plugin::getInstance()->approvalTiers->requiredLevels($company->id, $belowOrder->getTotalPrice()))->toBeEmpty();
});

it('arms via the legacy threshold, not the tier band, when a company configures both', function (float $total, bool $expected) {
    // threshold=500 arms below the lowest tier band (1000): the legacy single-threshold gate must
    // still fire on its own, independent of the tier gate never having been reached.
    [$user, $company] = approvalMember(CompanyRole::Purchaser, 500.0);
    Plugin::getInstance()->approvalTiers->setTier($company->id, 1, 1000.0, 'approver', false);

    expect(Plugin::getInstance()->approvals->needsApproval(approvalOrder($total), $user))->toBe($expected);
})->with([
    'above the legacy threshold but below the lowest tier' => [600.0, true],
    'below both the legacy threshold and the lowest tier' => [400.0, false],
]);
