<?php

use craft\elements\User;
use totalwebcreations\b2bcommerce\elements\Company;
use totalwebcreations\b2bcommerce\enums\BudgetPeriod;
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

it('renders the budget controls on the CP members page', function () {
    $company = createTestCompany(Company::STATUS_APPROVED, 'CP Http Co');
    $member = createTestUser('cp_http_budget_' . uniqid() . '@example.test');
    Plugin::getInstance()->companyMembers->addUserToCompany($member->id, $company->id, CompanyRole::Admin);

    [$client] = cpManagerClient();

    $response = $client->get("/admin/b2b/companies/{$company->id}/members", [
        'headers' => ['Accept' => 'text/html'],
    ]);

    $body = (string) $response->getBody();

    expect($response->getStatusCode())->toBe(200)
        ->and($body)->toContain('b2b-commerce/companies-cp/set-budget')
        ->and($body)->toContain('name="amount"')
        ->and($body)->toContain('name="period"');
});

it('sets and updates a member budget over HTTP as a CP manager', function () {
    $company = createTestCompany(Company::STATUS_APPROVED, 'CP Http Co');
    $member = createTestUser('cp_http_setbudget_' . uniqid() . '@example.test');
    Plugin::getInstance()->companyMembers->addUserToCompany($member->id, $company->id, CompanyRole::Admin);

    [$client] = cpManagerClient();

    $created = postCpAction($client, 'b2b-commerce/companies-cp/set-budget', [
        'companyId' => $company->id,
        'userId' => $member->id,
        'amount' => 250.0,
        'period' => 'monthly',
    ]);

    $budget = Plugin::getInstance()->budgets->getBudget($company->id, $member->id);

    expect($created->getStatusCode())->toBe(302)
        ->and($budget)->not->toBeNull()
        ->and((float) $budget['amount'])->toBe(250.0)
        ->and($budget['period'])->toBe('monthly');

    // A second set replaces the row (upsert) rather than adding a duplicate.
    $updated = postCpAction($client, 'b2b-commerce/companies-cp/set-budget', [
        'companyId' => $company->id,
        'userId' => $member->id,
        'amount' => 500.0,
        'period' => 'yearly',
    ]);

    $budget = Plugin::getInstance()->budgets->getBudget($company->id, $member->id);

    expect($updated->getStatusCode())->toBe(302)
        ->and((float) $budget['amount'])->toBe(500.0)
        ->and($budget['period'])->toBe('yearly');
});

it('rejects an invalid budget period cleanly over HTTP', function () {
    $company = createTestCompany(Company::STATUS_APPROVED, 'CP Http Co');
    $member = createTestUser('cp_http_badperiod_' . uniqid() . '@example.test');
    Plugin::getInstance()->companyMembers->addUserToCompany($member->id, $company->id, CompanyRole::Admin);

    [$client] = cpManagerClient();

    $response = postCpAction($client, 'b2b-commerce/companies-cp/set-budget', [
        'companyId' => $company->id,
        'userId' => $member->id,
        'amount' => 100.0,
        'period' => 'weekly',
    ]);

    // Redirects back with a flash error and never writes a row.
    expect($response->getStatusCode())->toBe(302)
        ->and(Plugin::getInstance()->budgets->getBudget($company->id, $member->id))->toBeNull();
});

it('removes a member budget over HTTP', function () {
    $company = createTestCompany(Company::STATUS_APPROVED, 'CP Http Co');
    $member = createTestUser('cp_http_rmbudget_' . uniqid() . '@example.test');
    Plugin::getInstance()->companyMembers->addUserToCompany($member->id, $company->id, CompanyRole::Admin);
    Plugin::getInstance()->budgets->setBudget(
        Plugin::getInstance()->companyMembers->getCompanyById($company->id),
        $member->id,
        100.0,
        BudgetPeriod::Monthly,
    );

    [$client] = cpManagerClient();

    $response = postCpAction($client, 'b2b-commerce/companies-cp/remove-budget', [
        'companyId' => $company->id,
        'userId' => $member->id,
    ]);

    expect($response->getStatusCode())->toBe(302)
        ->and(Plugin::getInstance()->budgets->getBudget($company->id, $member->id))->toBeNull();
});

it('does not set a budget for a non-member over HTTP', function () {
    $company = createTestCompany(Company::STATUS_APPROVED, 'CP Http Co');
    $stranger = createTestUser('cp_http_stranger_' . uniqid() . '@example.test');

    [$client] = cpManagerClient();

    $response = postCpAction($client, 'b2b-commerce/companies-cp/set-budget', [
        'companyId' => $company->id,
        'userId' => $stranger->id,
        'amount' => 100.0,
        'period' => 'monthly',
    ]);

    // The service guard refuses; the controller flashes the error and writes nothing.
    expect($response->getStatusCode())->toBe(302)
        ->and(Plugin::getInstance()->budgets->getBudget($company->id, $stranger->id))->toBeNull();
});
