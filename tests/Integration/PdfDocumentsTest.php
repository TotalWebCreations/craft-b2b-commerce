<?php

use craft\commerce\events\PdfRenderEvent;
use craft\commerce\services\Pdfs;
use totalwebcreations\b2bcommerce\enums\QuoteStatus;
use totalwebcreations\b2bcommerce\Plugin;
use yii\base\Event;

// quoteMember(), quoteCartWithItem(), quoteRow() live in tests/Integration/helpers.php;
// insertQuoteRow() in QuoteMerchantTest.php — all loaded globally by the suite.

it('falls back to the bundled quote and invoice templates when no override is set', function () {
    $settings = Plugin::getInstance()->getSettings();
    [$q, $i] = [$settings->quotePdfTemplate, $settings->invoicePdfTemplate];
    $settings->quotePdfTemplate = '';
    $settings->invoicePdfTemplate = '';

    try {
        $service = Plugin::getInstance()->pdfDocuments;

        expect($service->quoteTemplatePath())->toBe('b2b/pdf/quote.twig')
            ->and($service->invoiceTemplatePath())->toBe('b2b/pdf/invoice.twig');
    } finally {
        $settings->quotePdfTemplate = $q;
        $settings->invoicePdfTemplate = $i;
    }
});

it('prefers a configured template path over the bundled default', function () {
    $settings = Plugin::getInstance()->getSettings();
    $original = $settings->quotePdfTemplate;
    $settings->quotePdfTemplate = 'shop/my-quote';

    try {
        expect(Plugin::getInstance()->pdfDocuments->quoteTemplatePath())->toBe('shop/my-quote');
    } finally {
        $settings->quotePdfTemplate = $original;
    }
});

it('builds quote variables from the quote row, resolving the company from its companyId', function () {
    [$user, $company] = quoteMember();
    $order = quoteCartWithItem();
    insertQuoteRow($order->id, QuoteStatus::Sent->value, $company->id, $user->id, new DateTime('+7 days'));

    $variables = Plugin::getInstance()->pdfDocuments->quoteVariables($order);

    // The quote order is NOT completed, so the b2b_order_company link row does not exist yet and
    // order.b2bCompany would be null — the company MUST come from the quote row's companyId instead.
    expect($variables['documentType'])->toBe('quote')
        ->and($variables['company'])->not->toBeNull()
        ->and($variables['company']->id)->toBe($company->id)
        ->and($variables['validUntil'])->toBeInstanceOf(DateTime::class);
});

it('renders a quote PDF through Commerce with the B2B quote template and variables', function () {
    [$user, $company] = quoteMember();
    $order = quoteCartWithItem();
    insertQuoteRow($order->id, QuoteStatus::Sent->value, $company->id, $user->id);

    $captured = null;
    $handler = function (PdfRenderEvent $event) use (&$captured): void {
        $captured = [
            'template' => $event->template,
            'orderId' => $event->order->id,
            'variables' => $event->variables,
        ];
        // Short-circuits renderPdfForOrder before any template lookup or dompdf render.
        $event->pdf = 'STUB-PDF';
    };
    Event::on(Pdfs::class, Pdfs::EVENT_BEFORE_RENDER_PDF, $handler);

    try {
        $pdf = Plugin::getInstance()->pdfDocuments->renderQuotePdf($order);
    } finally {
        Event::off(Pdfs::class, Pdfs::EVENT_BEFORE_RENDER_PDF, $handler);
    }

    expect($pdf)->toBe('STUB-PDF')
        ->and($captured['template'])->toBe('b2b/pdf/quote.twig')
        ->and($captured['orderId'])->toBe($order->id)
        ->and($captured['variables']['documentType'])->toBe('quote')
        ->and($captured['variables']['company']->id)->toBe($company->id);
});

it('renders an invoice PDF through Commerce with the B2B invoice template', function () {
    $order = quoteCartWithItem();

    $captured = null;
    $handler = function (PdfRenderEvent $event) use (&$captured): void {
        $captured = ['template' => $event->template, 'variables' => $event->variables];
        $event->pdf = 'STUB-PDF';
    };
    Event::on(Pdfs::class, Pdfs::EVENT_BEFORE_RENDER_PDF, $handler);

    try {
        $pdf = Plugin::getInstance()->pdfDocuments->renderInvoicePdf($order);
    } finally {
        Event::off(Pdfs::class, Pdfs::EVENT_BEFORE_RENDER_PDF, $handler);
    }

    expect($pdf)->toBe('STUB-PDF')
        ->and($captured['template'])->toBe('b2b/pdf/invoice.twig')
        ->and($captured['variables']['documentType'])->toBe('invoice');
});

it('renders an arbitrary site template to a PDF response via streamPdf', function () {
    // streamPdf renders in SITE template mode, so the template must live in the dev site's templates
    // folder. Write a throwaway template that echoes a marker variable, then remove it. Assert on the
    // response (content-type + disposition) and that dompdf produced a real PDF — never diff bytes.
    $templatesDir = dirname(getcwd()) . '/b2b-dev/templates';

    if (!is_dir($templatesDir)) {
        test()->markTestSkipped('dev-site templates folder not found');
    }

    $devTemplates = $templatesDir . '/b2b-test';

    if (!is_dir($devTemplates)) {
        mkdir($devTemplates, 0777, true);
    }

    file_put_contents(
        "{$devTemplates}/streampdf.twig",
        '<!DOCTYPE html><html><body><p>{{ marker }}</p></body></html>'
    );

    try {
        $response = Plugin::getInstance()->pdfDocuments->streamPdf(
            'b2b-test/streampdf',
            ['marker' => 'STREAM-PDF-OK'],
            'statement-42.pdf',
        );

        expect($response)->toBeInstanceOf(\yii\web\Response::class)
            ->and($response->headers->get('Content-Type'))->toContain('application/pdf')
            // The download disposition carries the filename we passed.
            ->and($response->headers->get('Content-Disposition'))->toContain('statement-42.pdf')
            // The template rendered (its {{ marker }} resolved without error) and dompdf turned the
            // resulting HTML into a real PDF — proven by the %PDF- signature, not a byte diff.
            ->and(substr((string) $response->content, 0, 5))->toBe('%PDF-');
    } finally {
        @unlink("{$devTemplates}/streampdf.twig");
        @rmdir($devTemplates);
    }
});
