<?php

use totalwebcreations\b2bcommerce\enums\QuoteOrigin;

it('exposes the two country-neutral origin values', function () {
    expect(QuoteOrigin::Customer->value)->toBe('customer')
        ->and(QuoteOrigin::Merchant->value)->toBe('merchant');
});

it('lists both cases', function () {
    $values = array_map(fn (QuoteOrigin $origin) => $origin->value, QuoteOrigin::cases());

    expect($values)->toBe(['customer', 'merchant']);
});
