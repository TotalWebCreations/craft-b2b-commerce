<?php

use totalwebcreations\b2bcommerce\elements\Company;
use totalwebcreations\b2bcommerce\events\RegisterEvent;
use totalwebcreations\b2bcommerce\models\Settings;
use totalwebcreations\b2bcommerce\modules\companies\services\Registration;
use totalwebcreations\b2bcommerce\Plugin;
use yii\base\Event;
use yii\base\InvalidArgumentException;

it('rejects a honeypot field name that collides with a real registration field', function () {
    $settings = new Settings();
    $settings->honeypotFieldName = 'email';

    expect($settings->validate(['honeypotFieldName']))->toBeFalse()
        ->and($settings->getErrors('honeypotFieldName'))->not->toBeEmpty();
});

it('accepts a honeypot field name that does not collide with a real field', function () {
    $settings = new Settings();
    $settings->honeypotFieldName = 'b2b_website';

    expect($settings->validate(['honeypotFieldName']))->toBeTrue();
});

it('cancels the registration and creates nothing when the event is invalidated', function () {
    $email = 'cancel_' . uniqid() . '@example.test';

    $handler = function (RegisterEvent $event) {
        $event->isValid = false;
    };

    Event::on(Registration::class, Registration::EVENT_BEFORE_REGISTER, $handler);

    $companyCountBefore = Company::find()->status(null)->count();

    try {
        expect(fn () => Plugin::getInstance()->registration->register(
            'Blocked Co ' . uniqid(),
            '12345678',
            'NL123456789B01',
            'Jane',
            'Doe',
            $email,
        ))->toThrow(
            InvalidArgumentException::class,
            Craft::t('b2b-commerce', 'Registration could not be completed.'),
        );
    } finally {
        Event::off(Registration::class, Registration::EVENT_BEFORE_REGISTER, $handler);
    }

    $companyCountAfter = Company::find()->status(null)->count();

    expect($companyCountAfter)->toBe($companyCountBefore);
});

it('passes the submitted registration data to the before-register listener', function () {
    $email = 'listener_' . uniqid() . '@example.test';
    $received = null;

    $handler = function (RegisterEvent $event) use (&$received) {
        $received = [
            'companyName' => $event->companyName,
            'registrationNumber' => $event->registrationNumber,
            'taxId' => $event->taxId,
            'firstName' => $event->firstName,
            'lastName' => $event->lastName,
            'email' => $event->email,
        ];
    };

    Event::on(Registration::class, Registration::EVENT_BEFORE_REGISTER, $handler);

    try {
        $company = Plugin::getInstance()->registration->register(
            'Listener Co',
            '87654321',
            'NL987654321B01',
            'John',
            'Smith',
            $email,
        );

        trackElement($company);

        $user = craft\elements\User::find()->email($email)->status(null)->one();

        if ($user !== null) {
            trackElement($user);
        }
    } finally {
        Event::off(Registration::class, Registration::EVENT_BEFORE_REGISTER, $handler);
    }

    expect($received)->toBe([
        'companyName' => 'Listener Co',
        'registrationNumber' => '87654321',
        'taxId' => 'NL987654321B01',
        'firstName' => 'John',
        'lastName' => 'Smith',
        'email' => $email,
    ]);
});
