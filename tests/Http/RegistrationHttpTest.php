<?php

use craft\elements\User;
use totalwebcreations\b2bcommerce\elements\Company;
use totalwebcreations\b2bcommerce\Plugin;

it('returns a success response but creates nothing when the honeypot is filled', function () {
    $honeypotField = Plugin::getInstance()->getSettings()->honeypotFieldName;

    $companyName = 'Honeypot Co ' . uniqid();
    $email = 'honeypot_' . uniqid() . '@example.test';

    $client = httpClient();

    $response = postAction($client, 'b2b-commerce/registration/register', [
        'companyName' => $companyName,
        'firstName' => 'Bot',
        'lastName' => 'Spam',
        'email' => $email,
        $honeypotField => 'http://spam.example',
    ]);

    expect($response->getStatusCode())->toBe(200)
        ->and(Company::find()->title($companyName)->status(null)->exists())->toBeFalse()
        ->and(User::find()->email($email)->status(null)->exists())->toBeFalse();
});

it('creates a pending company when the honeypot is empty', function () {
    $companyName = 'Genuine Co ' . uniqid();
    $email = 'genuine_' . uniqid() . '@example.test';

    $client = httpClient();

    $response = postAction($client, 'b2b-commerce/registration/register', [
        'companyName' => $companyName,
        'registrationNumber' => '12345678',
        'taxId' => 'NL123456789B01',
        'firstName' => 'Jane',
        'lastName' => 'Doe',
        'email' => $email,
    ]);

    $company = Company::find()->title($companyName)->status(null)->one();
    $user = User::find()->email($email)->status(null)->one();

    if ($company !== null) {
        trackElement($company);
    }

    if ($user !== null) {
        trackElement($user);
    }

    expect($response->getStatusCode())->toBe(200)
        ->and($company)->not->toBeNull()
        ->and($company->companyStatus)->toBe(Company::STATUS_PENDING)
        ->and($user)->not->toBeNull()
        ->and($user->pending)->toBeTrue();
});
