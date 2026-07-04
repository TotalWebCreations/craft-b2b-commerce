<?php

use craft\elements\User;
use totalwebcreations\b2bcommerce\elements\Company;
use totalwebcreations\b2bcommerce\enums\CompanyRole;
use totalwebcreations\b2bcommerce\Plugin;

it('renders the add contact person form and role selects on the CP members page', function () {
    $company = createTestCompany(Company::STATUS_APPROVED, 'CP Http Co');
    $member = createTestUser('cp_http_member_' . uniqid() . '@example.test');
    Plugin::getInstance()->companyMembers->addUserToCompany($member->id, $company->id, CompanyRole::Admin);

    [$client] = cpManagerClient();

    $response = $client->get("/admin/b2b/companies/{$company->id}/members", [
        'headers' => ['Accept' => 'text/html'],
    ]);

    $body = (string) $response->getBody();

    expect($response->getStatusCode())->toBe(200)
        ->and($body)->toContain('b2b-commerce/companies-cp/add-member')
        ->and($body)->toContain('b2b-commerce/companies-cp/change-member-role')
        ->and($body)->toContain('name="role"');
});

it('adds a new contact person over HTTP as a CP manager, creating a pending user and membership', function () {
    $company = createTestCompany(Company::STATUS_APPROVED, 'CP Http Co');
    $email = 'cp_http_new_' . uniqid() . '@example.test';

    [$client] = cpManagerClient();

    $response = postCpAction($client, 'b2b-commerce/companies-cp/add-member', [
        'companyId' => $company->id,
        'firstName' => 'New',
        'lastName' => 'Contact',
        'email' => $email,
        'role' => CompanyRole::Purchaser->value,
    ]);

    $invited = User::find()->email($email)->status(null)->one();

    // Track for cleanup: the FK on b2b_company_users cascades on userId, so deleting
    // the user also removes the membership row created by the invite.
    if ($invited !== null) {
        trackElement($invited);
    }

    expect($response->getStatusCode())->toBe(302)
        ->and($invited)->not->toBeNull()
        ->and($invited->pending)->toBeTrue()
        ->and(Plugin::getInstance()->companyMembers->getRoleForUser($invited->id, $company->id))
        ->toBe(CompanyRole::Purchaser);
});
