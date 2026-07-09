<?php

namespace totalwebcreations\b2bcommerce\modules\companies\services;

use Craft;
use craft\commerce\elements\Order;
use craft\db\Query;
use craft\elements\User;
use craft\helpers\Db;
use totalwebcreations\b2bcommerce\elements\Company;
use totalwebcreations\b2bcommerce\Plugin;
use yii\base\Component;
use yii\web\ForbiddenHttpException;

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

    /**
     * Starts acting as $target using Craft's native impersonation. The active identity becomes the
     * member, so every downstream storefront guard runs against the member — the rep gets no
     * elevation. Two gates run first: our own server-side scope check (canActFor), then Craft's
     * native canImpersonate (impersonateUsers permission + permission-superset + never-an-admin).
     */
    public function actAs(User $rep, User $target): void
    {
        $company = Plugin::getInstance()->companyMembers->getCompanyForUser($target->id);

        if ($company === null) {
            throw new ForbiddenHttpException(
                Craft::t('b2b-commerce', 'You cannot act on behalf of this user.')
            );
        }

        if (!$this->canActFor($rep, $company)) {
            throw new ForbiddenHttpException(
                Craft::t('b2b-commerce', 'You are not assigned to this company.')
            );
        }

        if (!Craft::$app->getUsers()->canImpersonate($rep, $target)) {
            throw new ForbiddenHttpException(
                Craft::t('b2b-commerce', 'You cannot act on behalf of this user.')
            );
        }

        $userSession = Craft::$app->getUser();

        // Store the rep BEFORE switching identity so User::findIdentity() tolerates a not-yet-active
        // member, mirroring Craft's own actionImpersonate ordering.
        $userSession->setImpersonatorId($rep->id);

        if (!$userSession->loginByUserId($target->id)) {
            $userSession->setImpersonatorId(null);

            throw new ForbiddenHttpException(
                Craft::t('b2b-commerce', 'You cannot act on behalf of this user.')
            );
        }

        $this->log($rep->id, $target->id, $company->id, null, self::ACTION_ACT_AS);
    }

    /**
     * Ends impersonation: clears the __impersonator_id marker and logs the rep back in. A no-op when
     * the session is not impersonating, so it is safe to call unconditionally (and from the member's
     * own identity, which is why the controller exempts it from the permission gate).
     */
    public function endActingAs(): void
    {
        $userSession = Craft::$app->getUser();
        $impersonatorId = $userSession->getImpersonatorId();

        if ($impersonatorId === null) {
            return;
        }

        $targetId = (int) $userSession->getId();

        // Remove the impersonation marker first, then restore the rep as a first-class login.
        $userSession->setImpersonatorId(null);
        $userSession->loginByUserId($impersonatorId);

        $this->log($impersonatorId, $targetId, null, null, self::ACTION_END);
    }

    /**
     * At completion time, returns the impersonator's id ONLY when they are a genuine rep for the
     * order's company. getImpersonatorId() itself is permission-gated (the impersonator must hold
     * impersonateUsers), and canActFor re-checks the B2B assignment — so a native-impersonating admin
     * without an assignment resolves to null and is never stamped or logged as a rep.
     */
    public function resolveActingRepId(Company $company): ?int
    {
        $impersonatorId = Craft::$app->getUser()->getImpersonatorId();

        if ($impersonatorId === null) {
            return null;
        }

        $rep = Craft::$app->getUsers()->getUserById($impersonatorId);

        if ($rep === null) {
            return null;
        }

        if (!$this->canActFor($rep, $company)) {
            return null;
        }

        return $impersonatorId;
    }
}
