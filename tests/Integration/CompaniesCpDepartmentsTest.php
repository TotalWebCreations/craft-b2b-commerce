<?php

use totalwebcreations\b2bcommerce\elements\Company;
use totalwebcreations\b2bcommerce\enums\BudgetPeriod;
use totalwebcreations\b2bcommerce\enums\CompanyRole;
use totalwebcreations\b2bcommerce\Plugin;

// createTestCompany / createTestUser live in helpers.php (loaded globally).

// Rendering the CP departments template through a full HTTP round trip (rather than a raw
// renderTemplate() call under the console-booted test app) is covered in
// tests/Http/CompaniesCpDepartmentsHttpTest.php, mirroring CompaniesCpMembersHttpTest.php: the
// `_layouts/cp` layout this template extends calls craft.app.request.getBodyParam(), a
// craft\web\Request-only method that a console\Request (the app class the Integration suite
// boots) does not implement, so a direct renderTemplate() call here would fail for reasons
// unrelated to the template itself.

it('creates a department through the CP service and lists it for the company', function () {
    $company = createTestCompany(Company::STATUS_APPROVED, 'CP Dept Co');

    $id = Plugin::getInstance()->departments->createDepartment($company, 'Finance', 400.0, BudgetPeriod::Monthly, null);
    $rows = Plugin::getInstance()->departments->getDepartmentsForCompany($company->id);

    expect($rows)->toHaveCount(1)
        ->and((int) $rows[0]['id'])->toBe($id)
        ->and($rows[0]['name'])->toBe('Finance');
});

it('assigns a member and reflects it in the department member list', function () {
    $company = createTestCompany(Company::STATUS_APPROVED, 'CP Dept Co');
    $user = createTestUser('cpdept_' . uniqid() . '@example.test');
    Plugin::getInstance()->companyMembers->addUserToCompany($user->id, $company->id, CompanyRole::Purchaser);
    $id = Plugin::getInstance()->departments->createDepartment($company, 'Finance', null, BudgetPeriod::Monthly, null);

    Plugin::getInstance()->departments->assignMember($company, $user->id, $id);

    expect(Plugin::getInstance()->departments->getMemberIds($id))->toBe([$user->id]);
});
