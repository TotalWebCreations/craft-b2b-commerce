<?php

namespace totalwebcreations\b2bcommerce\modules\departments\services;

use Craft;
use craft\db\Query;
use craft\helpers\Db;
use totalwebcreations\b2bcommerce\elements\Company;
use totalwebcreations\b2bcommerce\enums\BudgetPeriod;
use totalwebcreations\b2bcommerce\Plugin;
use yii\base\Component;
use yii\base\InvalidArgumentException;

/**
 * Flat (one-level) departments within a company. A department carries its own aggregate spending
 * budget (a cap on the sum of its members' spend — see DepartmentBudget) and its own approval
 * routing (see eligibleApproversForUser). A member belongs to at most one department, recorded as
 * the departmentId on their b2b_company_users row.
 *
 * Rows are handled as plain arrays, mirroring Budgets and Quotes rather than introducing a model.
 */
class Departments extends Component
{
    public function createDepartment(
        Company $company,
        string $name,
        ?float $budgetAmount,
        BudgetPeriod $period,
        ?int $approverUserId,
    ): int {
        $this->assertApproverIsMember($company, $approverUserId);

        Db::insert('{{%b2b_departments}}', [
            'companyId' => $company->id,
            'name' => $name,
            'budgetAmount' => $budgetAmount,
            'budgetPeriod' => $period->value,
            'approverUserId' => $approverUserId,
        ]);

        return (int) Craft::$app->getDb()->getLastInsertID();
    }

    public function updateDepartment(
        int $departmentId,
        string $name,
        ?float $budgetAmount,
        BudgetPeriod $period,
        ?int $approverUserId,
    ): void {
        $department = $this->getDepartment($departmentId);

        if ($department === null) {
            throw new InvalidArgumentException(
                Craft::t('b2b-commerce', 'Department not found.')
            );
        }

        $company = Plugin::getInstance()->companyMembers->getCompanyById((int) $department['companyId']);

        if ($company !== null) {
            $this->assertApproverIsMember($company, $approverUserId);
        }

        Db::update('{{%b2b_departments}}', [
            'name' => $name,
            'budgetAmount' => $budgetAmount,
            'budgetPeriod' => $period->value,
            'approverUserId' => $approverUserId,
        ], ['id' => $departmentId]);
    }

    public function deleteDepartment(int $departmentId): void
    {
        // Members keep their b2b_company_users row; the departmentId FK is SET NULL, so they simply
        // become department-less (and department-budget-unlimited). No orphan enforcement.
        Db::delete('{{%b2b_departments}}', ['id' => $departmentId]);
    }

    /** @return array<string, mixed>|null */
    public function getDepartment(int $departmentId): ?array
    {
        return (new Query())
            ->from('{{%b2b_departments}}')
            ->where(['id' => $departmentId])
            ->one() ?: null;
    }

    /** @return array<int, array<string, mixed>> */
    public function getDepartmentsForCompany(int $companyId): array
    {
        return (new Query())
            ->from('{{%b2b_departments}}')
            ->where(['companyId' => $companyId])
            ->orderBy(['name' => SORT_ASC])
            ->all();
    }

    /**
     * Places a member in (or, with a null department, removes them from) a department. Guards that
     * the user is a member of the company and that the target department belongs to that same
     * company, so a member can never be pinned to a foreign department.
     */
    public function assignMember(Company $company, int $userId, ?int $departmentId): void
    {
        if (Plugin::getInstance()->companyMembers->getRoleForUser($userId, $company->id) === null) {
            throw new InvalidArgumentException(
                Craft::t('b2b-commerce', 'This user is not a member of this company.')
            );
        }

        if ($departmentId !== null) {
            $department = $this->getDepartment($departmentId);

            if ($department === null || (int) $department['companyId'] !== $company->id) {
                throw new InvalidArgumentException(
                    Craft::t('b2b-commerce', 'That department does not belong to this company.')
                );
            }
        }

        Db::update('{{%b2b_company_users}}', [
            'departmentId' => $departmentId,
        ], ['companyId' => $company->id, 'userId' => $userId]);
    }

    /**
     * The department of the user's lowest-id membership, or null when they have none. Uses the same
     * lowest-id membership rule as CompanyMembers::getCompanyForUser so the two always agree on which
     * company/department a multi-row user belongs to.
     *
     * @return array<string, mixed>|null
     */
    public function getDepartmentForUser(int $userId): ?array
    {
        $departmentId = (new Query())
            ->select('departmentId')
            ->from('{{%b2b_company_users}}')
            ->where(['userId' => $userId])
            ->orderBy(['id' => SORT_ASC])
            ->scalar();

        if (!$departmentId) {
            return null;
        }

        return $this->getDepartment((int) $departmentId);
    }

    /** @return array<int, int> */
    public function getMemberIds(int $departmentId): array
    {
        $ids = (new Query())
            ->select('userId')
            ->from('{{%b2b_company_users}}')
            ->where(['departmentId' => $departmentId])
            ->column();

        return array_map('intval', $ids);
    }

    private function assertApproverIsMember(Company $company, ?int $approverUserId): void
    {
        if ($approverUserId === null) {
            return;
        }

        if (Plugin::getInstance()->companyMembers->getRoleForUser($approverUserId, $company->id) === null) {
            throw new InvalidArgumentException(
                Craft::t('b2b-commerce', 'The department approver must be a member of the company.')
            );
        }
    }
}
