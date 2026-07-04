<?php

use totalwebcreations\b2bcommerce\elements\Company;
use totalwebcreations\b2bcommerce\enums\CompanyRole;
use totalwebcreations\b2bcommerce\Plugin;
use yii\base\InvalidArgumentException;

/*
|--------------------------------------------------------------------------
| CP contact-person management
|--------------------------------------------------------------------------
|
| The CP controller (CompaniesCpController) is a thin wrapper: it resolves the
| company from the posted id, maps the raw role string with CompanyRole::tryFrom,
| and delegates to the CompanyMembers service so every guard is reused. These
| tests drive that exact path — company lookup by id + role parsing + service
| call — so the substance the CP actions rely on is asserted end to end. The full
| request pipeline (permission, CSRF, redirect) is covered by the Http suite.
|
*/

function cpMembers(): totalwebcreations\b2bcommerce\modules\companies\services\CompanyMembers
{
    return Plugin::getInstance()->companyMembers;
}

function cpRole(int $companyId, int $userId): ?CompanyRole
{
    return cpMembers()->getRoleForUser($userId, $companyId);
}

it('adds a new contact person as a pending user with a membership through the CP path', function () {
    $company = createTestCompany(Company::STATUS_APPROVED, 'CP Members Co');
    $email = 'cp_new_' . uniqid() . '@example.test';

    $role = CompanyRole::tryFrom('purchaser');
    $user = cpMembers()->inviteMember(cpMembers()->getCompanyById($company->id), $email, 'New', 'Person', $role);

    trackElement($user);

    expect($user->pending)->toBeTrue()
        ->and($user->email)->toBe($email)
        ->and(cpRole($company->id, $user->id))->toBe(CompanyRole::Purchaser);
});

it('adds an existing free user as a contact person through the CP path', function () {
    $company = createTestCompany(Company::STATUS_APPROVED, 'CP Members Co');
    $existing = createTestUser('cp_free_' . uniqid() . '@example.test');

    $user = cpMembers()->inviteMember(
        cpMembers()->getCompanyById($company->id),
        $existing->email,
        'Free',
        'User',
        CompanyRole::Approver,
    );

    expect($user->id)->toBe($existing->id)
        ->and(cpRole($company->id, $existing->id))->toBe(CompanyRole::Approver);
});

it('fails cleanly when adding someone who already belongs to another company', function () {
    $companyA = createTestCompany(Company::STATUS_APPROVED, 'CP Members Co');
    $companyB = createTestCompany(Company::STATUS_APPROVED, 'CP Members Co');
    $existing = createTestUser('cp_taken_' . uniqid() . '@example.test');
    cpMembers()->addUserToCompany($existing->id, $companyA->id, CompanyRole::Purchaser);

    expect(fn () => cpMembers()->inviteMember(
        cpMembers()->getCompanyById($companyB->id),
        $existing->email,
        'Taken',
        'User',
        CompanyRole::Purchaser,
    ))->toThrow(InvalidArgumentException::class, 'This person already belongs to a company.');
});

it('changes a contact person role through the CP path', function () {
    $company = createTestCompany(Company::STATUS_APPROVED, 'CP Members Co');
    $admin = createTestUser('cp_admin_' . uniqid() . '@example.test');
    $member = createTestUser('cp_member_' . uniqid() . '@example.test');
    cpMembers()->addUserToCompany($admin->id, $company->id, CompanyRole::Admin);
    cpMembers()->addUserToCompany($member->id, $company->id, CompanyRole::Purchaser);

    cpMembers()->changeRole(cpMembers()->getCompanyById($company->id), $member->id, CompanyRole::Approver);

    expect(cpRole($company->id, $member->id))->toBe(CompanyRole::Approver);
});

it('removes a contact person through the CP path', function () {
    $company = createTestCompany(Company::STATUS_APPROVED, 'CP Members Co');
    $admin = createTestUser('cp_keep_' . uniqid() . '@example.test');
    $member = createTestUser('cp_drop_' . uniqid() . '@example.test');
    cpMembers()->addUserToCompany($admin->id, $company->id, CompanyRole::Admin);
    cpMembers()->addUserToCompany($member->id, $company->id, CompanyRole::Purchaser);

    cpMembers()->removeMember(cpMembers()->getCompanyById($company->id), $member->id);

    expect(cpRole($company->id, $member->id))->toBeNull();
});

it('refuses to remove the last admin through the CP path', function () {
    $company = createTestCompany(Company::STATUS_APPROVED, 'CP Members Co');
    $admin = createTestUser('cp_last_admin_' . uniqid() . '@example.test');
    cpMembers()->addUserToCompany($admin->id, $company->id, CompanyRole::Admin);

    expect(fn () => cpMembers()->removeMember(cpMembers()->getCompanyById($company->id), $admin->id))
        ->toThrow(InvalidArgumentException::class, 'A company must keep at least one admin.');

    expect(cpRole($company->id, $admin->id))->toBe(CompanyRole::Admin);
});
