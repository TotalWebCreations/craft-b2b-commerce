<?php

it('registers the payment reminder system message', function () {
    $message = craftApp()->getSystemMessages()->getMessage('b2b_payment_reminder');

    expect($message)->not->toBeNull()
        ->and($message->key)->toBe('b2b_payment_reminder');
});
