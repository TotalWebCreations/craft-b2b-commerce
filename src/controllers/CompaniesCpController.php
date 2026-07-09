<?php

namespace totalwebcreations\b2bcommerce\controllers;

use Craft;
use craft\commerce\elements\Order;
use craft\commerce\Plugin as Commerce;
use craft\db\Query;
use craft\elements\User;
use craft\helpers\UrlHelper;
use craft\web\Controller;
use DateTimeImmutable;
use totalwebcreations\b2bcommerce\controllers\concerns\ReadsStringBodyParams;
use totalwebcreations\b2bcommerce\elements\Company;
use totalwebcreations\b2bcommerce\enums\BudgetPeriod;
use totalwebcreations\b2bcommerce\enums\CompanyRole;
use totalwebcreations\b2bcommerce\Plugin;
use yii\base\InvalidArgumentException;
use yii\web\NotFoundHttpException;
use yii\web\Response;

class CompaniesCpController extends Controller
{
    use ReadsStringBodyParams;

    public function beforeAction($action): bool
    {
        if (!parent::beforeAction($action)) {
            return false;
        }

        $this->requireCpRequest();
        $this->requirePermission('b2b-commerce:manageCompanies');

        return true;
    }

    public function actionMembers(int $companyId): Response
    {
        $company = $this->findCompany($companyId);

        $budgets = Plugin::getInstance()->budgets;
        $now = new DateTimeImmutable('now');

        $members = array_map(
            fn(array $row): array => [
                'user' => $row['user'],
                'roleValue' => $row['role']->value,
                'roleLabel' => Craft::t('b2b-commerce', $row['role']->name),
                'budget' => $this->budgetInfo($company, $row['user'], $now),
            ],
            Plugin::getInstance()->companyMembers->getMemberUsers($company->id),
        );

        $roleOptions = array_map(
            fn(CompanyRole $role): array => [
                'label' => Craft::t('b2b-commerce', $role->name),
                'value' => $role->value,
            ],
            CompanyRole::cases(),
        );

        $budgetPeriodOptions = array_map(
            fn(BudgetPeriod $period): array => [
                'label' => Craft::t('b2b-commerce', $period->name),
                'value' => $period->value,
            ],
            BudgetPeriod::cases(),
        );

        // Budgets are single-currency like credit limits: format every budget figure in the primary
        // store's currency rather than the request locale.
        $currency = Commerce::getInstance()->getStores()->getPrimaryStore()?->getCurrency()?->getCode();

        return $this->renderTemplate('b2b-commerce/companies/_members', [
            'company' => $company,
            'members' => $members,
            'roleOptions' => $roleOptions,
            'budgetPeriodOptions' => $budgetPeriodOptions,
            'currency' => $currency,
        ]);
    }

    public function actionSetBudget(): Response
    {
        $this->requirePostRequest();

        $company = $this->findCompany((int) Craft::$app->getRequest()->getRequiredBodyParam('companyId'));
        $session = Craft::$app->getSession();

        $period = BudgetPeriod::tryFrom($this->requiredStringBodyParam('period'));

        if ($period === null) {
            $session->setError(Craft::t('b2b-commerce', 'Invalid budget period.'));

            return $this->redirectToMembers($company);
        }

        try {
            Plugin::getInstance()->budgets->setBudget(
                $company,
                (int) Craft::$app->getRequest()->getRequiredBodyParam('userId'),
                (float) Craft::$app->getRequest()->getRequiredBodyParam('amount'),
                $period,
            );
        } catch (InvalidArgumentException $exception) {
            $session->setError($exception->getMessage());

            return $this->redirectToMembers($company);
        }

        $session->setNotice(Craft::t('b2b-commerce', 'Budget saved.'));

        return $this->redirectToMembers($company);
    }

    public function actionRemoveBudget(): Response
    {
        $this->requirePostRequest();

        $company = $this->findCompany((int) Craft::$app->getRequest()->getRequiredBodyParam('companyId'));
        $session = Craft::$app->getSession();

        Plugin::getInstance()->budgets->removeBudget(
            $company,
            (int) Craft::$app->getRequest()->getRequiredBodyParam('userId'),
        );

        $session->setNotice(Craft::t('b2b-commerce', 'Budget removed.'));

        return $this->redirectToMembers($company);
    }

    public function actionOrders(int $companyId): Response
    {
        $company = $this->findCompany($companyId);

        $orderIds = (new Query())
            ->select('orderId')
            ->from('{{%b2b_order_company}}')
            ->where(['companyId' => $company->id])
            ->column();

        $orders = $orderIds !== []
            ? Order::find()->id($orderIds)->isCompleted(true)->status(null)->orderBy(['dateOrdered' => SORT_DESC])->all()
            : [];

        $summary = Plugin::getInstance()->creditBalance->getSummary($company->id);

        // Credit limits are single-currency: format every credit figure in the primary store's
        // currency rather than leaving the |currency filter to fall back to the request locale.
        $currency = Commerce::getInstance()->getStores()->getPrimaryStore()?->getCurrency()?->getCode();

        return $this->renderTemplate('b2b-commerce/companies/_orders', [
            'company' => $company,
            'orders' => $orders,
            'outstanding' => $summary['outstanding'],
            'creditLimit' => $summary['creditLimit'],
            'available' => $summary['available'],
            'currency' => $currency,
        ]);
    }

    public function actionApprovalTiers(int $companyId): Response
    {
        $company = $this->findCompany($companyId);

        $tiers = Plugin::getInstance()->approvalTiers->getTiers($company->id);

        // Tiers are single-currency like credit limits and budgets: format amounts in the primary
        // store's currency rather than the request locale.
        $currency = Commerce::getInstance()->getStores()->getPrimaryStore()?->getCurrency()?->getCode();

        return $this->renderTemplate('b2b-commerce/companies/_approvalTiers', [
            'company' => $company,
            'tiers' => $tiers,
            'currency' => $currency,
        ]);
    }

    public function actionSetTier(): Response
    {
        $this->requirePostRequest();

        $company = $this->findCompany((int) Craft::$app->getRequest()->getRequiredBodyParam('companyId'));
        $session = Craft::$app->getSession();

        try {
            Plugin::getInstance()->approvalTiers->setTier(
                $company->id,
                (int) Craft::$app->getRequest()->getRequiredBodyParam('level'),
                (float) Craft::$app->getRequest()->getRequiredBodyParam('minAmount'),
                $this->requiredStringBodyParam('approverRole'),
                (bool) Craft::$app->getRequest()->getBodyParam('departmentScoped', false),
            );
        } catch (InvalidArgumentException $exception) {
            $session->setError($exception->getMessage());

            return $this->redirectToTiers($company);
        }

        $session->setNotice(Craft::t('b2b-commerce', 'Tier saved.'));

        return $this->redirectToTiers($company);
    }

    public function actionDeleteTier(): Response
    {
        $this->requirePostRequest();

        $company = $this->findCompany((int) Craft::$app->getRequest()->getRequiredBodyParam('companyId'));

        Plugin::getInstance()->approvalTiers->deleteTier(
            $company->id,
            (int) Craft::$app->getRequest()->getRequiredBodyParam('level'),
        );

        Craft::$app->getSession()->setNotice(Craft::t('b2b-commerce', 'Tier removed.'));

        return $this->redirectToTiers($company);
    }

    public function actionAddMember(): Response
    {
        $this->requirePostRequest();

        $company = $this->findCompany((int) Craft::$app->getRequest()->getRequiredBodyParam('companyId'));
        $session = Craft::$app->getSession();

        $role = CompanyRole::tryFrom($this->requiredStringBodyParam('role'));

        if ($role === null) {
            $session->setError(Craft::t('b2b-commerce', 'Invalid role.'));

            return $this->redirectToMembers($company);
        }

        try {
            Plugin::getInstance()->companyMembers->inviteMember(
                $company,
                $this->requiredStringBodyParam('email'),
                $this->requiredStringBodyParam('firstName'),
                $this->requiredStringBodyParam('lastName'),
                $role,
            );
        } catch (InvalidArgumentException $exception) {
            $session->setError($exception->getMessage());

            return $this->redirectToMembers($company);
        }

        $session->setNotice(Craft::t('b2b-commerce', 'Contact person added.'));

        return $this->redirectToMembers($company);
    }

    public function actionChangeMemberRole(): Response
    {
        $this->requirePostRequest();

        $company = $this->findCompany((int) Craft::$app->getRequest()->getRequiredBodyParam('companyId'));
        $session = Craft::$app->getSession();

        $role = CompanyRole::tryFrom($this->requiredStringBodyParam('role'));

        if ($role === null) {
            $session->setError(Craft::t('b2b-commerce', 'Invalid role.'));

            return $this->redirectToMembers($company);
        }

        try {
            Plugin::getInstance()->companyMembers->changeRole(
                $company,
                (int) Craft::$app->getRequest()->getRequiredBodyParam('userId'),
                $role,
            );
        } catch (InvalidArgumentException $exception) {
            $session->setError($exception->getMessage());

            return $this->redirectToMembers($company);
        }

        $session->setNotice(Craft::t('b2b-commerce', 'Role updated.'));

        return $this->redirectToMembers($company);
    }

    public function actionRemoveMember(): Response
    {
        $this->requirePostRequest();

        $company = $this->findCompany((int) Craft::$app->getRequest()->getRequiredBodyParam('companyId'));
        $session = Craft::$app->getSession();

        try {
            Plugin::getInstance()->companyMembers->removeMember(
                $company,
                (int) Craft::$app->getRequest()->getRequiredBodyParam('userId'),
            );
        } catch (InvalidArgumentException $exception) {
            $session->setError($exception->getMessage());

            return $this->redirectToMembers($company);
        }

        $session->setNotice(Craft::t('b2b-commerce', 'Contact person removed.'));

        return $this->redirectToMembers($company);
    }

    /**
     * The member's current budget as a display row, or null when they have no budget (unlimited) or
     * no linked user. `remaining` is the room left this period, never below zero.
     *
     * @return array{
     *     amount: float,
     *     periodValue: string,
     *     periodLabel: string,
     *     spent: float,
     *     remaining: float
     * }|null
     */
    private function budgetInfo(Company $company, ?User $user, DateTimeImmutable $now): ?array
    {
        if ($user === null) {
            return null;
        }

        $budgets = Plugin::getInstance()->budgets;
        $budget = $budgets->getBudget($company->id, $user->id);

        if ($budget === null) {
            return null;
        }

        $period = BudgetPeriod::from((string) $budget['period']);
        $amount = (float) $budget['amount'];
        $spent = $budgets->getSpent($company->id, $user->id, $now);

        return [
            'amount' => $amount,
            'periodValue' => $period->value,
            'periodLabel' => Craft::t('b2b-commerce', $period->name),
            'spent' => $spent,
            'remaining' => max(0.0, $amount - $spent),
        ];
    }

    private function redirectToMembers(Company $company): Response
    {
        return $this->redirect(UrlHelper::cpUrl("b2b/companies/{$company->id}/members"));
    }

    private function redirectToTiers(Company $company): Response
    {
        return $this->redirect(UrlHelper::cpUrl("b2b/companies/{$company->id}/approval-tiers"));
    }

    private function findCompany(int $companyId): Company
    {
        $company = Plugin::getInstance()->companyMembers->getCompanyById($companyId);

        if ($company === null) {
            throw new NotFoundHttpException(Craft::t('b2b-commerce', 'Company not found.'));
        }

        return $company;
    }
}
