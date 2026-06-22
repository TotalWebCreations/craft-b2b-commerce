<?php

use craft\db\Query;
use craft\elements\User;
use totalwebcreations\b2bcommerce\elements\Company;
use totalwebcreations\b2bcommerce\enums\CompanyRole;
use totalwebcreations\b2bcommerce\Plugin;
use yii\base\InvalidArgumentException;

/**
 * Absolute path to the file-transport mailbox of the dev site.
 */
function mailDir(): string
{
    return dirname(__DIR__, 3) . '/b2b-dev/storage/runtime/mail';
}

/**
 * Number of .eml files currently sitting in the dev-site mailbox.
 */
function mailCount(): int
{
    return count(glob(mailDir() . '/*.eml') ?: []);
}

/**
 * Reads the current role of a user within a company straight from the table.
 */
function membershipRole(int $companyId, int $userId): ?string
{
    $role = (new Query())
        ->select('role')
        ->from('{{%b2b_company_users}}')
        ->where(['companyId' => $companyId, 'userId' => $userId])
        ->scalar();

    return $role ?: null;
}

/**
 * Creates a tracked, approved company that can invite members.
 */
function approvedCompany(): Company
{
    return createTestCompany(Company::STATUS_APPROVED, 'Team Co');
}

/**
 * Tracks the user the invite created (if any) so auto-cleanup removes it.
 */
function trackInvited(?User $user): void
{
    if ($user !== null) {
        trackElement($user);
    }
}

it('invites an unknown email as a pending user with a membership and an activation mail', function () {
    $company = approvedCompany();
    $email = 'invite_new_' . uniqid() . '@example.test';

    $mailBefore = mailCount();

    $user = Plugin::getInstance()->companyMembers->inviteMember(
        $company,
        $email,
        'New',
        'Member',
        CompanyRole::Purchaser,
    );

    trackInvited($user);

    expect($user->pending)->toBeTrue()
        ->and($user->email)->toBe($email)
        ->and($user->username)->toBe($email)
        ->and(membershipRole($company->id, $user->id))->toBe(CompanyRole::Purchaser->value)
        ->and(mailCount())->toBeGreaterThan($mailBefore);
});

it('invites an existing free user by adding a membership', function () {
    $company = approvedCompany();
    $email = 'invite_free_' . uniqid() . '@example.test';
    $existing = createTestUser($email);

    $user = Plugin::getInstance()->companyMembers->inviteMember(
        $company,
        $email,
        'Free',
        'User',
        CompanyRole::Approver,
    );

    expect($user->id)->toBe($existing->id)
        ->and(membershipRole($company->id, $existing->id))->toBe(CompanyRole::Approver->value);
});

it('rejects inviting a user who already belongs to a company', function () {
    $companyA = approvedCompany();
    $companyB = approvedCompany();
    $email = 'invite_taken_' . uniqid() . '@example.test';
    $existing = createTestUser($email);

    Plugin::getInstance()->companyMembers->addUserToCompany($existing->id, $companyA->id, CompanyRole::Purchaser);

    expect(fn () => Plugin::getInstance()->companyMembers->inviteMember(
        $companyB,
        $email,
        'Taken',
        'User',
        CompanyRole::Purchaser,
    ))->toThrow(InvalidArgumentException::class);
});

it('rejects inviting on a company that is not approved', function () {
    $company = createTestCompany(Company::STATUS_PENDING, 'Pending Co');
    $email = 'invite_pending_' . uniqid() . '@example.test';

    expect(fn () => Plugin::getInstance()->companyMembers->inviteMember(
        $company,
        $email,
        'Nope',
        'User',
        CompanyRole::Purchaser,
    ))->toThrow(InvalidArgumentException::class);
});

it('changes the role of a member', function () {
    $company = approvedCompany();
    $admin = createTestUser('team_admin_' . uniqid() . '@example.test');
    $member = createTestUser('team_member_' . uniqid() . '@example.test');
    Plugin::getInstance()->companyMembers->addUserToCompany($admin->id, $company->id, CompanyRole::Admin);
    Plugin::getInstance()->companyMembers->addUserToCompany($member->id, $company->id, CompanyRole::Purchaser);

    Plugin::getInstance()->companyMembers->changeRole($company, $member->id, CompanyRole::Approver);

    expect(membershipRole($company->id, $member->id))->toBe(CompanyRole::Approver->value);
});

it('refuses to demote the last admin', function () {
    $company = approvedCompany();
    $admin = createTestUser('last_admin_' . uniqid() . '@example.test');
    Plugin::getInstance()->companyMembers->addUserToCompany($admin->id, $company->id, CompanyRole::Admin);

    expect(fn () => Plugin::getInstance()->companyMembers->changeRole($company, $admin->id, CompanyRole::Purchaser))
        ->toThrow(InvalidArgumentException::class);

    expect(membershipRole($company->id, $admin->id))->toBe(CompanyRole::Admin->value);
});

it('refuses to remove the last admin', function () {
    $company = approvedCompany();
    $admin = createTestUser('remove_admin_' . uniqid() . '@example.test');
    Plugin::getInstance()->companyMembers->addUserToCompany($admin->id, $company->id, CompanyRole::Admin);

    expect(fn () => Plugin::getInstance()->companyMembers->removeMember($company, $admin->id))
        ->toThrow(InvalidArgumentException::class);

    expect(membershipRole($company->id, $admin->id))->toBe(CompanyRole::Admin->value);
});

it('refuses to remove a user who is not a member', function () {
    $company = approvedCompany();
    $stranger = createTestUser('stranger_' . uniqid() . '@example.test');

    expect(fn () => Plugin::getInstance()->companyMembers->removeMember($company, $stranger->id))
        ->toThrow(InvalidArgumentException::class);
});

it('removes a member and deletes the membership row', function () {
    $company = approvedCompany();
    $admin = createTestUser('keep_admin_' . uniqid() . '@example.test');
    $member = createTestUser('drop_member_' . uniqid() . '@example.test');
    Plugin::getInstance()->companyMembers->addUserToCompany($admin->id, $company->id, CompanyRole::Admin);
    Plugin::getInstance()->companyMembers->addUserToCompany($member->id, $company->id, CompanyRole::Purchaser);

    Plugin::getInstance()->companyMembers->removeMember($company, $member->id);

    expect(membershipRole($company->id, $member->id))->toBeNull();
});
