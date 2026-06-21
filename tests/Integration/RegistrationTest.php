<?php

use craft\db\Query;
use craft\elements\User;
use totalwebcreations\b2bcommerce\elements\Company;
use totalwebcreations\b2bcommerce\enums\CompanyRole;
use totalwebcreations\b2bcommerce\Plugin;
use yii\base\InvalidArgumentException;

/**
 * Registers a company and tracks both the returned company and the user
 * created for the given email so auto-cleanup removes them afterwards.
 */
function registerAndTrack(string $email): Company
{
    $company = Plugin::getInstance()->registration->register(
        'Registration Co ' . uniqid(),
        '12345678',
        'NL123456789B01',
        'Jane',
        'Doe',
        $email,
    );

    trackElement($company);

    $user = User::find()->email($email)->status(null)->one();

    if ($user !== null) {
        trackElement($user);
    }

    return $company;
}

it('creates a pending company, a pending user and an admin membership', function () {
    $email = 'register_' . uniqid() . '@example.test';

    $company = registerAndTrack($email);

    $user = User::find()->email($email)->status(null)->one();

    $membership = (new Query())
        ->select(['userId', 'role'])
        ->from('{{%b2b_company_users}}')
        ->where(['companyId' => $company->id])
        ->one();

    expect($company->companyStatus)->toBe(Company::STATUS_PENDING)
        ->and($user)->not->toBeNull()
        ->and($user->pending)->toBeTrue()
        ->and($membership)->not->toBeNull()
        ->and((int) $membership['userId'])->toBe($user->id)
        ->and($membership['role'])->toBe(CompanyRole::Admin->value);
});

it('rejects a duplicate email and does not create a second company', function () {
    $email = 'duplicate_' . uniqid() . '@example.test';

    registerAndTrack($email);

    $companyCountBefore = Company::find()->status(null)->count();

    expect(fn () => registerAndTrack($email))
        ->toThrow(InvalidArgumentException::class);

    $companyCountAfter = Company::find()->status(null)->count();

    expect($companyCountAfter)->toBe($companyCountBefore);
});
