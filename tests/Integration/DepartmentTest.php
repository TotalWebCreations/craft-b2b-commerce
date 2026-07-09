<?php

use Craft;
use craft\commerce\elements\Order;
use DateTimeImmutable;
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

/**
 * Invokes the phase-18 private seam Approvals::departmentScopedApproverIds through reflection — it is
 * private by design (an internal routing seam), so the wiring is asserted directly here rather than
 * driving a full approval-ladder scenario.
 */
function callDepartmentScopedApproverIds(int $companyId, int $requesterId): ?array
{
    $method = new ReflectionMethod(
        \totalwebcreations\b2bcommerce\modules\approvals\services\Approvals::class,
        'departmentScopedApproverIds'
    );
    $method->setAccessible(true);

    return $method->invoke(Plugin::getInstance()->approvals, $companyId, $requesterId);
}

it('routes a requester in a department to that department\'s approvers', function () {
    [$admin, $company] = deptMember();
    $approver = createTestUser('deptwire_appr_' . uniqid() . '@example.test');
    Plugin::getInstance()->companyMembers->addUserToCompany($approver->id, $company->id, CompanyRole::Approver);

    $id = Plugin::getInstance()->departments->createDepartment($company, 'Sales', null, BudgetPeriod::Monthly, $approver->id);
    Plugin::getInstance()->departments->assignMember($company, $admin->id, $id);
    Plugin::getInstance()->departments->assignMember($company, $approver->id, $id);

    $ids = callDepartmentScopedApproverIds($company->id, $admin->id);

    expect($ids)->not->toBeNull()
        ->and($ids)->toContain($approver->id);
});

it('returns null (not []) for a requester with no department so approval falls back to company approvers', function () {
    // deptMember() adds the admin to the company but assigns no department.
    [$admin, $company] = deptMember();

    // A [] here would deadlock a department-scoped step in phase 18; the contract requires null.
    expect(callDepartmentScopedApproverIds($company->id, $admin->id))->toBeNull();
});

it('returns null for an unknown requester id', function () {
    [, $company] = deptMember();

    expect(callDepartmentScopedApproverIds($company->id, 999999999))->toBeNull();
});

it('sums the spend of all current department members for the department period', function () {
    [$admin, $company] = deptMember();
    $id = Plugin::getInstance()->departments->createDepartment($company, 'Sales', 1000.0, BudgetPeriod::Monthly, null);

    $second = createTestUser('deptspend_' . uniqid() . '@example.test');
    Plugin::getInstance()->companyMembers->addUserToCompany($second->id, $company->id, CompanyRole::Purchaser);
    Plugin::getInstance()->departments->assignMember($company, $admin->id, $id);
    Plugin::getInstance()->departments->assignMember($company, $second->id, $id);

    budgetCompletedOrder($admin, 30.0);
    budgetCompletedOrder($second, 20.0);

    $department = Plugin::getInstance()->departments->getDepartment($id);

    expect(Plugin::getInstance()->departmentBudget->getSpent($department, new DateTimeImmutable('now')))->toBe(50.0);
});

it('excludes a non-department member from department spend', function () {
    [$admin, $company] = deptMember();
    $id = Plugin::getInstance()->departments->createDepartment($company, 'Sales', 1000.0, BudgetPeriod::Monthly, null);
    Plugin::getInstance()->departments->assignMember($company, $admin->id, $id);

    $outsider = createTestUser('deptout_' . uniqid() . '@example.test');
    Plugin::getInstance()->companyMembers->addUserToCompany($outsider->id, $company->id, CompanyRole::Purchaser);

    budgetCompletedOrder($admin, 30.0);
    budgetCompletedOrder($outsider, 500.0); // not in the department

    $department = Plugin::getInstance()->departments->getDepartment($id);

    expect(Plugin::getInstance()->departmentBudget->getSpent($department, new DateTimeImmutable('now')))->toBe(30.0);
});

it('treats a null-budget department as unlimited', function () {
    [$admin, $company] = deptMember();
    $id = Plugin::getInstance()->departments->createDepartment($company, 'Ops', null, BudgetPeriod::Monthly, null);
    Plugin::getInstance()->departments->assignMember($company, $admin->id, $id);
    budgetCompletedOrder($admin, 9999.0);

    $department = Plugin::getInstance()->departments->getDepartment($id);

    expect(Plugin::getInstance()->departmentBudget->canAfford($department, 9999.0, new DateTimeImmutable('now')))->toBeTrue();
});

it('allows a charge landing exactly on the department budget', function () {
    [$admin, $company] = deptMember();
    $id = Plugin::getInstance()->departments->createDepartment($company, 'Sales', 50.0, BudgetPeriod::Monthly, null);
    Plugin::getInstance()->departments->assignMember($company, $admin->id, $id);
    budgetCompletedOrder($admin, 40.0);

    $department = Plugin::getInstance()->departments->getDepartment($id);

    expect(Plugin::getInstance()->departmentBudget->canAfford($department, 10.0, new DateTimeImmutable('now')))->toBeTrue()
        ->and(Plugin::getInstance()->departmentBudget->canAfford($department, 11.0, new DateTimeImmutable('now')))->toBeFalse();
});

it('drops a previous-month order once the department monthly period resets', function () {
    [$admin, $company] = deptMember();
    $id = Plugin::getInstance()->departments->createDepartment($company, 'Sales', 100.0, BudgetPeriod::Monthly, null);
    Plugin::getInstance()->departments->assignMember($company, $admin->id, $id);

    $old = budgetCompletedOrder($admin, 40.0);
    setOrderDateOrdered($old, '2020-01-15 12:00:00'); // helper in BudgetTest.php

    $department = Plugin::getInstance()->departments->getDepartment($id);

    expect(Plugin::getInstance()->departmentBudget->getSpent($department, new DateTimeImmutable('now')))->toBe(0.0);
});

/**
 * Runs the department completion backstop under a faked storefront request and reports whether it
 * refused (threw). On a pass it leaves the per-department lock held; callers must release it.
 */
function deptEnforceAsSiteRequest(Order $order): bool
{
    $refused = false;

    asSiteRequest(function () use ($order, &$refused) {
        try {
            Plugin::getInstance()->departmentBudgetEnforcer->enforceDepartmentBudget($order);
        } catch (Throwable) {
            $refused = true;
        }
    });

    return $refused;
}

it('refuses completion when the department is over its aggregate budget', function () {
    [$admin, $company] = deptMember();
    $id = Plugin::getInstance()->departments->createDepartment($company, 'Sales', 50.0, BudgetPeriod::Monthly, null);
    Plugin::getInstance()->departments->assignMember($company, $admin->id, $id);
    budgetCompletedOrder($admin, 40.0);

    $cart = budgetCart($admin, 20.0);

    expect(deptEnforceAsSiteRequest($cart))->toBeTrue()
        ->and($cart->getErrors('customerId'))->toBe(['This order exceeds the department spending budget.']);
});

it('lets an in-budget department completion through and holds the lock for the after-handler', function () {
    [$admin, $company] = deptMember();
    $id = Plugin::getInstance()->departments->createDepartment($company, 'Sales', 500.0, BudgetPeriod::Monthly, null);
    Plugin::getInstance()->departments->assignMember($company, $admin->id, $id);
    budgetCompletedOrder($admin, 40.0);

    $cart = budgetCart($admin, 20.0);
    $lockName = "b2b-dept-budget-{$id}";

    expect(deptEnforceAsSiteRequest($cart))->toBeFalse()
        ->and(Craft::$app->getMutex()->isAcquired($lockName))->toBeTrue();

    Plugin::getInstance()->departmentBudgetEnforcer->releaseDepartmentBudgetLock($cart);
    expect(Craft::$app->getMutex()->isAcquired($lockName))->toBeFalse();
});

it('never enforces a department budget on a member without a department', function () {
    [$admin] = deptMember();
    $cart = budgetCart($admin, 100000.0);

    expect(deptEnforceAsSiteRequest($cart))->toBeFalse();
});

it('never enforces a null-budget department', function () {
    [$admin, $company] = deptMember();
    $id = Plugin::getInstance()->departments->createDepartment($company, 'Ops', null, BudgetPeriod::Monthly, null);
    Plugin::getInstance()->departments->assignMember($company, $admin->id, $id);
    budgetCompletedOrder($admin, 100000.0);

    $cart = budgetCart($admin, 100000.0);

    expect(deptEnforceAsSiteRequest($cart))->toBeFalse();
});

it('enforces both the per-member and the department budget independently', function () {
    // Roomy department (1000), tight per-member budget (50): the member gate refuses first.
    [$admin, $company] = deptMember();
    $id = Plugin::getInstance()->departments->createDepartment($company, 'Sales', 1000.0, BudgetPeriod::Monthly, null);
    Plugin::getInstance()->departments->assignMember($company, $admin->id, $id);
    Plugin::getInstance()->budgets->setBudget($company, $admin->id, 50.0, BudgetPeriod::Monthly);
    budgetCompletedOrder($admin, 40.0);

    $cart = budgetCart($admin, 20.0);

    // Per-member gate refuses (60 > 50) even though the department (60 <= 1000) is fine.
    expect(budgetEnforceAsSiteRequest($cart))->toBeTrue();
    // And with a roomy per-member budget but a tight department, the department gate refuses.
    Plugin::getInstance()->budgets->setBudget($company, $admin->id, 1000.0, BudgetPeriod::Monthly);
    Plugin::getInstance()->departments->updateDepartment($id, 'Sales', 50.0, BudgetPeriod::Monthly, null);

    expect(deptEnforceAsSiteRequest($cart))->toBeTrue();
});

it('shifts an order to the department a member is reassigned into (aggregate follows membership)', function () {
    [$admin, $company] = deptMember();
    $deptA = Plugin::getInstance()->departments->createDepartment($company, 'A', 100.0, BudgetPeriod::Monthly, null);
    $deptB = Plugin::getInstance()->departments->createDepartment($company, 'B', 100.0, BudgetPeriod::Monthly, null);
    Plugin::getInstance()->departments->assignMember($company, $admin->id, $deptA);
    budgetCompletedOrder($admin, 40.0);

    $now = new DateTimeImmutable('now');
    expect(Plugin::getInstance()->departmentBudget->getSpent(Plugin::getInstance()->departments->getDepartment($deptA), $now))->toBe(40.0)
        ->and(Plugin::getInstance()->departmentBudget->getSpent(Plugin::getInstance()->departments->getDepartment($deptB), $now))->toBe(0.0);

    Plugin::getInstance()->departments->assignMember($company, $admin->id, $deptB);

    expect(Plugin::getInstance()->departmentBudget->getSpent(Plugin::getInstance()->departments->getDepartment($deptA), $now))->toBe(0.0)
        ->and(Plugin::getInstance()->departmentBudget->getSpent(Plugin::getInstance()->departments->getDepartment($deptB), $now))->toBe(40.0);
});

it('does not enforce a department budget after its department is deleted', function () {
    [$admin, $company] = deptMember();
    $id = Plugin::getInstance()->departments->createDepartment($company, 'Sales', 10.0, BudgetPeriod::Monthly, null);
    Plugin::getInstance()->departments->assignMember($company, $admin->id, $id);
    budgetCompletedOrder($admin, 40.0);

    Plugin::getInstance()->departments->deleteDepartment($id);

    $cart = budgetCart($admin, 1000.0);

    // The member's departmentId was SET NULL by the delete, so there is nothing to enforce.
    expect(deptEnforceAsSiteRequest($cart))->toBeFalse();
});
