<?php

namespace totalwebcreations\b2bcommerce\controllers;

use Craft;
use craft\commerce\elements\Order;
use craft\web\Controller;
use totalwebcreations\b2bcommerce\elements\Quote;
use totalwebcreations\b2bcommerce\Plugin;
use yii\base\Exception;
use yii\web\NotFoundHttpException;
use yii\web\Response;
use yii\web\ServerErrorHttpException;

/**
 * Control-panel PDF downloads for a quote (from the Quote edit screen) and an order/invoice (from
 * the company order overview). Both are read-only GET downloads. The two actions live on pages with
 * DIFFERENT permissions — the quote lives on a manageQuotes page, the invoice on the manageCompanies
 * company-orders overview — so each action requires its own permission rather than sharing one gate
 * in beforeAction (a manageCompanies-only operator must be able to download an invoice without a 403).
 */
class DocumentsCpController extends Controller
{
    public function beforeAction($action): bool
    {
        if (!parent::beforeAction($action)) {
            return false;
        }

        $this->requireCpRequest();

        return true;
    }

    public function actionQuote(int $quoteId): Response
    {
        $this->requirePermission('b2b-commerce:manageQuotes');

        $quote = Quote::find()->id($quoteId)->status(null)->one();

        if ($quote === null) {
            throw new NotFoundHttpException(Craft::t('b2b-commerce', 'Quote not found.'));
        }

        $order = $quote->getOrder();

        if ($order === null) {
            throw new NotFoundHttpException(Craft::t('b2b-commerce', 'Order not found.'));
        }

        return $this->sendPdf(
            $this->attemptPdfRender(fn (): string => Plugin::getInstance()->pdfDocuments->renderQuotePdf($order)),
            Plugin::getInstance()->pdfDocuments->fileName($order, 'quote'),
        );
    }

    public function actionInvoice(int $orderId): Response
    {
        $this->requirePermission('b2b-commerce:manageCompanies');

        $order = Order::find()->id($orderId)->status(null)->one();

        if ($order === null) {
            throw new NotFoundHttpException(Craft::t('b2b-commerce', 'Order not found.'));
        }

        return $this->sendPdf(
            $this->attemptPdfRender(fn (): string => Plugin::getInstance()->pdfDocuments->renderInvoicePdf($order)),
            Plugin::getInstance()->pdfDocuments->fileName($order, 'invoice'),
        );
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
