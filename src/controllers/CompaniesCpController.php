<?php

namespace totalwebcreations\b2bcommerce\controllers;

use Craft;
use craft\commerce\elements\Order;
use craft\db\Query;
use craft\elements\User;
use craft\web\Controller;
use totalwebcreations\b2bcommerce\elements\Company;
use totalwebcreations\b2bcommerce\enums\CompanyRole;
use totalwebcreations\b2bcommerce\Plugin;
use yii\web\NotFoundHttpException;
use yii\web\Response;

class CompaniesCpController extends Controller
{
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
            function (array $row): array {
                $user = User::find()->id($row['userId'])->status(null)->one();
                $role = CompanyRole::tryFrom($row['role']);

                return [
                    'user' => $user,
                    'roleLabel' => $role !== null
                        ? Craft::t('b2b-commerce', $role->name)
                        : $row['role'],
                ];
            },
            Plugin::getInstance()->companyMembers->getMembers($company->id),
        );

        return $this->renderTemplate('b2b-commerce/companies/_members', [
            'company' => $company,
            'members' => $members,
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

        return $this->renderTemplate('b2b-commerce/companies/_orders', [
            'company' => $company,
            'orders' => $orders,
        ]);
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
