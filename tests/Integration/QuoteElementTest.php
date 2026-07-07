<?php

use craft\db\Query;
use totalwebcreations\b2bcommerce\elements\Quote;
use totalwebcreations\b2bcommerce\enums\QuoteStatus;
use totalwebcreations\b2bcommerce\Plugin;

// insertQuoteRow() lives in QuoteMerchantTest.php; quoteMember(), quoteCartWithItem() in
// helpers.php — all loaded globally by the suite.

it('creates an element row for a requested quote and makes it findable as an element', function () {
    [$user, $company] = quoteMember();
    $cart = quoteCartWithItem();
    $orderId = $cart->id;

    Plugin::getInstance()->quotes->requestQuote($cart, $user, 'Element please.');

    $quote = Quote::find()->orderId($orderId)->status(null)->one();

    $elementExists = (new Query())
        ->from('{{%elements}}')
        ->where(['id' => $quote?->id, 'type' => Quote::class])
        ->exists();

    // The element identity sits around the b2b_quotes row: the element id is the table PK, while
    // orderId stays the business key the enforcement guards read.
    expect($quote)->not->toBeNull()
        ->and($elementExists)->toBeTrue()
        ->and($quote->orderId)->toBe((int) $orderId)
        ->and($quote->companyId)->toBe($company->id)
        ->and($quote->quoteStatus)->toBe(QuoteStatus::Requested->value)
        ->and($quote->getStatus())->toBe(QuoteStatus::Requested->value)
        ->and($quote->notes)->toBe('Element please.');
});

it('filters quotes by status through the element query', function () {
    [$user, $company] = quoteMember();
    $sentOrder = quoteCartWithItem();
    $declinedOrder = quoteCartWithItem();
    insertQuoteRow($sentOrder->id, QuoteStatus::Sent->value, $company->id, $user->id);
    insertQuoteRow($declinedOrder->id, QuoteStatus::Declined->value, $company->id, $user->id);

    $sentOrderIds = array_map(
        fn(Quote $quote) => $quote->orderId,
        Quote::find()->quoteStatus(QuoteStatus::Sent->value)->status(null)->all()
    );

    expect($sentOrderIds)->toContain($sentOrder->id)
        ->and($sentOrderIds)->not->toContain($declinedOrder->id);
});

it('reflects a status transition on the element index after mark-sent', function () {
    [$user, $company] = quoteMember();
    $order = quoteCartWithItem();
    insertQuoteRow($order->id, QuoteStatus::Requested->value, $company->id, $user->id);

    Plugin::getInstance()->quotes->markSent($order, null);

    $quote = Quote::find()->orderId($order->id)->status(null)->one();

    expect($quote->getStatus())->toBe(QuoteStatus::Sent->value);
});

it('exposes an All source plus one source per quote status', function () {
    $reflection = new ReflectionMethod(Quote::class, 'defineSources');
    $reflection->setAccessible(true);

    $sources = $reflection->invoke(null, 'index');
    $keys = array_column($sources, 'key');

    expect($keys)->toContain('*')
        ->and($keys)->toContain('status:' . QuoteStatus::Requested->value)
        ->and($keys)->toContain('status:' . QuoteStatus::Sent->value)
        ->and($keys)->toContain('status:' . QuoteStatus::Accepted->value)
        ->and($keys)->toContain('status:' . QuoteStatus::Declined->value)
        ->and($keys)->toContain('status:' . QuoteStatus::Expired->value);
});
