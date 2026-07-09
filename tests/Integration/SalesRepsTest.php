<?php

use craft\db\Query;
use craft\elements\User;
use totalwebcreations\b2bcommerce\elements\Company;
use totalwebcreations\b2bcommerce\modules\companies\services\SalesReps;
use totalwebcreations\b2bcommerce\Plugin;

function salesRepsDb(): \craft\db\Connection
{
    return craftApp()->getDb();
}

function reps(): SalesReps
{
    return Plugin::getInstance()->salesReps;
}

function makeRepUser(): User
{
    $rep = createTestUser('rep_' . uniqid() . '@example.test');
    craftApp()->getUserPermissions()->saveUserPermissions($rep->id, ['b2b-commerce:orderOnBehalf']);

    // Re-fetch so the freshly saved permissions are reflected by can().
    return craftApp()->getUsers()->getUserById($rep->id);
}

it('has created the sales-rep schema objects', function () {
    $db = salesRepsDb();

    expect($db->tableExists('{{%b2b_rep_companies}}'))->toBeTrue()
        ->and($db->tableExists('{{%b2b_impersonation_log}}'))->toBeTrue()
        ->and($db->columnExists('{{%b2b_order_company}}', 'placedByRepId'))->toBeTrue();
});

it('bumps the plugin schema version to 1.1.4', function () {
    expect(Plugin::getInstance()->schemaVersion)->toBe('1.1.4');
});

it('assigns and unassigns a rep to a company', function () {
    $rep = createTestUser('assign_' . uniqid() . '@example.test');
    $company = createTestCompany(Company::STATUS_APPROVED, 'Rep Co');

    reps()->assignRep($rep->id, $company->id);
    expect(reps()->isRepForCompany($rep->id, $company->id))->toBeTrue();

    // Idempotent: a second assign does not create a duplicate row.
    reps()->assignRep($rep->id, $company->id);
    expect((new Query())->from('{{%b2b_rep_companies}}')
        ->where(['repUserId' => $rep->id, 'companyId' => $company->id])->count())->toBe('1');

    reps()->unassignRep($rep->id, $company->id);
    expect(reps()->isRepForCompany($rep->id, $company->id))->toBeFalse();
});

it('lists only the companies a rep is assigned to', function () {
    $rep = createTestUser('list_' . uniqid() . '@example.test');
    $a = createTestCompany(Company::STATUS_APPROVED, 'Rep A');
    $b = createTestCompany(Company::STATUS_APPROVED, 'Rep B');
    createTestCompany(Company::STATUS_APPROVED, 'Rep C (unassigned)');

    reps()->assignRep($rep->id, $a->id);
    reps()->assignRep($rep->id, $b->id);

    $ids = array_map(fn(Company $c): int => $c->id, reps()->getCompaniesForRep($rep->id));

    expect($ids)->toContain($a->id)->toContain($b->id)->toHaveCount(2);
});

it('grants canActFor only with BOTH permission and assignment', function () {
    $company = createTestCompany(Company::STATUS_APPROVED, 'Scope Co');

    // Permission but no assignment.
    $rep = makeRepUser();
    expect(reps()->canActFor($rep, $company))->toBeFalse();

    // Assignment added -> now allowed.
    reps()->assignRep($rep->id, $company->id);
    $rep = craftApp()->getUsers()->getUserById($rep->id);
    expect(reps()->canActFor($rep, $company))->toBeTrue();

    // Assignment but no permission -> refused.
    $noPerm = createTestUser('noperm_' . uniqid() . '@example.test');
    reps()->assignRep($noPerm->id, $company->id);
    expect(reps()->canActFor($noPerm, $company))->toBeFalse();
});

it('does not grant a non-rep admin any B2B rep scope', function () {
    $company = createTestCompany(Company::STATUS_APPROVED, 'Admin Scope Co');

    $admin = createTestUser('admin_' . uniqid() . '@example.test');
    $admin->admin = true;
    craftApp()->getElements()->saveElement($admin);
    $admin = craftApp()->getUsers()->getUserById($admin->id);

    // Admin can() returns true for the permission, but with no assignment row canActFor is false.
    expect($admin->can('b2b-commerce:orderOnBehalf'))->toBeTrue()
        ->and(reps()->canActFor($admin, $company))->toBeFalse();
});
