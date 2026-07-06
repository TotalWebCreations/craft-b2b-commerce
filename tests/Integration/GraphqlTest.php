<?php

use craft\elements\User;
use craft\gql\GqlEntityRegistry;
use craft\gql\TypeLoader;
use craft\models\GqlSchema;
use totalwebcreations\b2bcommerce\elements\Company;
use totalwebcreations\b2bcommerce\enums\BudgetPeriod;
use totalwebcreations\b2bcommerce\enums\CompanyRole;
use totalwebcreations\b2bcommerce\Plugin;

/**
 * Scope covering both B2B GraphQL components, but NOT the sensitive-financials add-on.
 */
const B2B_GQL_FULL_SCOPE = ['b2bCompanies.all:read', 'b2bContext.self:read'];

/**
 * Scope that additionally opts in to reading company financial fields across all companies.
 */
const B2B_GQL_FINANCIALS_SCOPE = ['b2bCompanies.all:read', 'b2bCompanies.financials:read', 'b2bContext.self:read'];

/**
 * Builds a transient GraphQL schema with the given scope. A null id keeps executeQuery from caching
 * results, so each query is resolved fresh against the scope under test.
 */
function b2bGqlSchema(array $scope): GqlSchema
{
    return new GqlSchema([
        'id' => null,
        'name' => 'B2B Test Schema ' . uniqid(),
        'scope' => $scope,
    ]);
}

/**
 * Runs a query against the given schema, flushing the GraphQL caches first so the schema definition
 * is rebuilt for this scope (Craft memoizes the last-built schema otherwise). Debug mode surfaces
 * resolver errors in the result.
 *
 * @return array<string, mixed>
 */
function runB2bGql(GqlSchema $schema, string $query): array
{
    $gql = craftApp()->getGql();

    // Rebuild the schema definition for the scope under test. This is flushCaches() minus its
    // result-cache invalidation sweep, which races on transient cache files in the dev runtime and
    // emits stray filemtime warnings. Nulling the memoized definitions and flushing the type
    // registry is enough for a clean, scope-accurate rebuild.
    GqlEntityRegistry::flush();
    TypeLoader::flush();

    // Couples to craft\services\Gql's private memoization props; kept in sync with Craft manually
    // because there is no public API to rebuild a transient schema definition mid-request.
    Closure::bind(function (): void {
        $this->_schema = null;
        $this->_schemaDef = null;
        $this->_contentArguments = [];
        $this->_typeDefinitions = [];
    }, $gql, $gql)();

    craftApp()->getConfig()->getGeneral()->enableGraphqlCaching = false;

    return $gql->executeQuery($schema, $query, null, null, true);
}

/**
 * Runs the callback with $user (or a guest when null) as the signed-in identity, restoring the
 * previous identity afterwards.
 */
function asGqlIdentity(?User $user, callable $callback): void
{
    $userComponent = craftApp()->getUser();
    $previous = $userComponent->getIdentity();
    $userComponent->setIdentity($user);

    try {
        $callback();
    } finally {
        $userComponent->setIdentity($previous);
    }
}

/**
 * Creates a tracked, approved company with the given attributes plus one admin member.
 *
 * @return array{0: Company, 1: User}
 */
function gqlCompanyWithAdmin(array $attributes = []): array
{
    $company = createTestCompany(Company::STATUS_APPROVED, 'Gql Co');

    foreach ($attributes as $key => $value) {
        $company->$key = $value;
    }

    if ($attributes !== [] && !craftApp()->getElements()->saveElement($company)) {
        throw new RuntimeException('Could not save company: ' . implode(', ', $company->getFirstErrors()));
    }

    $admin = createTestUser('gql_admin_' . uniqid() . '@example.test');
    Plugin::getInstance()->companyMembers->addUserToCompany($admin->id, $company->id, CompanyRole::Admin);

    return [$company, $admin];
}

it('exposes company financial fields through the element query with the financials scope', function () {
    [$company] = gqlCompanyWithAdmin([
        'registrationNumber' => 'REG-123',
        'taxId' => 'NL123456789B01',
        'creditLimit' => 1000.0,
        'paymentTermDays' => 30,
        'allowInvoicePayment' => true,
        'approvalThreshold' => 500.0,
    ]);

    $result = runB2bGql(b2bGqlSchema(B2B_GQL_FINANCIALS_SCOPE), <<<GQL
        query {
            company(id: {$company->id}) {
                id
                name
                title
                registrationNumber
                taxId
                status
                creditLimit
                paymentTermDays
                allowInvoicePayment
                approvalThreshold
            }
        }
    GQL);

    expect($result['errors'] ?? null)->toBeNull();

    $data = $result['data']['company'];

    expect((int) $data['id'])->toBe($company->id)
        ->and($data['name'])->toBe($company->title)
        ->and($data['registrationNumber'])->toBe('REG-123')
        ->and($data['taxId'])->toBe('NL123456789B01')
        ->and($data['status'])->toBe(Company::STATUS_APPROVED)
        ->and($data['creditLimit'])->toBe(1000.0)
        ->and($data['paymentTermDays'])->toBe(30)
        ->and($data['allowInvoicePayment'])->toBeTrue()
        ->and($data['approvalThreshold'])->toBe(500.0);
});

it('exposes company identity but nulls financial fields without the financials scope', function () {
    [$company] = gqlCompanyWithAdmin([
        'registrationNumber' => 'REG-456',
        'taxId' => 'NL987654321B01',
        'creditLimit' => 2000.0,
        'paymentTermDays' => 45,
        'allowInvoicePayment' => true,
        'approvalThreshold' => 750.0,
    ]);

    // No signed-in user: a public token bearing only b2bCompanies.all — the leak this fix closes.
    asGqlIdentity(null, function () use ($company) {
        $result = runB2bGql(b2bGqlSchema(B2B_GQL_FULL_SCOPE), <<<GQL
            query {
                company(id: {$company->id}) {
                    id
                    name
                    registrationNumber
                    status
                    taxId
                    creditLimit
                    paymentTermDays
                    allowInvoicePayment
                    approvalThreshold
                }
            }
        GQL);

        expect($result['errors'] ?? null)->toBeNull();

        $data = $result['data']['company'];

        expect($data['name'])->toBe($company->title)
            ->and($data['registrationNumber'])->toBe('REG-456')
            ->and($data['status'])->toBe(Company::STATUS_APPROVED)
            ->and($data['taxId'])->toBeNull()
            ->and($data['creditLimit'])->toBeNull()
            ->and($data['paymentTermDays'])->toBeNull()
            ->and($data['allowInvoicePayment'])->toBeNull()
            ->and($data['approvalThreshold'])->toBeNull();
    });
});

it('never exposes another company’s financials through the element query without the scope', function () {
    [$companyA, $adminA] = gqlCompanyWithAdmin(['creditLimit' => 1111.0, 'taxId' => 'NL-A']);
    [$companyB] = gqlCompanyWithAdmin(['creditLimit' => 2222.0, 'taxId' => 'NL-B']);

    // adminA is signed in but the schema has no financials scope: they may read their OWN financials,
    // never company B's.
    asGqlIdentity($adminA, function () use ($companyA, $companyB) {
        $query = fn(int $id) => runB2bGql(b2bGqlSchema(B2B_GQL_FULL_SCOPE), <<<GQL
            query {
                company(id: {$id}) { taxId creditLimit }
            }
        GQL)['data']['company'];

        $own = $query($companyA->id);
        $other = $query($companyB->id);

        expect($own['taxId'])->toBe('NL-A')
            ->and($own['creditLimit'])->toBe(1111.0)
            ->and($other['taxId'])->toBeNull()
            ->and($other['creditLimit'])->toBeNull();
    });
});

it('always exposes the caller’s own company financials through b2bContext regardless of scope', function () {
    [$company, $admin] = gqlCompanyWithAdmin([
        'taxId' => 'NL-OWN',
        'creditLimit' => 3000.0,
        'paymentTermDays' => 60,
        'allowInvoicePayment' => true,
        'approvalThreshold' => 900.0,
    ]);

    // Schema deliberately omits b2bCompanies.financials: own-company financials must still resolve.
    asGqlIdentity($admin, function () use ($company) {
        $result = runB2bGql(b2bGqlSchema(B2B_GQL_FULL_SCOPE), <<<'GQL'
            query {
                b2bContext {
                    company {
                        taxId
                        creditLimit
                        paymentTermDays
                        allowInvoicePayment
                        approvalThreshold
                    }
                }
            }
        GQL);

        expect($result['errors'] ?? null)->toBeNull();

        $data = $result['data']['b2bContext']['company'];

        expect($data['taxId'])->toBe('NL-OWN')
            ->and($data['creditLimit'])->toBe(3000.0)
            ->and($data['paymentTermDays'])->toBe(60)
            ->and($data['allowInvoicePayment'])->toBeTrue()
            ->and($data['approvalThreshold'])->toBe(900.0);
    });
});

it('returns the authenticated member’s own company, role, budget and credit summary', function () {
    [$company, $admin] = gqlCompanyWithAdmin(['creditLimit' => 800.0]);
    Plugin::getInstance()->budgets->setBudget($company, $admin->id, 250.0, BudgetPeriod::Monthly);

    asGqlIdentity($admin, function () use ($company) {
        $result = runB2bGql(b2bGqlSchema(B2B_GQL_FULL_SCOPE), <<<'GQL'
            query {
                b2bContext {
                    role
                    company { id name }
                    creditSummary { outstanding creditLimit available }
                    memberBudget { amount period spent remaining }
                }
            }
        GQL);

        expect($result['errors'] ?? null)->toBeNull();

        $context = $result['data']['b2bContext'];

        expect($context)->not->toBeNull()
            ->and($context['role'])->toBe('admin')
            ->and((int) $context['company']['id'])->toBe($company->id)
            ->and($context['creditSummary']['creditLimit'])->toBe(800.0)
            ->and($context['creditSummary']['available'])->toBe(800.0)
            ->and($context['memberBudget']['amount'])->toBe(250.0)
            ->and($context['memberBudget']['period'])->toBe('monthly')
            ->and($context['memberBudget']['remaining'])->toBe(250.0);
    });
});

it('scopes b2bContext to the caller’s own company and never leaks another’s data', function () {
    [$companyA, $adminA] = gqlCompanyWithAdmin();
    $purchaserA = createTestUser('gql_purchaser_' . uniqid() . '@example.test');
    Plugin::getInstance()->companyMembers->addUserToCompany($purchaserA->id, $companyA->id, CompanyRole::Purchaser);

    [$companyB, $adminB] = gqlCompanyWithAdmin();
    $listB = Plugin::getInstance()->orderLists->createList($companyB, 'B only list', $adminB->id);

    $query = <<<'GQL'
        query {
            b2bContext {
                company { id }
                members { role user { email } }
                orderLists { id name }
            }
        }
    GQL;

    asGqlIdentity($adminA, function () use ($query, $companyA, $adminA, $purchaserA) {
        $context = runB2bGql(b2bGqlSchema(B2B_GQL_FULL_SCOPE), $query)['data']['b2bContext'];

        $emails = array_column(array_column($context['members'], 'user'), 'email');

        expect((int) $context['company']['id'])->toBe($companyA->id)
            ->and($context['members'])->toHaveCount(2)
            ->and($emails)->toContain($adminA->email)
            ->and($emails)->toContain($purchaserA->email)
            ->and($context['orderLists'])->toBe([]);
    });

    asGqlIdentity($adminB, function () use ($query, $companyB, $listB) {
        $context = runB2bGql(b2bGqlSchema(B2B_GQL_FULL_SCOPE), $query)['data']['b2bContext'];

        expect((int) $context['company']['id'])->toBe($companyB->id)
            ->and($context['members'])->toHaveCount(1)
            ->and($context['orderLists'])->toHaveCount(1)
            ->and((int) $context['orderLists'][0]['id'])->toBe($listB)
            ->and($context['orderLists'][0]['name'])->toBe('B only list');
    });
});

it('returns null b2bContext for a visitor with no user and no error', function () {
    asGqlIdentity(null, function () {
        $result = runB2bGql(b2bGqlSchema(B2B_GQL_FULL_SCOPE), '{ b2bContext { role company { id } } }');

        expect($result['errors'] ?? null)->toBeNull()
            ->and($result['data']['b2bContext'])->toBeNull();
    });
});

it('returns null b2bContext for a signed-in user who has no company', function () {
    $loner = createTestUser('gql_loner_' . uniqid() . '@example.test');

    asGqlIdentity($loner, function () {
        $result = runB2bGql(b2bGqlSchema(B2B_GQL_FULL_SCOPE), '{ b2bContext { role } }');

        expect($result['errors'] ?? null)->toBeNull()
            ->and($result['data']['b2bContext'])->toBeNull();
    });
});

it('does not register the company query when the b2bCompanies component is disabled', function () {
    // Schema carries only the b2bContext scope, so the Query type is non-empty but companies is off.
    $result = runB2bGql(b2bGqlSchema(['b2bContext.self:read']), '{ companies { id } }');

    expect($result['errors'] ?? [])->not->toBeEmpty()
        ->and($result['errors'][0]['message'])->toContain('Cannot query field "companies"');
});

it('does not register the b2bContext query when the b2bContext component is disabled', function () {
    // Schema carries only the company scope, so b2bContext is off.
    $result = runB2bGql(b2bGqlSchema(['b2bCompanies.all:read']), '{ b2bContext { role } }');

    expect($result['errors'] ?? [])->not->toBeEmpty()
        ->and($result['errors'][0]['message'])->toContain('Cannot query field "b2bContext"');
});
