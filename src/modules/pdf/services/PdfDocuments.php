<?php

namespace totalwebcreations\b2bcommerce\modules\pdf\services;

use Craft;
use craft\commerce\elements\Order;
use craft\commerce\Plugin as Commerce;
use craft\db\Query;
use craft\web\View;
use DateTime;
use DateTimeZone;
use Dompdf\Dompdf;
use totalwebcreations\b2bcommerce\elements\Company;
use totalwebcreations\b2bcommerce\Plugin;
use yii\base\Component;
use yii\web\Response;

/**
 * Builds and renders the B2B quote and order/invoice PDFs. The actual render is delegated to
 * Commerce's native Pdfs service (dompdf); this service only resolves the template path (a merchant
 * override, or the bundled example) and assembles the template variables. No PDF library is added.
 */
class PdfDocuments extends Component
{
    // Namespaced under the plugin's own `b2b-commerce` site template root (registered in Plugin::
    // registerSiteTemplateRoot()), NOT the merchant-facing `b2b/` example namespace — these resolve
    // to the templates shipped inside the plugin at src/templates/pdf/*.twig, so renderQuotePdf/
    // renderInvoicePdf work out of the box with no merchant template override configured.
    public const DEFAULT_QUOTE_TEMPLATE = 'b2b-commerce/pdf/quote';
    public const DEFAULT_INVOICE_TEMPLATE = 'b2b-commerce/pdf/invoice';
    public const DEFAULT_STATEMENT_TEMPLATE = 'b2b-commerce/pdf/statement';

    public function quoteTemplatePath(): string
    {
        $override = Plugin::getInstance()->getSettings()->quotePdfTemplate;

        return $override !== '' ? $override : self::DEFAULT_QUOTE_TEMPLATE;
    }

    public function invoiceTemplatePath(): string
    {
        $override = Plugin::getInstance()->getSettings()->invoicePdfTemplate;

        return $override !== '' ? $override : self::DEFAULT_INVOICE_TEMPLATE;
    }

    public function statementTemplatePath(): string
    {
        $override = Plugin::getInstance()->getSettings()->statementPdfTemplate;

        return $override !== '' ? $override : self::DEFAULT_STATEMENT_TEMPLATE;
    }

    /**
     * @return array{documentType: string, company: ?Company, validUntil: ?DateTime}
     */
    public function quoteVariables(Order $order): array
    {
        $row = $this->quoteRow((int) $order->id);

        return [
            'documentType' => 'quote',
            'company' => $this->resolveCompany($row['companyId'] ?? null),
            'validUntil' => $this->toUtcDateTime($row['validUntil'] ?? null),
        ];
    }

    /**
     * @return array{documentType: string, company: ?Company}
     */
    public function invoiceVariables(Order $order): array
    {
        // A completed invoice order carries a b2b_order_company link row, so the order behavior
        // resolves its company directly.
        return [
            'documentType' => 'invoice',
            'company' => $order->getBehavior('b2bOrder')->getB2bCompany(),
        ];
    }

    public function renderQuotePdf(Order $order): string
    {
        return Commerce::getInstance()->getPdfs()->renderPdfForOrder(
            $order,
            'b2b-quote',
            $this->quoteTemplatePath(),
            $this->quoteVariables($order),
        );
    }

    public function renderInvoicePdf(Order $order): string
    {
        return Commerce::getInstance()->getPdfs()->renderPdfForOrder(
            $order,
            'b2b-invoice',
            $this->invoiceTemplatePath(),
            $this->invoiceVariables($order),
        );
    }

    /**
     * Renders any site Twig template to a downloadable PDF response. Unlike renderQuotePdf/
     * renderInvoicePdf — which are order-bound and delegate to Commerce's Pdfs::renderPdfForOrder —
     * this is order-agnostic: it renders $templatePath in SITE mode with $variables to HTML and feeds
     * that HTML to dompdf directly (the same renderer Commerce's Pdfs uses; it ships as a Commerce
     * dependency, so no PDF library is added). Consumed by phase 22 for the account-statement PDF,
     * which is a computed document rather than an order.
     *
     * Builds its own Response rather than reusing Craft::$app->getResponse(): the app's shared
     * response component is a console Response under a console request (e.g. this suite, or a queue
     * job), which has no file-download support at all, so this always constructs a standalone
     * yii\web\Response and fills its headers directly via setDownloadHeaders() rather than
     * sendContentAsFile() — the latter also resolves the request's Range header off the shared
     * Yii::$app->getRequest(), which is a console request in that same scenario and has no
     * getHeaders(). Range support is not meaningful for a freshly generated PDF anyway.
     *
     * @param array<string, mixed> $variables
     */
    public function streamPdf(string $templatePath, array $variables, string $filename): Response
    {
        $html = Craft::$app->getView()->renderTemplate($templatePath, $variables, View::TEMPLATE_MODE_SITE);

        $dompdf = new Dompdf();
        $dompdf->loadHtml($html);
        $dompdf->render();

        $response = new Response();
        $response->setDownloadHeaders($filename, 'application/pdf');
        $response->format = Response::FORMAT_RAW;
        $response->content = $dompdf->output();

        return $response;
    }

    public function fileName(Order $order, string $prefix): string
    {
        $reference = $order->reference ?: $order->getShortNumber();

        return "{$prefix}-{$reference}";
    }

    /** @return array<string, mixed>|null */
    private function quoteRow(int $orderId): ?array
    {
        return (new Query())
            ->from('{{%b2b_quotes}}')
            ->where(['orderId' => $orderId])
            ->one() ?: null;
    }

    private function resolveCompany(mixed $companyId): ?Company
    {
        if ($companyId === null) {
            return null;
        }

        return Company::find()
            ->id((int) $companyId)
            ->site('*')
            ->unique()
            ->status(null)
            ->one();
    }

    private function toUtcDateTime(mixed $value): ?DateTime
    {
        if (empty($value)) {
            return null;
        }

        return new DateTime((string) $value, new DateTimeZone('UTC'));
    }
}
