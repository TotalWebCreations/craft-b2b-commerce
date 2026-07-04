<?php

namespace totalwebcreations\b2bcommerce\controllers;

use Craft;
use craft\commerce\elements\Order;
use craft\commerce\Plugin as Commerce;
use craft\db\Query;
use craft\helpers\UrlHelper;
use craft\web\Controller;
use totalwebcreations\b2bcommerce\controllers\concerns\ReadsStringBodyParams;
use totalwebcreations\b2bcommerce\elements\Company;
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

        $members = array_map(
            fn(array $row): array => [
                'user' => $row['user'],
                'roleValue' => $row['role']->value,
                'roleLabel' => Craft::t('b2b-commerce', $row['role']->name),
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

        return $this->renderTemplate('b2b-commerce/companies/_members', [
            'company' => $company,
            'members' => $members,
            'roleOptions' => $roleOptions,
        ]);
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

    private function redirectToMembers(Company $company): Response
    {
        return $this->redirect(UrlHelper::cpUrl("b2b/companies/{$company->id}/members"));
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
