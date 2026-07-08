<?php

use craft\commerce\elements\Order;
use totalwebcreations\b2bcommerce\enums\QuoteStatus;
use totalwebcreations\b2bcommerce\Plugin;

// quoteMember(), quoteCartWithItem(), mailSnapshot(), decodedMailSince() live in
// helpers.php; insertQuoteRow() in QuoteMerchantTest.php — all loaded globally by the suite.

/**
 * Registers a stub phase-16 pdfDocuments service that renders a fixed PDF byte string,
 * runs the callback, then restores the previous component so other tests are unaffected.
 */
function withStubDocuments(string $pdfBytes, callable $callback): void
{
    $plugin = Plugin::getInstance();
    $previous = $plugin->has('pdfDocuments') ? $plugin->get('pdfDocuments') : null;

    $plugin->set('pdfDocuments', new class ($pdfBytes) {
        public function __construct(private string $bytes) {}

        public function renderQuotePdf(Order $order): string
        {
            return $this->bytes;
        }
    });

    try {
        $callback();
    } finally {
        $plugin->set('pdfDocuments', $previous);
    }
}

it('attaches the phase-16 quote PDF to the sent-quote email when the service is available', function () {
    [$user, $company] = quoteMember();
    $order = quoteCartWithItem();
    insertQuoteRow($order->id, QuoteStatus::Requested->value, $company->id, $user->id);

    $reference = $order->reference ?: $order->getShortNumber();
    $snapshot = mailSnapshot();

    withStubDocuments('%PDF-1.4 stub quote', function () use ($order) {
        Plugin::getInstance()->quotes->markSent($order, null);
    });

    $raw = decodedMailSince($snapshot);

    expect($raw)->toContain('application/pdf')
        ->and($raw)->toContain("quote-{$reference}.pdf");
});

it('still sends the sent-quote email when no pdfDocuments service is registered', function () {
    [$user, $company] = quoteMember();
    $order = quoteCartWithItem();
    $token = insertQuoteRow($order->id, QuoteStatus::Requested->value, $company->id, $user->id);

    $plugin = Plugin::getInstance();
    $previous = $plugin->has('pdfDocuments') ? $plugin->get('pdfDocuments') : null;
    $plugin->set('pdfDocuments', null);

    $snapshot = mailSnapshot();

    try {
        Plugin::getInstance()->quotes->markSent($order, null);
    } finally {
        $plugin->set('pdfDocuments', $previous);
    }

    $raw = decodedMailSince($snapshot);

    expect($raw)->toContain($token);
});
