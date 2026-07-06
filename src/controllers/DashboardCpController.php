<?php

namespace totalwebcreations\b2bcommerce\controllers;

use craft\commerce\Plugin as Commerce;
use craft\web\Controller;
use totalwebcreations\b2bcommerce\Plugin;
use yii\web\Response;

/**
 * Serves the B2B section landing: a read-only overview of the headline figures. Gated behind
 * manageCompanies — the same permission that guards the Companies index this page replaces as the
 * section's landing spot — so anyone who could already reach the section keeps reaching it.
 */
class DashboardCpController extends Controller
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

    public function actionIndex(): Response
    {
        return $this->renderTemplate('b2b-commerce/dashboard/_index', [
            'stats' => Plugin::getInstance()->overview->getStats(),
            'currency' => Commerce::getInstance()->getStores()->getPrimaryStore()?->getCurrency()?->getCode(),
        ]);
    }
}
