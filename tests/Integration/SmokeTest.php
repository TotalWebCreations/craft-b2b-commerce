<?php

use craft\console\Application;
use craft\elements\User;
use totalwebcreations\b2bcommerce\elements\Company;

it('boots the dev Craft application', function () {
    expect(craftApp())->toBeInstanceOf(Application::class);
});

it('creates an approved company that is findable by status', function () {
    $company = createTestCompany('approved');

    $exists = Company::find()
        ->companyStatus('approved')
        ->id($company->id)
        ->exists();

    expect($company->id)->toBeInt()
        ->and($exists)->toBeTrue();
});

it('creates an active user', function () {
    $user = createTestUser('smoke_' . uniqid() . '@example.test');

    expect($user->id)->toBeInt()
        ->and($user->active)->toBeTrue()
        ->and(User::find()->id($user->id)->status(null)->exists())->toBeTrue();
});

it('hard-deletes tracked elements on cleanup', function () {
    $company = createTestCompany('pending');
    $id = $company->id;

    expect(Company::find()->id($id)->status(null)->exists())->toBeTrue();

    deleteTrackedElements();

    expect(Company::find()->id($id)->status(null)->trashed(null)->exists())->toBeFalse();
});
