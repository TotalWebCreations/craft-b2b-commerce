<?php

namespace totalwebcreations\b2bcommerce\modules\pricing\services;

use Craft;
use craft\db\Query;
use craft\models\UserGroup;
use totalwebcreations\b2bcommerce\elements\Company;
use totalwebcreations\b2bcommerce\Plugin;
use yii\base\Component;

/**
 * Keeps company members in the Craft user group that drives their native Commerce catalog prices.
 *
 * A company points at a "pricing" user group (Company::$customerGroupId). This service keeps the
 * company's members in that group so a merchant's Commerce catalog pricing rule with a customer
 * condition on the group prices them automatically — no custom pricing engine.
 *
 * The service only ever touches the set of B2B-managed groups: the group ids currently referenced
 * by ANY company's customerGroupId (see {@see managedGroupIds()}). A member is only ever in their
 * own company's pricing group among that managed set; every other membership the user has (roles,
 * permission groups, unrelated groups) is preserved untouched.
 *
 * Only APPROVED companies place their members in the pricing group. A pending or blocked company's
 * members are removed from every managed group, so an unapproved account never receives wholesale
 * prices.
 */
class CustomerGroupSync extends Component
{
    /**
     * Ensures the user sits in the given company's pricing group (when the company is approved and
     * a group is set) and in no other B2B-managed group. Idempotent.
     */
    public function syncMember(int $userId, Company $company): void
    {
        $this->applyGroups($userId, $this->targetGroupIdFor($company), $this->managedGroupIds());
    }

    /**
     * Resyncs every member of the company. Used when the company's group or status changes.
     *
     * $previousGroupId is the group the company pointed at before this change. It is folded into the
     * managed set for this operation so members are moved OUT of it even when the change orphaned it
     * (no company references it anymore, so it would otherwise fall out of the managed set).
     */
    public function syncCompany(Company $company, ?int $previousGroupId = null): void
    {
        $managedGroupIds = $this->managedGroupIds();

        if ($previousGroupId !== null && !in_array($previousGroupId, $managedGroupIds, true)) {
            $managedGroupIds[] = $previousGroupId;
        }

        $targetGroupId = $this->targetGroupIdFor($company);

        foreach ($this->memberIds($company->id) as $userId) {
            $this->applyGroups($userId, $targetGroupId, $managedGroupIds);
        }
    }

    /**
     * Removes the user from every B2B-managed group, preserving all other memberships. Used when a
     * member is removed from their company.
     */
    public function unsyncMember(int $userId): void
    {
        $this->applyGroups($userId, null, $this->managedGroupIds());
    }

    /**
     * The company's pricing group, or null when the company is not approved (pending/blocked members
     * must not receive wholesale prices) or no group is configured.
     */
    private function targetGroupIdFor(Company $company): ?int
    {
        if ($company->companyStatus !== Company::STATUS_APPROVED) {
            return null;
        }

        return $company->customerGroupId;
    }

    /**
     * Recomputes the user's group membership: drop every managed group, then add the single target
     * group (if any), leaving all unrelated memberships intact. Saved in one idempotent call.
     *
     * @param int[] $managedGroupIds
     */
    private function applyGroups(int $userId, ?int $targetGroupId, array $managedGroupIds): void
    {
        $currentGroupIds = array_map(
            static fn(UserGroup $group): int => (int) $group->id,
            Craft::$app->getUserGroups()->getGroupsByUserId($userId)
        );

        $targetGroupIds = array_values(array_diff($currentGroupIds, $managedGroupIds));

        if ($targetGroupId !== null) {
            $targetGroupIds[] = $targetGroupId;
        }

        Craft::$app->getUsers()->assignUserToGroups($userId, array_values(array_unique($targetGroupIds)));
    }

    /**
     * The set of B2B-managed group ids: every group id currently referenced by a company's
     * customerGroupId. These are the only groups the service ever adds to or removes from.
     *
     * @return int[]
     */
    public function managedGroupIds(): array
    {
        $ids = (new Query())
            ->select(['customerGroupId'])
            ->distinct()
            ->from('{{%b2b_companies}}')
            ->where(['not', ['customerGroupId' => null]])
            ->column();

        return array_map('intval', $ids);
    }

    /**
     * @return int[]
     */
    private function memberIds(int $companyId): array
    {
        return array_map(
            static fn(array $member): int => $member['userId'],
            Plugin::getInstance()->companyMembers->getMembers($companyId)
        );
    }
}
