<?php

use totalwebcreations\b2bcommerce\enums\CompanyRole;
use totalwebcreations\b2bcommerce\Plugin;
use craft\elements\User;

function memberRole(int $companyId, int $userId): ?CompanyRole
{
    return Plugin::getInstance()->companyMembers->getRoleForUser($userId, $companyId);
}

it('forbids a purchaser from inviting a member', function () {
    $company = createTestCompany();
    $purchaser = createTestUserWithPassword('purchaser_' . uniqid() . '@example.test');
    Plugin::getInstance()->companyMembers->addUserToCompany($purchaser->id, $company->id, CompanyRole::Purchaser);

    $invitedEmail = 'should_not_exist_' . uniqid() . '@example.test';

    $client = httpClient();
    loginAs($client, $purchaser->email, httpTestPassword());

    $response = postAction($client, 'b2b-commerce/team/invite', [
        'email' => $invitedEmail,
        'firstName' => 'No',
        'lastName' => 'Way',
        'role' => CompanyRole::Purchaser->value,
    ]);

    expect($response->getStatusCode())->toBe(403)
        ->and(User::find()->email($invitedEmail)->status(null)->exists())->toBeFalse();
});

it('does not mutate another company when an admin targets a foreign member (IDOR)', function () {
    $companyA = createTestCompany();
    $companyB = createTestCompany();

    $adminA = createTestUserWithPassword('admin_a_' . uniqid() . '@example.test');
    Plugin::getInstance()->companyMembers->addUserToCompany($adminA->id, $companyA->id, CompanyRole::Admin);

    $memberB = createTestUser('member_b_' . uniqid() . '@example.test');
    Plugin::getInstance()->companyMembers->addUserToCompany($memberB->id, $companyB->id, CompanyRole::Purchaser);

    $client = httpClient();
    loginAs($client, $adminA->email, httpTestPassword());

    $response = postAction($client, 'b2b-commerce/team/change-role', [
        'userId' => $memberB->id,
        'role' => CompanyRole::Approver->value,
    ]);

    expect($response->getStatusCode())->toBe(400)
        ->and(memberRole($companyB->id, $memberB->id))->toBe(CompanyRole::Purchaser)
        ->and(memberRole($companyA->id, $memberB->id))->toBeNull();
});

it('rejects an unauthenticated invite without a server error', function () {
    $client = httpClient();

    $response = postAction($client, 'b2b-commerce/team/invite', [
        'email' => 'anon_' . uniqid() . '@example.test',
        'firstName' => 'An',
        'lastName' => 'On',
        'role' => CompanyRole::Purchaser->value,
    ]);

    expect($response->getStatusCode())->toBeIn([302, 403])
        ->and($response->getStatusCode())->not->toBe(500);
});
