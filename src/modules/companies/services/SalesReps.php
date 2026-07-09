<?php

namespace totalwebcreations\b2bcommerce\modules\companies\services;

use craft\db\Query;
use craft\elements\User;
use craft\helpers\Db;
use totalwebcreations\b2bcommerce\elements\Company;
use yii\base\Component;

class SalesReps extends Component
{
    public const ACTION_ACT_AS = 'act_as';
    public const ACTION_END = 'end_act_as';
    public const ACTION_ORDER_PLACED = 'order_placed';

    public function assignRep(int $repUserId, int $companyId): void
    {
        // Upsert against the unique (repUserId, companyId) index -> assigning twice is a no-op.
        Db::upsert('{{%b2b_rep_companies}}', [
            'repUserId' => $repUserId,
            'companyId' => $companyId,
        ]);
    }

    public function unassignRep(int $repUserId, int $companyId): void
    {
        Db::delete('{{%b2b_rep_companies}}', [
            'repUserId' => $repUserId,
            'companyId' => $companyId,
        ]);
    }

    public function isRepForCompany(int $repUserId, int $companyId): bool
    {
        return (new Query())
            ->from('{{%b2b_rep_companies}}')
            ->where(['repUserId' => $repUserId, 'companyId' => $companyId])
            ->exists();
    }

    /** @return array<int, Company> */
    public function getCompaniesForRep(int $repUserId): array
    {
        $companyIds = (new Query())
            ->select('companyId')
            ->from('{{%b2b_rep_companies}}')
            ->where(['repUserId' => $repUserId])
            ->column();

        if ($companyIds === []) {
            return [];
        }

        // Companies are non-localized elements hosted on the primary site only.
        return Company::find()->id($companyIds)->site('*')->unique()->status(null)->all();
    }

    /** @return array<int, User> */
    public function getRepsForCompany(int $companyId): array
    {
        $repIds = (new Query())
            ->select('repUserId')
            ->from('{{%b2b_rep_companies}}')
            ->where(['companyId' => $companyId])
            ->column();

        if ($repIds === []) {
            return [];
        }

        return User::find()->id($repIds)->status(null)->all();
    }

    /**
     * The authoritative server-side scope gate. A rep may act for a company ONLY when they hold the
     * orderOnBehalf permission AND carry an assignment row for it. Deliberately independent of Craft's
     * impersonateUsers/admin state: an admin's can() returns true for the permission, but with no
     * assignment row this still returns false, so admin/impersonateUsers alone grants no rep scope.
     */
    public function canActFor(User $rep, Company $company): bool
    {
        if (!$rep->can('b2b-commerce:orderOnBehalf')) {
            return false;
        }

        return $this->isRepForCompany($rep->id, $company->id);
    }

    public function log(int $repUserId, int $targetUserId, ?int $companyId, ?int $orderId, string $action): void
    {
        // Db::insert auto-populates dateCreated/dateUpdated/uid for tables carrying those columns.
        Db::insert('{{%b2b_impersonation_log}}', [
            'repUserId' => $repUserId,
            'targetUserId' => $targetUserId,
            'companyId' => $companyId,
            'orderId' => $orderId,
            'action' => $action,
        ]);
    }

    /** @return array<int, array<string, mixed>> */
    public function getLog(?int $companyId = null): array
    {
        $query = (new Query())
            ->from('{{%b2b_impersonation_log}}')
            ->orderBy(['dateCreated' => SORT_DESC, 'id' => SORT_DESC]);

        if ($companyId !== null) {
            $query->where(['companyId' => $companyId]);
        }

        return $query->all();
    }
}
