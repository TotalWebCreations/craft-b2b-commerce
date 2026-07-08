<?php

use totalwebcreations\b2bcommerce\elements\Quote;
use totalwebcreations\b2bcommerce\enums\QuoteOrigin;
use totalwebcreations\b2bcommerce\enums\QuoteStatus;

// bareQuoteOrder() lives in QuoteMerchantTest.php; createTestCompany() in helpers.php.

it('defaults a saved quote element to the customer origin and reloads it', function () {
    $company = createTestCompany();
    $order = bareQuoteOrder();

    $quote = new Quote();
    $quote->orderId = $order->id;
    $quote->companyId = $company->id;
    $quote->quoteStatus = QuoteStatus::Requested->value;
    $quote->acceptToken = craftApp()->getSecurity()->generateRandomString(40);

    expect(craftApp()->getElements()->saveElement($quote))->toBeTrue();
    trackElement($quote);

    $reloaded = Quote::find()->id($quote->id)->status(null)->one();

    expect($reloaded->origin)->toBe(QuoteOrigin::Customer->value);
});

it('round-trips an explicit merchant origin', function () {
    $company = createTestCompany();
    $order = bareQuoteOrder();

    $quote = new Quote();
    $quote->orderId = $order->id;
    $quote->companyId = $company->id;
    $quote->quoteStatus = QuoteStatus::Requested->value;
    $quote->origin = QuoteOrigin::Merchant->value;
    $quote->acceptToken = craftApp()->getSecurity()->generateRandomString(40);

    expect(craftApp()->getElements()->saveElement($quote))->toBeTrue();
    trackElement($quote);

    $reloaded = Quote::find()->id($quote->id)->status(null)->one();

    expect($reloaded->origin)->toBe(QuoteOrigin::Merchant->value);
});
