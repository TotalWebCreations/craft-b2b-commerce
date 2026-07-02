<?php

namespace totalwebcreations\b2bcommerce\controllers;

use craft\web\Controller;
use totalwebcreations\b2bcommerce\enums\ApprovalStatus;
use totalwebcreations\b2bcommerce\Plugin;
use yii\web\Response;

class ApprovalsCpController extends Controller
{
    public function beforeAction($action): bool
    {
        if (!parent::beforeAction($action)) {
            return false;
        }

        $this->requireCpRequest();
        $this->requirePermission('b2b-commerce:manageApprovals');

        return true;
    }

    public function actionIndex(?string $status = null): Response
    {
        $status = $this->normalizeStatus($status);

        return $this->renderTemplate('b2b-commerce/approvals/_index', [
            'approvals' => Plugin::getInstance()->approvals->getApprovalsForCp($status),
            'currentStatus' => $status,
            'statuses' => ApprovalStatus::cases(),
        ]);
    }

    private function normalizeStatus(?string $status): ?string
    {
        if ($status === null || ApprovalStatus::tryFrom($status) === null) {
            return null;
        }

        return $status;
    }
}
