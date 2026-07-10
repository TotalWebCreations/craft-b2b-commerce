<?php

use totalwebcreations\b2bcommerce\models\Settings;

it('defaults dunning off with the standard offsets', function () {
    $settings = new Settings();

    expect($settings->enableDunning)->toBeFalse()
        ->and($settings->dunningOffsets)->toBe([7, 14, 30]);
});

it('normalises a comma-separated offsets string into sorted unique positive ints', function () {
    $settings = new Settings();
    $settings->dunningOffsets = ' 30, 7, 7, 0, -3, foo , 14 ';

    expect($settings->dunningOffsets)->toBe([7, 14, 30]);
});

it('rejects offsets that are not positive whole numbers', function () {
    $settings = new Settings();
    // Bypass the normaliser to prove the validator guards the array directly.
    $reflection = new ReflectionProperty(Settings::class, 'dunningOffsets');
    $reflection->setValue($settings, [7, 0]);

    $settings->validateDunningOffsets('dunningOffsets');

    expect($settings->hasErrors('dunningOffsets'))->toBeTrue();
});
