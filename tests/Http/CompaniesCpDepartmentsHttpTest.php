<?php

use totalwebcreations\b2bcommerce\elements\Company;
use totalwebcreations\b2bcommerce\enums\BudgetPeriod;
use totalwebcreations\b2bcommerce\enums\CompanyRole;
use totalwebcreations\b2bcommerce\Plugin;

it('renders the departments CP page with the create form and existing departments', function () {
    $company = createTestCompany(Company::STATUS_APPROVED, 'CP Http Dept Co');
    Plugin::getInstance()->departments->createDepartment($company, 'Finance', 400.0, BudgetPeriod::Monthly, null);

    [$client] = cpManagerClient();

    $response = $client->get("/admin/b2b/companies/{$company->id}/departments", [
        'headers' => ['Accept' => 'text/html'],
    ]);

    $body = (string) $response->getBody();

    expect($response->getStatusCode())->toBe(200)
        ->and($body)->toContain('Finance')
        ->and($body)->toContain('b2b-commerce/companies-cp/create-department')
        ->and($body)->toContain('name="budgetAmount"');
});

it('creates a department over HTTP as a CP manager', function () {
    $company = createTestCompany(Company::STATUS_APPROVED, 'CP Http Dept Co');

    [$client] = cpManagerClient();

    $response = postCpAction($client, 'b2b-commerce/companies-cp/create-department', [
        'companyId' => $company->id,
        'name' => 'Sales',
        'budgetAmount' => 300.0,
        'budgetPeriod' => 'monthly',
        'approverUserId' => '',
    ]);

    $rows = Plugin::getInstance()->departments->getDepartmentsForCompany($company->id);

    expect($response->getStatusCode())->toBe(302)
        ->and($rows)->toHaveCount(1)
        ->and($rows[0]['name'])->toBe('Sales')
        ->and((float) $rows[0]['budgetAmount'])->toBe(300.0);
});

it('rejects an invalid budget period cleanly over HTTP without creating a department', function () {
    $company = createTestCompany(Company::STATUS_APPROVED, 'CP Http Dept Co');

    [$client] = cpManagerClient();

    $response = postCpAction($client, 'b2b-commerce/companies-cp/create-department', [
        'companyId' => $company->id,
        'name' => 'Sales',
        'budgetAmount' => 300.0,
        'budgetPeriod' => 'weekly',
        'approverUserId' => '',
    ]);

    // Redirects back with a flash error and never writes a row.
    expect($response->getStatusCode())->toBe(302)
        ->and(Plugin::getInstance()->departments->getDepartmentsForCompany($company->id))->toBe([]);
});

it('updates and deletes a department over HTTP', function () {
    $company = createTestCompany(Company::STATUS_APPROVED, 'CP Http Dept Co');
    $id = Plugin::getInstance()->departments->createDepartment($company, 'Sales', 300.0, BudgetPeriod::Monthly, null);

    [$client] = cpManagerClient();

    $updated = postCpAction($client, 'b2b-commerce/companies-cp/update-department', [
        'companyId' => $company->id,
        'departmentId' => $id,
        'name' => 'Sales EU',
        'budgetAmount' => 500.0,
        'budgetPeriod' => 'quarterly',
        'approverUserId' => '',
    ]);

    $row = Plugin::getInstance()->departments->getDepartment($id);

    expect($updated->getStatusCode())->toBe(302)
        ->and($row['name'])->toBe('Sales EU')
        ->and((float) $row['budgetAmount'])->toBe(500.0)
        ->and($row['budgetPeriod'])->toBe('quarterly');

    $deleted = postCpAction($client, 'b2b-commerce/companies-cp/delete-department', [
        'companyId' => $company->id,
        'departmentId' => $id,
    ]);

    expect($deleted->getStatusCode())->toBe(302)
        ->and(Plugin::getInstance()->departments->getDepartment($id))->toBeNull();
});

it('assigns a member to a department over HTTP', function () {
    $company = createTestCompany(Company::STATUS_APPROVED, 'CP Http Dept Co');
    $member = createTestUser('cp_http_deptassign_' . uniqid() . '@example.test');
    Plugin::getInstance()->companyMembers->addUserToCompany($member->id, $company->id, CompanyRole::Purchaser);
    $id = Plugin::getInstance()->departments->createDepartment($company, 'Sales', null, BudgetPeriod::Monthly, null);

    [$client] = cpManagerClient();

    $response = postCpAction($client, 'b2b-commerce/companies-cp/assign-department', [
        'companyId' => $company->id,
        'userId' => $member->id,
        'departmentId' => $id,
    ]);

    expect($response->getStatusCode())->toBe(302)
        ->and(Plugin::getInstance()->departments->getMemberIds($id))->toBe([$member->id]);
});

it('does not assign a non-member to a department over HTTP', function () {
    $company = createTestCompany(Company::STATUS_APPROVED, 'CP Http Dept Co');
    $stranger = createTestUser('cp_http_deptstranger_' . uniqid() . '@example.test');
    $id = Plugin::getInstance()->departments->createDepartment($company, 'Sales', null, BudgetPeriod::Monthly, null);

    [$client] = cpManagerClient();

    $response = postCpAction($client, 'b2b-commerce/companies-cp/assign-department', [
        'companyId' => $company->id,
        'userId' => $stranger->id,
        'departmentId' => $id,
    ]);

    // The service guard refuses; the controller flashes the error and writes nothing.
    expect($response->getStatusCode())->toBe(302)
        ->and(Plugin::getInstance()->departments->getMemberIds($id))->toBe([]);
});
