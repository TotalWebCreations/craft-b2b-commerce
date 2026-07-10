<?php

use totalwebcreations\b2bcommerce\enums\BudgetPeriod;
use totalwebcreations\b2bcommerce\Plugin;

it('exposes the company departments and the member department budget through b2bContext', function () {
    [$company, $admin] = gqlCompanyWithAdmin();

    // Phase 19 fixtures: one department with a monthly budget, admin assigned to it.
    $departmentId = Plugin::getInstance()->departments->createDepartment($company, 'Facilities', 1000.0, BudgetPeriod::Monthly, $admin->id);
    Plugin::getInstance()->departments->assignMember($company, $admin->id, $departmentId);

    asGqlIdentity($admin, function () use ($company, $admin) {
        $result = runB2bGql(b2bGqlSchema(B2B_GQL_FULL_SCOPE), <<<'GQL'
            query {
                b2bContext {
                    departments { id name budgetAmount budgetPeriod approverUserId }
                    departmentBudget { amount period spent remaining }
                }
            }
        GQL);

        expect($result['errors'] ?? null)->toBeNull();

        $context = $result['data']['b2bContext'];

        expect($context['departments'])->toHaveCount(1)
            ->and($context['departments'][0]['name'])->toBe('Facilities')
            ->and($context['departments'][0]['budgetAmount'])->toBe(1000.0)
            ->and($context['departments'][0]['budgetPeriod'])->toBe('monthly')
            ->and($context['departments'][0]['approverUserId'])->toBe($admin->id)
            ->and($context['departmentBudget']['amount'])->toBe(1000.0)
            ->and($context['departmentBudget']['period'])->toBe('monthly')
            ->and($context['departmentBudget']['spent'])->toBe(0.0)
            ->and($context['departmentBudget']['remaining'])->toBe(1000.0);
    });
});

it('nulls the department budget for a member with no department or an unlimited one', function () {
    [$company, $admin] = gqlCompanyWithAdmin();

    asGqlIdentity($admin, function () {
        $result = runB2bGql(b2bGqlSchema(B2B_GQL_FULL_SCOPE), '{ b2bContext { departmentBudget { amount } } }');

        expect($result['errors'] ?? null)->toBeNull()
            ->and($result['data']['b2bContext']['departmentBudget'])->toBeNull();
    });
});
