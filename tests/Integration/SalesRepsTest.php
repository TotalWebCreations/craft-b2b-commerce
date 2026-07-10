<?php

use craft\commerce\elements\Order;
use craft\db\Query;
use craft\elements\User;
use totalwebcreations\b2bcommerce\elements\Company;
use totalwebcreations\b2bcommerce\enums\CompanyRole;
use totalwebcreations\b2bcommerce\modules\companies\services\SalesReps;
use totalwebcreations\b2bcommerce\Plugin;
use yii\web\ForbiddenHttpException;

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

it('bumps the plugin schema version to at least 1.1.5', function () {
    expect(version_compare(Plugin::getInstance()->schemaVersion, '1.1.5', '>='))->toBeTrue();
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

it('writes and reads audit rows scoped by company', function () {
    $rep = createTestUser('audit_rep_' . uniqid() . '@example.test');
    $target = createTestUser('audit_target_' . uniqid() . '@example.test');
    $companyA = createTestCompany(Company::STATUS_APPROVED, 'Audit A');
    $companyB = createTestCompany(Company::STATUS_APPROVED, 'Audit B');

    reps()->log($rep->id, $target->id, $companyA->id, null, SalesReps::ACTION_ACT_AS);
    reps()->log($rep->id, $target->id, $companyB->id, null, SalesReps::ACTION_ACT_AS);

    $forA = reps()->getLog($companyA->id);
    $companyIds = array_map(fn(array $row): int => (int) $row['companyId'], $forA);

    expect($forA)->toHaveCount(1)
        ->and($companyIds)->toBe([$companyA->id])
        ->and($forA[0]['action'])->toBe(SalesReps::ACTION_ACT_AS)
        ->and((int) $forA[0]['repUserId'])->toBe($rep->id)
        ->and((int) $forA[0]['targetUserId'])->toBe($target->id);
});

it('keeps the audit row when the rep user is hard-deleted, nulling out the actor id', function () {
    $rep = createTestUser('audit_deleted_rep_' . uniqid() . '@example.test');
    $target = createTestUser('audit_deleted_target_' . uniqid() . '@example.test');
    $company = createTestCompany(Company::STATUS_APPROVED, 'Audit Deleted Rep Co');

    reps()->log($rep->id, $target->id, $company->id, null, SalesReps::ACTION_ACT_AS);
    $repId = $rep->id;

    // Hard-delete the rep: repUserId has an ON DELETE SET NULL foreign key, so the
    // audit row survives with a null actor reference instead of being erased.
    craftApp()->getElements()->deleteElement($rep, true);
    $GLOBALS['b2bTrackedElements'] = array_values(array_filter(
        $GLOBALS['b2bTrackedElements'],
        fn ($tracked) => $tracked->id !== $repId,
    ));

    $row = (new Query())
        ->from('{{%b2b_impersonation_log}}')
        ->where(['targetUserId' => $target->id, 'companyId' => $company->id])
        ->one();

    expect($row)->not->toBeNull()
        ->and($row['repUserId'])->toBeNull()
        ->and((int) $row['targetUserId'])->toBe($target->id)
        ->and((int) $row['companyId'])->toBe($company->id)
        ->and($row['action'])->toBe(SalesReps::ACTION_ACT_AS);
});

/**
 * Grants the native Craft impersonateUsers permission. Craft's saveUserPermissions() silently
 * drops a nested permission whose ancestors are not ALSO present in the posted set
 * (UserPermissions::_findSelectedPermissions only recurses into a group's `nested` permissions
 * when the group itself is posted) — impersonateUsers nests under editUsers, which nests under
 * viewUsers, so both must be granted alongside it or the permission never actually sticks.
 */
function grantImpersonate(User $user): User
{
    $existing = craftApp()->getUserPermissions()->getPermissionsByUserId($user->id);
    craftApp()->getUserPermissions()->saveUserPermissions(
        $user->id,
        array_values(array_unique([...$existing, 'viewUsers', 'editUsers', 'impersonateUsers']))
    );

    return craftApp()->getUsers()->getUserById($user->id);
}

it('starts impersonation for an assigned company and logs it', function () {
    $company = createTestCompany(Company::STATUS_APPROVED, 'ActAs Co');
    $member = createTestUser('member_' . uniqid() . '@example.test');
    Plugin::getInstance()->companyMembers->addUserToCompany($member->id, $company->id, CompanyRole::Purchaser);

    $rep = grantImpersonate(makeRepUser());
    reps()->assignRep($rep->id, $company->id);

    // Integration suite runs a console app: craft\console\User has no impersonation concept
    // (getImpersonatorId/setImpersonatorId/loginByUserId are craft\web\User-only, session-backed
    // APIs). impersonationTestUser() attaches a test-only behavior reproducing exactly those
    // three methods so actAs()/endActingAs() run unmodified; see its docblock in helpers.php. The
    // real web session path is covered end-to-end by tests/Http/SalesRepHttpTest.php.
    $userSession = impersonationTestUser();
    $previous = $userSession->getIdentity();

    try {
        $userSession->setIdentity($rep);
        reps()->actAs($rep, $member);

        expect((int) $userSession->getId())->toBe($member->id)
            ->and($userSession->getImpersonatorId())->toBe($rep->id);

        $log = reps()->getLog($company->id);
        expect($log[0]['action'])->toBe(SalesReps::ACTION_ACT_AS);

        // End: rep restored, impersonation marker cleared.
        reps()->endActingAs();
        expect($userSession->getImpersonatorId())->toBeNull();
    } finally {
        $userSession->setIdentity($previous);
        $userSession->setImpersonatorId(null);
    }
});

it('refuses to act for an unassigned company', function () {
    $company = createTestCompany(Company::STATUS_APPROVED, 'Unassigned Co');
    $member = createTestUser('umember_' . uniqid() . '@example.test');
    Plugin::getInstance()->companyMembers->addUserToCompany($member->id, $company->id, CompanyRole::Purchaser);

    $rep = grantImpersonate(makeRepUser());
    // Deliberately NOT assigned to $company.

    reps()->actAs($rep, $member);
})->throws(ForbiddenHttpException::class);

it('resolves the acting rep id only for a genuine rep of the order company', function () {
    $company = createTestCompany(Company::STATUS_APPROVED, 'Resolve Co');
    $member = createTestUser('rmember_' . uniqid() . '@example.test');
    Plugin::getInstance()->companyMembers->addUserToCompany($member->id, $company->id, CompanyRole::Purchaser);

    $rep = grantImpersonate(makeRepUser());
    reps()->assignRep($rep->id, $company->id);

    $userSession = impersonationTestUser();

    try {
        $userSession->setImpersonatorId($rep->id);
        expect(reps()->resolveActingRepId($company))->toBe($rep->id);

        // No impersonation in session -> null.
        $userSession->setImpersonatorId(null);
        expect(reps()->resolveActingRepId($company))->toBeNull();
    } finally {
        $userSession->setImpersonatorId(null);
    }
});

function completedMemberOrder(User $member): Order
{
    $order = new Order();
    $order->number = md5(uniqid((string) mt_rand(), true));

    if (!craftApp()->getElements()->saveElement($order)) {
        throw new RuntimeException('Could not save order: ' . implode(', ', $order->getFirstErrors()));
    }

    trackElement($order);
    $order->setCustomer($member);

    return $order;
}

function orderCompanyRow(int $orderId): ?array
{
    return (new Query())->from('{{%b2b_order_company}}')->where(['orderId' => $orderId])->one() ?: null;
}

it('stamps placedByRepId and logs when a rep placed the order', function () {
    $company = createTestCompany(Company::STATUS_APPROVED, 'Stamp Co');
    $member = createTestUser('smember_' . uniqid() . '@example.test');
    Plugin::getInstance()->companyMembers->addUserToCompany($member->id, $company->id, CompanyRole::Purchaser);

    $rep = grantImpersonate(makeRepUser());
    reps()->assignRep($rep->id, $company->id);

    $order = completedMemberOrder($member);
    $userSession = impersonationTestUser();

    try {
        $userSession->setImpersonatorId($rep->id);
        Plugin::getInstance()->orderCompanyLink->linkCompany($order);
    } finally {
        $userSession->setImpersonatorId(null);
    }

    $row = orderCompanyRow($order->id);
    $orderLog = array_values(array_filter(
        reps()->getLog($company->id),
        fn(array $r): bool => $r['action'] === SalesReps::ACTION_ORDER_PLACED && (int) $r['orderId'] === $order->id
    ));

    expect((int) $row['placedByRepId'])->toBe($rep->id)
        ->and($orderLog)->toHaveCount(1);
});

it('assigns a rep by email through the CP service path', function () {
    $company = createTestCompany(Company::STATUS_APPROVED, 'CP Rep Co');
    $rep = createTestUser('cp_rep_' . uniqid() . '@example.test');

    // Mirrors CompaniesCpController::actionAssignRep: resolve by email, then assign.
    $resolved = Plugin::getInstance()->companyMembers->findUserByEmail($rep->email);
    Plugin::getInstance()->salesReps->assignRep($resolved->id, $company->id);

    $repIds = array_map(fn(User $u): int => $u->id, Plugin::getInstance()->salesReps->getRepsForCompany($company->id));

    expect($repIds)->toContain($rep->id);
});

it('leaves placedByRepId null for an ordinary (non-rep) order', function () {
    $company = createTestCompany(Company::STATUS_APPROVED, 'Plain Co');
    $member = createTestUser('pmember_' . uniqid() . '@example.test');
    Plugin::getInstance()->companyMembers->addUserToCompany($member->id, $company->id, CompanyRole::Purchaser);

    // Attach the impersonation shim and guarantee no impersonator lingers from a prior test, so
    // resolveActingRepId's getImpersonatorId() call resolves to null on the console user component.
    impersonationTestUser()->setImpersonatorId(null);

    $order = completedMemberOrder($member);
    Plugin::getInstance()->orderCompanyLink->linkCompany($order);

    $row = orderCompanyRow($order->id);
    expect($row['placedByRepId'])->toBeNull();
});
