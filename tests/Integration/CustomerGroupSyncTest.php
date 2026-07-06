<?php

use Craft;
use craft\commerce\elements\conditions\customers\CatalogPricingRuleCustomerCondition;
use craft\commerce\models\CatalogPricingRule;
use craft\commerce\Plugin as Commerce;
use craft\commerce\records\CatalogPricingRule as CatalogPricingRuleRecord;
use craft\db\Query;
use craft\elements\conditions\users\GroupConditionRule;
use craft\models\UserGroup;
use totalwebcreations\b2bcommerce\elements\Company;
use totalwebcreations\b2bcommerce\enums\CompanyRole;
use totalwebcreations\b2bcommerce\Plugin;

/**
 * User groups and catalog pricing rules are not elements, so trackElement() cannot reap them.
 * These file-scoped trackers hard-delete everything this suite creates in afterEach.
 *
 * @var int[] $GLOBALS['b2bTrackedGroupIds']
 * @var int[] $GLOBALS['b2bTrackedRuleIds']
 */
$GLOBALS['b2bTrackedGroupIds'] = [];
$GLOBALS['b2bTrackedRuleIds'] = [];

/**
 * Creates and tracks a throwaway user group.
 */
function createTestUserGroup(string $label = 'Pricing'): UserGroup
{
    $group = new UserGroup();
    $group->name = "B2B {$label} " . uniqid();
    $group->handle = 'b2b' . $label . random_int(100000, 999999);

    if (!Craft::$app->getUserGroups()->saveGroup($group)) {
        throw new RuntimeException('Could not save test user group: ' . implode(', ', $group->getFirstErrors()));
    }

    $GLOBALS['b2bTrackedGroupIds'][] = $group->id;

    return $group;
}

/**
 * Reports whether a user currently belongs to a group, read straight from the table.
 */
function userInGroup(int $userId, int $groupId): bool
{
    return (new Query())
        ->from('{{%usergroups_users}}')
        ->where(['userId' => $userId, 'groupId' => $groupId])
        ->exists();
}

/**
 * Sets a company's pricing group and saves it, so Company::afterSave resyncs its members.
 */
function assignPricingGroup(Company $company, ?int $groupId): void
{
    $company->customerGroupId = $groupId;

    if (!craftApp()->getElements()->saveElement($company)) {
        throw new RuntimeException('Could not save company: ' . implode(', ', $company->getFirstErrors()));
    }
}

afterEach(function () {
    foreach ($GLOBALS['b2bTrackedRuleIds'] as $ruleId) {
        Commerce::getInstance()->getCatalogPricingRules()->deleteCatalogPricingRuleById($ruleId);
    }

    foreach ($GLOBALS['b2bTrackedGroupIds'] as $groupId) {
        Craft::$app->getUserGroups()->deleteGroupById($groupId);
    }

    $GLOBALS['b2bTrackedRuleIds'] = [];
    $GLOBALS['b2bTrackedGroupIds'] = [];
});

it('places an approved company\'s existing members in its pricing group', function () {
    $group = createTestUserGroup();
    $company = createTestCompany(Company::STATUS_APPROVED, 'Sync Co');
    $user = createTestUser('sync_' . uniqid() . '@example.test');
    Plugin::getInstance()->companyMembers->addUserToCompany($user->id, $company->id, CompanyRole::Admin);

    expect(userInGroup($user->id, $group->id))->toBeFalse();

    assignPricingGroup($company, $group->id);

    expect(userInGroup($user->id, $group->id))->toBeTrue();
});

it('syncs a member added after the group is assigned', function () {
    $group = createTestUserGroup();
    $company = createTestCompany(Company::STATUS_APPROVED, 'Sync Co');
    assignPricingGroup($company, $group->id);

    $user = createTestUser('later_' . uniqid() . '@example.test');
    Plugin::getInstance()->companyMembers->addUserToCompany($user->id, $company->id, CompanyRole::Purchaser);

    expect(userInGroup($user->id, $group->id))->toBeTrue();
});

it('removes a member from the pricing group when removed from the company', function () {
    $group = createTestUserGroup();
    $company = createTestCompany(Company::STATUS_APPROVED, 'Sync Co');
    assignPricingGroup($company, $group->id);

    $admin = createTestUser('admin_' . uniqid() . '@example.test');
    $purchaser = createTestUser('purch_' . uniqid() . '@example.test');
    Plugin::getInstance()->companyMembers->addUserToCompany($admin->id, $company->id, CompanyRole::Admin);
    Plugin::getInstance()->companyMembers->addUserToCompany($purchaser->id, $company->id, CompanyRole::Purchaser);

    expect(userInGroup($purchaser->id, $group->id))->toBeTrue();

    Plugin::getInstance()->companyMembers->removeMember($company, $purchaser->id);

    expect(userInGroup($purchaser->id, $group->id))->toBeFalse()
        ->and(userInGroup($admin->id, $group->id))->toBeTrue();
});

it('moves all members to the new group and out of the old when the group changes', function () {
    $oldGroup = createTestUserGroup('Old');
    $newGroup = createTestUserGroup('New');
    $company = createTestCompany(Company::STATUS_APPROVED, 'Move Co');
    $user = createTestUser('move_' . uniqid() . '@example.test');
    Plugin::getInstance()->companyMembers->addUserToCompany($user->id, $company->id, CompanyRole::Admin);

    assignPricingGroup($company, $oldGroup->id);
    expect(userInGroup($user->id, $oldGroup->id))->toBeTrue();

    assignPricingGroup($company, $newGroup->id);

    expect(userInGroup($user->id, $newGroup->id))->toBeTrue()
        ->and(userInGroup($user->id, $oldGroup->id))->toBeFalse();
});

it('preserves an unrelated group membership while syncing', function () {
    $unrelatedGroup = createTestUserGroup('Unrelated');
    $pricingGroup = createTestUserGroup('Pricing');
    $company = createTestCompany(Company::STATUS_APPROVED, 'Preserve Co');
    $admin = createTestUser('keepadmin_' . uniqid() . '@example.test');
    $user = createTestUser('preserve_' . uniqid() . '@example.test');

    // A membership the plugin must never touch: it is not referenced by any company.
    Craft::$app->getUsers()->assignUserToGroups($user->id, [$unrelatedGroup->id]);

    // A second admin so the tested purchaser can be removed later.
    Plugin::getInstance()->companyMembers->addUserToCompany($admin->id, $company->id, CompanyRole::Admin);
    Plugin::getInstance()->companyMembers->addUserToCompany($user->id, $company->id, CompanyRole::Purchaser);
    assignPricingGroup($company, $pricingGroup->id);

    expect(userInGroup($user->id, $pricingGroup->id))->toBeTrue()
        ->and(userInGroup($user->id, $unrelatedGroup->id))->toBeTrue();

    // And removing the member leaves the unrelated membership intact.
    Plugin::getInstance()->companyMembers->removeMember($company, $user->id);

    expect(userInGroup($user->id, $pricingGroup->id))->toBeFalse()
        ->and(userInGroup($user->id, $unrelatedGroup->id))->toBeTrue();
});

it('does not place a pending company\'s members in the group, but does after approval', function () {
    $group = createTestUserGroup();
    $company = createTestCompany(Company::STATUS_PENDING, 'Pending Co');
    $user = createTestUser('pending_' . uniqid() . '@example.test');
    Plugin::getInstance()->companyMembers->addUserToCompany($user->id, $company->id, CompanyRole::Admin);

    assignPricingGroup($company, $group->id);

    expect(userInGroup($user->id, $group->id))->toBeFalse();

    Plugin::getInstance()->companyApproval->approve($company);

    expect(userInGroup($user->id, $group->id))->toBeTrue();
});

it('unsyncs members when an approved company is blocked', function () {
    $group = createTestUserGroup();
    $company = createTestCompany(Company::STATUS_APPROVED, 'Block Co');
    $user = createTestUser('block_' . uniqid() . '@example.test');
    Plugin::getInstance()->companyMembers->addUserToCompany($user->id, $company->id, CompanyRole::Admin);
    assignPricingGroup($company, $group->id);

    expect(userInGroup($user->id, $group->id))->toBeTrue();

    Plugin::getInstance()->companyApproval->block($company);

    expect(userInGroup($user->id, $group->id))->toBeFalse();
});

it('targets the company member with a catalog pricing rule on their group that yields the reduced price', function () {
    // End-to-end pricing proof at the wiring + rule-math level: the plugin puts the member in the
    // group, and a native Commerce catalog pricing rule whose customer condition matches that group
    // both selects the member (and not an outsider) and computes the reduced price from the base.
    //
    // The full catalog-price *recalculation* (generateCatalogPrices + getCatalogPrice) is asserted
    // manually against the dev site instead — see .superpowers/sdd/p12-report.md. It cannot run in
    // this harness because the shared dev database carries orphaned commerce_sitestores rows (site
    // ids of long-deleted sites), which makes Commerce's recalculation path dereference a null Site;
    // that is a pre-existing dev-data quirk, unrelated to this feature.
    $store = Commerce::getInstance()->getStores()->getPrimaryStore();
    $group = createTestUserGroup();

    $company = createTestCompany(Company::STATUS_APPROVED, 'Price Co');
    $member = createTestUser('member_' . uniqid() . '@example.test');
    Plugin::getInstance()->companyMembers->addUserToCompany($member->id, $company->id, CompanyRole::Admin);
    assignPricingGroup($company, $group->id);

    $nonMember = createTestUser('outsider_' . uniqid() . '@example.test');

    expect(userInGroup($member->id, $group->id))->toBeTrue();

    // A native Commerce catalog pricing rule that sets the price to a flat 80 for the group.
    $rule = new CatalogPricingRule();
    $rule->name = 'B2B wholesale ' . uniqid();
    $rule->storeId = $store->id;
    $rule->enabled = true;
    $rule->apply = CatalogPricingRuleRecord::APPLY_TO_FLAT;
    $rule->applyAmount = -80.0;
    $rule->applyPriceType = CatalogPricingRuleRecord::APPLY_PRICE_TYPE_PRICE;

    $condition = new CatalogPricingRuleCustomerCondition();
    $groupRule = $condition->createConditionRule([
        'class' => GroupConditionRule::class,
        'values' => [$group->uid],
    ]);
    $condition->addConditionRule($groupRule);
    $rule->setCustomerCondition($condition);

    if (!Commerce::getInstance()->getCatalogPricingRules()->saveCatalogPricingRule($rule)) {
        throw new RuntimeException('Could not save catalog pricing rule: ' . implode(', ', $rule->getErrorSummary(true)));
    }

    $GLOBALS['b2bTrackedRuleIds'][] = $rule->id;

    // The rule's customer condition selects exactly the group's members: the company member matches,
    // the outsider does not.
    $reloaded = Commerce::getInstance()->getCatalogPricingRules()->getCatalogPricingRuleById($rule->id, $store->id);
    $matchedUserIds = $reloaded->getUserIds();

    expect($reloaded->getCustomerCondition()->matchElement($member))->toBeTrue()
        ->and($reloaded->getCustomerCondition()->matchElement($nonMember))->toBeFalse()
        ->and($matchedUserIds)->toContain($member->id)
        ->and($matchedUserIds)->not->toContain($nonMember->id)
        // And the rule reduces the 100.00 base price to the wholesale 80.00 it will publish.
        ->and(Commerce::getInstance()->getCatalogPricingRules()->generateRulePriceFromPrice(100.0, null, $reloaded))
        ->toEqual(80.0);
});
