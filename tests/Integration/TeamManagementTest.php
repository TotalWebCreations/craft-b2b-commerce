<?php

use Craft;
use craft\db\Query;
use craft\elements\User;
use totalwebcreations\b2bcommerce\console\controllers\TeamController;
use totalwebcreations\b2bcommerce\elements\Company;
use totalwebcreations\b2bcommerce\enums\CompanyRole;
use totalwebcreations\b2bcommerce\Plugin;
use yii\base\InvalidArgumentException;
use yii\console\ExitCode;

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

it('sends an activation mail, not a member-added mail, when inviting an existing pending user', function () {
    $company = approvedCompany();
    $email = 'invite_pending_existing_' . uniqid() . '@example.test';

    $pending = new User();
    $pending->email = $email;
    $pending->username = 'pending_' . uniqid();
    $pending->pending = true;

    if (!craftApp()->getElements()->saveElement($pending)) {
        throw new RuntimeException('Could not save pending user: ' . implode(', ', $pending->getFirstErrors()));
    }

    trackElement($pending);

    $mailBefore = mailCount();

    $user = Plugin::getInstance()->companyMembers->inviteMember(
        $company,
        $email,
        'Pending',
        'Existing',
        CompanyRole::Purchaser,
    );

    expect($user->id)->toBe($pending->id)
        ->and(membershipRole($company->id, $pending->id))->toBe(CompanyRole::Purchaser->value)
        ->and(mailCount())->toBeGreaterThan($mailBefore)
        ->and(newestMailBody())->not->toContain('added to a business account');
});

it('finds an existing free user case-insensitively when inviting', function () {
    $company = approvedCompany();
    $suffix = uniqid();
    $existing = createTestUser("free_{$suffix}@example.test");

    $user = Plugin::getInstance()->companyMembers->inviteMember(
        $company,
        "FREE_{$suffix}@EXAMPLE.test",
        'Free',
        'User',
        CompanyRole::Approver,
    );

    expect($user->id)->toBe($existing->id)
        ->and(membershipRole($company->id, $existing->id))->toBe(CompanyRole::Approver->value);
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
    ))->toThrow(InvalidArgumentException::class, 'This person already belongs to a company.');
});

it('rejects inviting an email that already belongs to the same company', function () {
    $company = approvedCompany();
    $email = 'invite_same_' . uniqid() . '@example.test';
    $existing = createTestUser($email);

    Plugin::getInstance()->companyMembers->addUserToCompany($existing->id, $company->id, CompanyRole::Purchaser);

    expect(fn () => Plugin::getInstance()->companyMembers->inviteMember(
        $company,
        $email,
        'Same',
        'User',
        CompanyRole::Approver,
    ))->toThrow(InvalidArgumentException::class, 'This person already belongs to a company.');
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
    ))->toThrow(InvalidArgumentException::class, 'Only approved companies can invite members.');
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

it('promotes a purchaser to admin even when there is only one admin', function () {
    $company = approvedCompany();
    $admin = createTestUser('promote_admin_' . uniqid() . '@example.test');
    $member = createTestUser('promote_member_' . uniqid() . '@example.test');
    Plugin::getInstance()->companyMembers->addUserToCompany($admin->id, $company->id, CompanyRole::Admin);
    Plugin::getInstance()->companyMembers->addUserToCompany($member->id, $company->id, CompanyRole::Purchaser);

    Plugin::getInstance()->companyMembers->changeRole($company, $member->id, CompanyRole::Admin);

    expect(membershipRole($company->id, $member->id))->toBe(CompanyRole::Admin->value);
});

it('refuses to demote the last admin', function () {
    $company = approvedCompany();
    $admin = createTestUser('last_admin_' . uniqid() . '@example.test');
    Plugin::getInstance()->companyMembers->addUserToCompany($admin->id, $company->id, CompanyRole::Admin);

    expect(fn () => Plugin::getInstance()->companyMembers->changeRole($company, $admin->id, CompanyRole::Purchaser))
        ->toThrow(InvalidArgumentException::class, 'A company must keep at least one admin.');

    expect(membershipRole($company->id, $admin->id))->toBe(CompanyRole::Admin->value);
});

it('refuses to remove the last admin', function () {
    $company = approvedCompany();
    $admin = createTestUser('remove_admin_' . uniqid() . '@example.test');
    Plugin::getInstance()->companyMembers->addUserToCompany($admin->id, $company->id, CompanyRole::Admin);

    expect(fn () => Plugin::getInstance()->companyMembers->removeMember($company, $admin->id))
        ->toThrow(InvalidArgumentException::class, 'A company must keep at least one admin.');

    expect(membershipRole($company->id, $admin->id))->toBe(CompanyRole::Admin->value);
});

it('refuses to remove a user who is not a member', function () {
    $company = approvedCompany();
    $stranger = createTestUser('stranger_' . uniqid() . '@example.test');

    expect(fn () => Plugin::getInstance()->companyMembers->removeMember($company, $stranger->id))
        ->toThrow(InvalidArgumentException::class, 'This user is not a member of this company.');
});

it('moves a user cleanly to a new company on a forced console reassign, leaving no old membership', function () {
    $companyA = approvedCompany();
    $companyB = approvedCompany();
    $email = 'reassign_' . uniqid() . '@example.test';
    $user = createTestUser($email);

    Plugin::getInstance()->companyMembers->addUserToCompany($user->id, $companyA->id, CompanyRole::Purchaser);

    $controller = new TeamController('team', Craft::$app);
    $controller->force = true;

    $exitCode = $controller->actionAssignRole($companyB->id, $email, CompanyRole::Admin->value);

    // The forced reassign is a move, not a copy: the old membership is gone, the new one carries the
    // requested role, and getCompanyForUser resolves unambiguously to the new company rather than to
    // whichever row happened to have the lowest id.
    expect($exitCode)->toBe(ExitCode::OK)
        ->and(membershipRole($companyA->id, $user->id))->toBeNull()
        ->and(membershipRole($companyB->id, $user->id))->toBe(CompanyRole::Admin->value)
        ->and(Plugin::getInstance()->companyMembers->getCompanyForUser($user->id)?->id)->toBe($companyB->id);
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
