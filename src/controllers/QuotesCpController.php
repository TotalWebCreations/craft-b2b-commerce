<?php

namespace totalwebcreations\b2bcommerce\controllers;

use Craft;
use craft\commerce\elements\Order;
use craft\helpers\DateTimeHelper;
use craft\web\Controller;
use DateTime;
use DateTimeZone;
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

        $validUntilRaw = Craft::$app->getRequest()->getBodyParam('validUntil');
        $validUntil = null;

        if ($this->hasValidUntilDate($validUntilRaw)) {
            $validUntil = self::normalizeValidUntil($validUntilRaw);

            if ($validUntil === null) {
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

    /**
     * Whether the posted validUntil actually carries a date. Craft's date field posts an array
     * (a date, plus a timezone and locale) and an empty picker still posts an empty date, so this
     * looks past the wrapper: an absent field or an empty picker is "no expiry", not a value to
     * parse. Keeping this separate from parsing lets the caller tell "left blank" (allowed) apart
     * from "filled with garbage" (rejected).
     */
    private function hasValidUntilDate(mixed $value): bool
    {
        if (is_array($value)) {
            $value = $value['date'] ?? '';
        }

        return trim((string) $value) !== '';
    }

    /**
     * Turns a date picked in the CP into the last instant of that calendar day in the site's own
     * timezone. A quote's validity is a whole-day guarantee: "valid until the 9th" must leave the
     * buyer the whole of the 9th, not expire at midnight as it starts, and the day is measured
     * where the store lives (Craft::$app->getTimeZone()) rather than in UTC. The whole posted value
     * is handed to DateTimeHelper so the CP date field's array shape -- and the locale it posts, so
     * a localized short date still parses -- is honoured. Returns null when the value cannot be read
     * as a date so the caller can fail cleanly.
     */
    public static function normalizeValidUntil(mixed $value): ?DateTime
    {
        if (empty($value)) {
            return null;
        }

        $date = DateTimeHelper::toDateTime($value, true);

        if ($date === false) {
            return null;
        }

        $date->setTimezone(new DateTimeZone(Craft::$app->getTimeZone()));
        $date->setTime(23, 59, 59);

        return $date;
    }

    private function findQuoteOrder(): Order
    {
        $orderId = (int) Craft::$app->getRequest()->getRequiredBodyParam('orderId');

        $order = Order::find()->id($orderId)->status(null)->one();

        if ($order === null) {
            throw new NotFoundHttpException(Craft::t('b2b-commerce', 'Order not found.'));
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
