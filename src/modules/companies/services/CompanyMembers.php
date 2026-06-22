<?php

namespace totalwebcreations\b2bcommerce\modules\companies\services;

use craft\db\Query;
use craft\helpers\Db;
use totalwebcreations\b2bcommerce\elements\Company;
use totalwebcreations\b2bcommerce\enums\CompanyRole;
use yii\base\Component;

class CompanyMembers extends Component
{
    public function addUserToCompany(int $userId, int $companyId, CompanyRole $role): void
    {
        Db::upsert('{{%b2b_company_users}}', [
            'companyId' => $companyId,
            'userId' => $userId,
            'role' => $role->value,
        ]);
    }

    public function removeUserFromCompany(int $userId, int $companyId): void
    {
        Db::delete('{{%b2b_company_users}}', [
            'companyId' => $companyId,
            'userId' => $userId,
        ]);
    }

    public function getCompanyForUser(int $userId): ?Company
    {
        $companyId = (new Query())
            ->select('companyId')
            ->from('{{%b2b_company_users}}')
            ->where(['userId' => $userId])
            ->orderBy(['id' => SORT_ASC])
            ->scalar();

        if (!$companyId) {
            return null;
        }

        // Companies are non-localized elements hosted on the primary site only, so query with site('*').
        return Company::find()->id($companyId)->site('*')->unique()->status(null)->one();
    }

    public function getRoleForUser(int $userId, int $companyId): ?CompanyRole
    {
        $role = (new Query())
            ->select('role')
            ->from('{{%b2b_company_users}}')
            ->where(['userId' => $userId, 'companyId' => $companyId])
            ->scalar();

        return $role ? CompanyRole::from($role) : null;
    }

    /** @return array<int, array{userId: int, role: string}> */
    public function getMembers(int $companyId): array
    {
        $rows = (new Query())
            ->select(['userId', 'role'])
            ->from('{{%b2b_company_users}}')
            ->where(['companyId' => $companyId])
            ->all();

        return array_map(
            fn(array $row): array => ['userId' => (int) $row['userId'], 'role' => $row['role']],
            $rows
        );
    }
}
