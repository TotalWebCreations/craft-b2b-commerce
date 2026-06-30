<?php

namespace totalwebcreations\b2bcommerce\controllers;

use Craft;
use craft\commerce\elements\Order;
use craft\web\Controller;
use DateTime;
use Exception;
use totalwebcreations\b2bcommerce\controllers\concerns\ReadsStringBodyParams;
use totalwebcreations\b2bcommerce\enums\QuoteStatus;
use totalwebcreations\b2bcommerce\Plugin;
use yii\base\InvalidArgumentException;
use yii\web\NotFoundHttpException;
use yii\web\Response;

class QuotesCpController extends Controller
{
    use ReadsStringBodyParams;

    public function beforeAction($action): bool
    {
        if (!parent::beforeAction($action)) {
            return false;
        }

        $this->requireCpRequest();
        $this->requirePermission('b2b-commerce:manageQuotes');

        return true;
    }

    public function actionIndex(?string $status = null): Response
    {
        $status = $this->normalizeStatus($status);

        return $this->renderTemplate('b2b-commerce/quotes/_index', [
            'quotes' => Plugin::getInstance()->quotes->getQuotesForCp($status),
            'currentStatus' => $status,
            'statuses' => QuoteStatus::cases(),
        ]);
    }

    public function actionMarkSent(): ?Response
    {
        $this->requirePostRequest();

        $order = $this->findQuoteOrder();

        $validUntilRaw = $this->stringBodyParam('validUntil');
        $validUntil = null;

        if ($validUntilRaw !== '') {
            try {
                $validUntil = new DateTime($validUntilRaw);
            } catch (Exception) {
                return $this->asFailure(
                    Craft::t('b2b-commerce', 'The validity date is invalid.')
                );
            }
        }

        try {
            Plugin::getInstance()->quotes->markSent($order, $validUntil);
        } catch (InvalidArgumentException $exception) {
            return $this->asFailure($exception->getMessage());
        }

        return $this->asSuccess(Craft::t('b2b-commerce', 'Quote sent.'));
    }

    public function actionDecline(): ?Response
    {
        $this->requirePostRequest();

        $order = $this->findQuoteOrder();
        $reason = $this->stringBodyParam('reason');

        try {
            Plugin::getInstance()->quotes->decline($order, $reason, byCustomer: false);
        } catch (InvalidArgumentException $exception) {
            return $this->asFailure($exception->getMessage());
        }

        return $this->asSuccess(Craft::t('b2b-commerce', 'Quote declined.'));
    }

    private function findQuoteOrder(): Order
    {
        $orderId = (int) Craft::$app->getRequest()->getRequiredBodyParam('orderId');

        $order = Order::find()->id($orderId)->status(null)->one();

        if ($order === null) {
            throw new NotFoundHttpException(Craft::t('b2b-commerce', 'This order is not a quote.'));
        }

        return $order;
    }

    private function normalizeStatus(?string $status): ?string
    {
        if ($status === null || QuoteStatus::tryFrom($status) === null) {
            return null;
        }

        return $status;
    }
}
