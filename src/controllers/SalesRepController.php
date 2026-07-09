<?php

namespace totalwebcreations\b2bcommerce\controllers;

use Craft;
use craft\web\Controller;
use totalwebcreations\b2bcommerce\elements\Company;
use totalwebcreations\b2bcommerce\Plugin;
use yii\web\NotFoundHttpException;
use yii\web\Response;

class SalesRepController extends Controller
{
    protected array|bool|int $allowAnonymous = false;

    public function beforeAction($action): bool
    {
        if (!parent::beforeAction($action)) {
            return false;
        }

        // "end" must stay reachable while the active identity is the impersonated member (who does
        // not hold the rep permission), otherwise a rep would be trapped in the impersonation. Every
        // other action requires the rep permission; the per-company scope is re-checked in actAs.
        if ($action->id !== 'end') {
            $this->requirePermission('b2b-commerce:orderOnBehalf');
        }

        return true;
    }

    public function actionIndex(): Response
    {
        $rep = Craft::$app->getUser()->getIdentity();
        $salesReps = Plugin::getInstance()->salesReps;
        $members = Plugin::getInstance()->companyMembers;

        $companies = array_map(
            fn(Company $company): array => [
                'company' => $company,
                'members' => $members->getMemberUsers($company->id),
            ],
            $salesReps->getCompaniesForRep($rep->id),
        );

        return $this->renderTemplate('b2b/sales-rep/index', [
            'companies' => $companies,
        ]);
    }

    public function actionAct(): Response
    {
        $this->requirePostRequest();

        $rep = Craft::$app->getUser()->getIdentity();
        $targetId = (int) Craft::$app->getRequest()->getRequiredBodyParam('userId');
        $target = Craft::$app->getUsers()->getUserById($targetId);

        if ($target === null) {
            throw new NotFoundHttpException();
        }

        // Throws ForbiddenHttpException when the rep is out of scope for this member's company.
        Plugin::getInstance()->salesReps->actAs($rep, $target);

        return $this->redirectToPostedUrl();
    }

    public function actionEnd(): Response
    {
        $this->requirePostRequest();

        Plugin::getInstance()->salesReps->endActingAs();

        return $this->redirectToPostedUrl();
    }
}
