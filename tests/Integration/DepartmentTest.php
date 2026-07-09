<?php

use Craft;
use totalwebcreations\b2bcommerce\elements\Company;
use totalwebcreations\b2bcommerce\enums\BudgetPeriod;
use totalwebcreations\b2bcommerce\enums\CompanyRole;
use totalwebcreations\b2bcommerce\Plugin;
use yii\base\InvalidArgumentException;

it('has created the b2b_departments table and departmentId column', function () {
    $db = Craft::$app->getDb();

    expect($db->tableExists('{{%b2b_departments}}'))->toBeTrue()
        ->and($db->columnExists('{{%b2b_departments}}', 'budgetAmount'))->toBeTrue()
        ->and($db->columnExists('{{%b2b_departments}}', 'budgetPeriod'))->toBeTrue()
        ->and($db->columnExists('{{%b2b_departments}}', 'approverUserId'))->toBeTrue()
        ->and($db->columnExists('{{%b2b_company_users}}', 'departmentId'))->toBeTrue();
});

// createTestCompany / createTestUser live in helpers.php (loaded globally by the suite).

/**
 * An approved company with one admin member.
 *
 * @return array{0: \craft\elements\User, 1: Company}
 */
function deptMember(): array
{
    $company = createTestCompany(Company::STATUS_APPROVED, 'Dept Co');
    $user = createTestUser('dept_' . uniqid() . '@example.test');
    Plugin::getInstance()->companyMembers->addUserToCompany($user->id, $company->id, CompanyRole::Admin);

    return [$user, $company];
}

it('creates and reads back a department', function () {
    [, $company] = deptMember();

    $id = Plugin::getInstance()->departments->createDepartment($company, 'Marketing', 500.0, BudgetPeriod::Monthly, null);
    $row = Plugin::getInstance()->departments->getDepartment($id);

    expect($row)->not->toBeNull()
        ->and($row['name'])->toBe('Marketing')
        ->and((float) $row['budgetAmount'])->toBe(500.0)
        ->and($row['budgetPeriod'])->toBe('monthly')
        ->and((int) $row['companyId'])->toBe($company->id);
});

it('creates a department with an unlimited (null) budget', function () {
    [, $company] = deptMember();

    $id = Plugin::getInstance()->departments->createDepartment($company, 'Ops', null, BudgetPeriod::Monthly, null);

    expect(Plugin::getInstance()->departments->getDepartment($id)['budgetAmount'])->toBeNull();
});

it('updates a department', function () {
    [, $company] = deptMember();
    $id = Plugin::getInstance()->departments->createDepartment($company, 'Sales', 100.0, BudgetPeriod::Monthly, null);

    Plugin::getInstance()->departments->updateDepartment($id, 'Sales EU', 250.0, BudgetPeriod::Quarterly, null);
    $row = Plugin::getInstance()->departments->getDepartment($id);

    expect($row['name'])->toBe('Sales EU')
        ->and((float) $row['budgetAmount'])->toBe(250.0)
        ->and($row['budgetPeriod'])->toBe('quarterly');
});

it('lists departments for a company ordered by name', function () {
    [, $company] = deptMember();
    Plugin::getInstance()->departments->createDepartment($company, 'Zeta', null, BudgetPeriod::Monthly, null);
    Plugin::getInstance()->departments->createDepartment($company, 'Alpha', null, BudgetPeriod::Monthly, null);

    $names = array_map(fn (array $r): string => $r['name'], Plugin::getInstance()->departments->getDepartmentsForCompany($company->id));

    expect($names)->toBe(['Alpha', 'Zeta']);
});

it('assigns a member to a department and resolves it back', function () {
    [$user, $company] = deptMember();
    $id = Plugin::getInstance()->departments->createDepartment($company, 'Marketing', null, BudgetPeriod::Monthly, null);

    Plugin::getInstance()->departments->assignMember($company, $user->id, $id);
    $resolved = Plugin::getInstance()->departments->getDepartmentForUser($user->id);

    expect((int) $resolved['id'])->toBe($id)
        ->and(Plugin::getInstance()->departments->getMemberIds($id))->toBe([$user->id]);
});

it('unassigns a member when given a null department', function () {
    [$user, $company] = deptMember();
    $id = Plugin::getInstance()->departments->createDepartment($company, 'Marketing', null, BudgetPeriod::Monthly, null);
    Plugin::getInstance()->departments->assignMember($company, $user->id, $id);

    Plugin::getInstance()->departments->assignMember($company, $user->id, null);

    expect(Plugin::getInstance()->departments->getDepartmentForUser($user->id))->toBeNull();
});

it('refuses to assign a non-member to a department', function () {
    [, $company] = deptMember();
    $id = Plugin::getInstance()->departments->createDepartment($company, 'Marketing', null, BudgetPeriod::Monthly, null);
    $stranger = createTestUser('deptstranger_' . uniqid() . '@example.test');

    expect(fn () => Plugin::getInstance()->departments->assignMember($company, $stranger->id, $id))
        ->toThrow(InvalidArgumentException::class);
});

it('refuses to assign a member to a department of another company', function () {
    [$user, $company] = deptMember();
    [, $otherCompany] = deptMember();
    $foreign = Plugin::getInstance()->departments->createDepartment($otherCompany, 'Foreign', null, BudgetPeriod::Monthly, null);

    expect(fn () => Plugin::getInstance()->departments->assignMember($company, $user->id, $foreign))
        ->toThrow(InvalidArgumentException::class);
});

it('nulls member assignment when a department is deleted (SET NULL, no orphan)', function () {
    [$user, $company] = deptMember();
    $id = Plugin::getInstance()->departments->createDepartment($company, 'Marketing', null, BudgetPeriod::Monthly, null);
    Plugin::getInstance()->departments->assignMember($company, $user->id, $id);

    Plugin::getInstance()->departments->deleteDepartment($id);

    expect(Plugin::getInstance()->departments->getDepartment($id))->toBeNull()
        ->and(Plugin::getInstance()->departments->getDepartmentForUser($user->id))->toBeNull();
});

it('returns the department approver plus approver-role members', function () {
    [$admin, $company] = deptMember();
    $approver = createTestUser('deptappr_' . uniqid() . '@example.test');
    Plugin::getInstance()->companyMembers->addUserToCompany($approver->id, $company->id, CompanyRole::Approver);
    $purchaser = createTestUser('deptpur_' . uniqid() . '@example.test');
    Plugin::getInstance()->companyMembers->addUserToCompany($purchaser->id, $company->id, CompanyRole::Purchaser);

    $id = Plugin::getInstance()->departments->createDepartment($company, 'Sales', null, BudgetPeriod::Monthly, $approver->id);
    Plugin::getInstance()->departments->assignMember($company, $admin->id, $id);       // admin: canApproveOrders
    Plugin::getInstance()->departments->assignMember($company, $approver->id, $id);    // approver: canApproveOrders
    Plugin::getInstance()->departments->assignMember($company, $purchaser->id, $id);   // purchaser: cannot approve

    $ids = Plugin::getInstance()->departments->eligibleApproversForUser($admin);

    $expected = [$admin->id, $approver->id];
    sort($expected);

    expect($ids)->toBe($expected)
        ->and($ids)->not->toContain($purchaser->id);
});

it('returns an empty approver list for a member with no department (company-level fallback)', function () {
    [$admin] = deptMember();

    expect(Plugin::getInstance()->departments->eligibleApproversForUser($admin))->toBe([]);
});

it('includes an out-of-department approverUserId as the preferred approver', function () {
    [$admin, $company] = deptMember();
    $externalApprover = createTestUser('deptext_' . uniqid() . '@example.test');
    Plugin::getInstance()->companyMembers->addUserToCompany($externalApprover->id, $company->id, CompanyRole::Approver);

    $id = Plugin::getInstance()->departments->createDepartment($company, 'Sales', null, BudgetPeriod::Monthly, $externalApprover->id);
    Plugin::getInstance()->departments->assignMember($company, $admin->id, $id); // admin also can approve

    $ids = Plugin::getInstance()->departments->eligibleApproversForUser($admin);

    expect($ids)->toContain($externalApprover->id)
        ->and($ids)->toContain($admin->id);
});
