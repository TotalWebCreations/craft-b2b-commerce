<?php

namespace totalwebcreations\b2bcommerce\controllers;

use Craft;
use craft\commerce\elements\Order;
use craft\helpers\DateTimeHelper;
use craft\helpers\UrlHelper;
use craft\web\Controller;
use DateTime;
use DateTimeZone;
use totalwebcreations\b2bcommerce\controllers\concerns\ReadsStringBodyParams;
use totalwebcreations\b2bcommerce\elements\Company;
use totalwebcreations\b2bcommerce\elements\Quote;
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

    /**
     * Read view for a single quote, reached from the native element index. The list itself is a
     * Craft element index (b2b/quotes → elementindex); this detail page hosts the mark-sent and
     * decline actions so the merchant workflow stays reachable from the read view.
     */
    public function actionEdit(int $quoteId): Response
    {
        $quote = Quote::find()->id($quoteId)->status(null)->one();

        if ($quote === null) {
            throw new NotFoundHttpException(Craft::t('b2b-commerce', 'Quote not found.'));
        }

        $company = $quote->companyId !== null
            ? Company::find()->id($quote->companyId)->site('*')->unique()->status(null)->one()
            : null;

        $requester = $quote->requestedById !== null
            ? Craft::$app->getUsers()->getUserById($quote->requestedById)
            : null;

        return $this->renderTemplate('b2b-commerce/quotes/_edit', [
            'quote' => $quote,
            'order' => $quote->getOrder(),
            'companyName' => $company?->title,
            'requesterName' => $requester !== null ? ($requester->fullName ?: $requester->email) : null,
        ]);
    }

    /**
     * The "Send as B2B quote" confirmation screen, reached from the Commerce order-edit button or
     * the Quote index. Shows the order and its customer and lets the merchant pick which of the
     * customer's companies to bind the quote to (and an optional validity date). The pick is
     * re-validated server-side in createMerchantQuote.
     */
    public function actionNew(int $orderId): Response
    {
        $order = Order::find()->id($orderId)->status(null)->one();

        if ($order === null) {
            throw new NotFoundHttpException(Craft::t('b2b-commerce', 'Order not found.'));
        }

        $customer = $order->getCustomer();

        $companies = $customer !== null
            ? $this->approvedCompaniesForPicker($customer->id)
            : [];

        $suggested = $customer !== null
            ? Plugin::getInstance()->companyMembers->getCompanyForUser($customer->id)
            : null;

        return $this->renderTemplate('b2b-commerce/quotes/_new', [
            'order' => $order,
            'customer' => $customer,
            'companies' => $companies,
            'suggestedCompanyId' => $suggested?->id,
        ]);
    }

    /**
     * Wraps a merchant-built order in a sent B2B quote. The order's own customer is the quote
     * requester; companyId is the picker's choice (or empty to auto-link the customer's company).
     * All validation — membership, approved company, freeze — lives in createMerchantQuote.
     */
    public function actionCreate(): ?Response
    {
        $this->requirePostRequest();

        $order = $this->findQuoteOrder();
        $customer = $order->getCustomer();

        if ($customer === null) {
            return $this->createFailure(
                Craft::t('b2b-commerce', 'This order has no customer yet.'),
                UrlHelper::cpUrl('b2b/quotes/new', ['orderId' => (int) $order->id])
            );
        }

        $companyIdRaw = Craft::$app->getRequest()->getBodyParam('companyId');
        $companyId = ($companyIdRaw !== null && $companyIdRaw !== '') ? (int) $companyIdRaw : null;

        $validUntilRaw = Craft::$app->getRequest()->getBodyParam('validUntil');
        $validUntil = null;

        if ($this->hasValidUntilDate($validUntilRaw)) {
            $validUntil = self::normalizeValidUntil($validUntilRaw);

            if ($validUntil === null) {
                return $this->createFailure(
                    Craft::t('b2b-commerce', 'The validity date is invalid.'),
                    UrlHelper::cpUrl('b2b/quotes/new', ['orderId' => (int) $order->id])
                );
            }
        }

        try {
            Plugin::getInstance()->quotes->createMerchantQuote($order, $customer, $companyId, $validUntil);
        } catch (InvalidArgumentException $exception) {
            return $this->createFailure(
                $exception->getMessage(),
                UrlHelper::cpUrl('b2b/quotes/new', ['orderId' => (int) $order->id])
            );
        }

        return $this->asSuccess(Craft::t('b2b-commerce', 'Quote sent to the customer.'));
    }

    /**
     * Refuses a CP quote action cleanly. asFailure() alone is not enough here: for a plain
     * (non-JSON-accepting) CP request it only sets a flash and returns null, and Craft's action
     * dispatch (Application::_processActionRequest) treats a null action result as "unhandled" and
     * falls through to normal page routing — which 404s, since `/admin/actions/...` matches no page
     * route. Explicitly redirecting back to a screen where the flash renders guarantees a real
     * response for both the JSON and plain-form cases.
     */
    private function createFailure(string $message, string $redirectUrl): ?Response
    {
        $response = $this->asFailure($message);

        if ($response !== null) {
            return $response;
        }

        return $this->redirect($redirectUrl);
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
                return $this->createFailure(
                    Craft::t('b2b-commerce', 'The validity date is invalid.'),
                    UrlHelper::cpUrl('b2b/quotes')
                );
            }
        }

        try {
            Plugin::getInstance()->quotes->markSent($order, $validUntil);
        } catch (InvalidArgumentException $exception) {
            return $this->createFailure($exception->getMessage(), UrlHelper::cpUrl('b2b/quotes'));
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
            return $this->createFailure($exception->getMessage(), UrlHelper::cpUrl('b2b/quotes'));
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

    /**
     * Companies the customer may legitimately quote under. createMerchantQuote refuses any
     * company whose status is not approved, so offering a pending or blocked company in the
     * picker would just be a dead, misleading option — filter it out here instead.
     *
     * @return array<int, Company>
     */
    private function approvedCompaniesForPicker(int $customerId): array
    {
        $companies = Plugin::getInstance()->companyMembers->getCompaniesForUser($customerId);

        return array_values(array_filter(
            $companies,
            static fn(Company $company): bool => $company->companyStatus === Company::STATUS_APPROVED
        ));
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
}
