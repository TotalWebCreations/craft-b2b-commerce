<?php

namespace totalwebcreations\b2bcommerce\modules\companies\services;

use Craft;
use craft\db\Query;
use craft\elements\User;
use craft\helpers\Db;
use totalwebcreations\b2bcommerce\elements\Company;
use totalwebcreations\b2bcommerce\enums\CompanyRole;
use totalwebcreations\b2bcommerce\Plugin;
use yii\base\Component;
use yii\base\InvalidArgumentException;

class CompanyMembers extends Component
{
    public function inviteMember(
        Company $company,
        string $email,
        string $firstName,
        string $lastName,
        CompanyRole $role,
    ): User {
        if ($company->companyStatus !== Company::STATUS_APPROVED) {
            throw new InvalidArgumentException(
                Craft::t('b2b-commerce', 'Only approved companies can invite members.')
            );
        }

        $existingUser = $this->findUserByEmail($email);

        if ($existingUser !== null) {
            if ($this->getCompanyForUser($existingUser->id) !== null) {
                throw new InvalidArgumentException(
                    Craft::t('b2b-commerce', 'This person already belongs to a company.')
                );
            }

            $this->addUserToCompany($existingUser->id, $company->id, $role);
            $this->notifyInvitedUser($company, $existingUser);

            return $existingUser;
        }

        $transaction = Craft::$app->getDb()->beginTransaction();

        try {
            $user = new User();
            $user->username = $email;
            $user->email = $email;
            $user->firstName = $firstName;
            $user->lastName = $lastName;
            $user->pending = true;

            if (!Craft::$app->getElements()->saveElement($user)) {
                throw new InvalidArgumentException(implode(' ', $user->getFirstErrors()));
            }

            $this->addUserToCompany($user->id, $company->id, $role);

            $transaction->commit();
        } catch (\Throwable $e) {
            $transaction->rollBack();

            throw $e;
        }

        if (!Craft::$app->getUsers()->sendActivationEmail($user)) {
            Craft::warning("Failed to send activation email to {$user->email}", 'b2b-commerce');
        }

        return $user;
    }

    public function changeRole(Company $company, int $userId, CompanyRole $role): void
    {
        $currentRole = $this->getRoleForUser($userId, $company->id);

        if ($currentRole === null) {
            throw new InvalidArgumentException(
                Craft::t('b2b-commerce', 'This user is not a member of this company.')
            );
        }

        if ($currentRole === CompanyRole::Admin
            && $role !== CompanyRole::Admin
            && $this->countAdmins($company->id) <= 1) {
            throw new InvalidArgumentException(
                Craft::t('b2b-commerce', 'A company must keep at least one admin.')
            );
        }

        $this->addUserToCompany($userId, $company->id, $role);
    }

    public function removeMember(Company $company, int $userId): void
    {
        $currentRole = $this->getRoleForUser($userId, $company->id);

        if ($currentRole === null) {
            throw new InvalidArgumentException(
                Craft::t('b2b-commerce', 'This user is not a member of this company.')
            );
        }

        if ($currentRole === CompanyRole::Admin && $this->countAdmins($company->id) <= 1) {
            throw new InvalidArgumentException(
                Craft::t('b2b-commerce', 'A company must keep at least one admin.')
            );
        }

        $this->removeUserFromCompany($userId, $company->id);
    }

    public function addUserToCompany(int $userId, int $companyId, CompanyRole $role): void
    {
        Db::upsert('{{%b2b_company_users}}', [
            'companyId' => $companyId,
            'userId' => $userId,
            'role' => $role->value,
        ]);

        // Single seam for every add path (invite, register, console, role change): place the member
        // in the company's pricing group. The sync is a no-op when the company is not approved or
        // has no group, and is idempotent, so re-running it on a role change is harmless.
        $company = $this->getCompanyById($companyId);

        if ($company !== null) {
            Plugin::getInstance()->customerGroupSync->syncMember($userId, $company);
        }
    }

    public function removeUserFromCompany(int $userId, int $companyId): void
    {
        Db::delete('{{%b2b_company_users}}', [
            'companyId' => $companyId,
            'userId' => $userId,
        ]);

        Plugin::getInstance()->customerGroupSync->unsyncMember($userId);
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

    /**
     * Every company the user is a member of, lowest membership id first, matching
     * getCompanyForUser's ordering. Used by the merchant-quote picker to list only the
     * companies the customer may legitimately quote under.
     *
     * @return array<int, Company>
     */
    public function getCompaniesForUser(int $userId): array
    {
        $companyIds = (new Query())
            ->select('companyId')
            ->from('{{%b2b_company_users}}')
            ->where(['userId' => $userId])
            ->orderBy(['id' => SORT_ASC])
            ->column();

        if ($companyIds === []) {
            return [];
        }

        // Companies are non-localized elements hosted on the primary site only, so query with site('*').
        return Company::find()->id($companyIds)->site('*')->unique()->status(null)->all();
    }

    public function getCompanyById(int $id): ?Company
    {
        // Companies are non-localized elements hosted on the primary site only, so query with site('*').
        return Company::find()->id($id)->site('*')->unique()->status(null)->one();
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

    /**
     * Hydrates every membership row into its user, batched to avoid an N+1.
     *
     * @return array<int, array{user: User, role: CompanyRole}>
     */
    public function getMemberUsers(int $companyId): array
    {
        $members = $this->getMembers($companyId);

        if ($members === []) {
            return [];
        }

        $userIds = array_column($members, 'userId');

        /** @var array<int, User> $users */
        $users = User::find()
            ->id($userIds)
            ->status(null)
            ->indexBy('id')
            ->all();

        $rows = [];

        foreach ($members as $member) {
            $user = $users[$member['userId']] ?? null;

            if ($user === null) {
                continue;
            }

            $rows[] = [
                'user' => $user,
                'role' => CompanyRole::from($member['role']),
            ];
        }

        return $rows;
    }

    /**
     * Resolves a user by email case-insensitively, independent of the database
     * collation, by comparing lowercased values on both sides.
     */
    public function findUserByEmail(string $email): ?User
    {
        return User::find()
            ->status(null)
            ->where(['lower([[users.email]])' => mb_strtolower($email)])
            ->one();
    }

    private function countAdmins(int $companyId): int
    {
        return (int) (new Query())
            ->from('{{%b2b_company_users}}')
            ->where(['companyId' => $companyId, 'role' => CompanyRole::Admin->value])
            ->count();
    }

    private function notifyInvitedUser(Company $company, User $user): void
    {
        if ($user->pending) {
            // A pending user has no password yet, so "you can now sign in" is wrong.
            // Send the activation mail instead so they can set one and sign in.
            if (!Craft::$app->getUsers()->sendActivationEmail($user)) {
                Craft::warning("Failed to send activation email to {$user->email}", 'b2b-commerce');
            }

            return;
        }

        $this->notifyMemberAdded($company, $user);
    }

    private function notifyMemberAdded(Company $company, User $user): void
    {
        $sent = Craft::$app->getMailer()
            ->composeFromKey('b2b_member_added', ['company' => $company, 'user' => $user])
            ->setTo($user)
            ->send();

        if (!$sent) {
            Craft::warning("Failed to send `b2b_member_added` email to {$user->email}", 'b2b-commerce');
        }
    }
}
