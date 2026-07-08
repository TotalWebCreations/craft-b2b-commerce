<?php

namespace totalwebcreations\b2bcommerce\controllers;

use Craft;
use craft\commerce\elements\Order;
use craft\db\Query;
use craft\web\Controller;
use totalwebcreations\b2bcommerce\Plugin;
use yii\base\Exception;
use yii\base\InvalidArgumentException;
use yii\web\NotFoundHttpException;
use yii\web\Response;
use yii\web\ServerErrorHttpException;

/**
 * Storefront PDF downloads. The quote PDF is authorized by the same accept token the quote mail
 * carries (scoped to the buyer's company, sent/accepted only); the invoice PDF is member-guarded —
 * the caller must be signed in and the completed invoice order must belong to their company.
 */
class DocumentsController extends Controller
{
    public function actionQuote(): Response
    {
        $this->requireLogin();

        // Named quoteToken rather than the interface's plain "token": Craft reserves the query
        // param named after config.tokenParam (`token` by default) for its OWN token-route
        // resolution (share/preview links). Any GET request carrying an unrelated value under that
        // exact key never reaches this action at all — craft\web\Application::init() rejects it with
        // a 400 "Invalid token" before routing runs. See craft\web\Request::_findToken().
        $token = Craft::$app->getRequest()->getQueryParam('quoteToken', '');
        $token = is_string($token) ? $token : '';
        $actor = Craft::$app->getUser()->getIdentity();

        try {
            $order = Plugin::getInstance()->quotes->authorizeQuoteDownload($token, $actor);
        } catch (InvalidArgumentException) {
            throw new NotFoundHttpException(Craft::t('b2b-commerce', 'This quote is not available.'));
        }

        return $this->sendPdf(
            $this->attemptPdfRender(fn (): string => Plugin::getInstance()->pdfDocuments->renderQuotePdf($order)),
            Plugin::getInstance()->pdfDocuments->fileName($order, 'quote'),
        );
    }

    public function actionInvoice(): Response
    {
        $this->requireLogin();

        $number = Craft::$app->getRequest()->getQueryParam('orderNumber', '');
        $number = is_string($number) ? $number : '';
        $actor = Craft::$app->getUser()->getIdentity();

        $order = $number !== '' ? Order::find()->number($number)->isCompleted(true)->status(null)->one() : null;

        if ($order === null || !$this->canDownloadInvoice((int) $order->id, $actor->id)) {
            throw new NotFoundHttpException(Craft::t('b2b-commerce', 'This document is not available.'));
        }

        return $this->sendPdf(
            $this->attemptPdfRender(fn (): string => Plugin::getInstance()->pdfDocuments->renderInvoicePdf($order)),
            Plugin::getInstance()->pdfDocuments->fileName($order, 'invoice'),
        );
    }

    /**
     * A completed order is downloadable by a buyer only when it was placed on account (isInvoice)
     * AND it is linked to the caller's own company. Both are read from the b2b_order_company snapshot
     * written at completion, so archiving a gateway later cannot change the answer.
     */
    private function canDownloadInvoice(int $orderId, int $userId): bool
    {
        $link = (new Query())
            ->select(['companyId', 'isInvoice'])
            ->from('{{%b2b_order_company}}')
            ->where(['orderId' => $orderId])
            ->one();

        if ($link === false || $link === null || !$link['isInvoice']) {
            return false;
        }

        $company = Plugin::getInstance()->companyMembers->getCompanyForUser($userId);

        return $company !== null && (int) $link['companyId'] === $company->id;
    }

    /**
     * Runs the render, turning a missing-template failure into a clean 500 with an actionable
     * message rather than an unhandled dompdf/Twig stack trace. Named attemptPdfRender rather than
     * render: yii\base\Controller already declares a public render() method, and a private method of
     * the same name fatals at compile time ("access level must be public, as in class
     * yii\base\Controller") — this is not a mere style choice.
     *
     * @param callable(): string $render
     */
    private function attemptPdfRender(callable $render): string
    {
        try {
            return $render();
        } catch (Exception $exception) {
            throw new ServerErrorHttpException(
                Craft::t('b2b-commerce', 'The PDF template could not be found. Copy the example template into your templates folder or set the template path in the plugin settings.'),
                0,
                $exception,
            );
        }
    }

    private function sendPdf(string $pdf, string $fileName): Response
    {
        return $this->response->sendContentAsFile($pdf, "{$fileName}.pdf", [
            'mimeType' => 'application/pdf',
            'inline' => false,
        ]);
    }
}
