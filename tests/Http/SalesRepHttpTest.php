<?php

use totalwebcreations\b2bcommerce\elements\Company;
use totalwebcreations\b2bcommerce\enums\CompanyRole;
use totalwebcreations\b2bcommerce\Plugin;

beforeEach(function () {
    if (!httpSiteAvailable()) {
        $this->markTestSkipped('Dev site not reachable.');
    }
});

/**
 * Creates a logged-in storefront rep client with orderOnBehalf + impersonateUsers, plus a member
 * belonging to $company. Returns [client, rep, member].
 *
 * @return array{0: GuzzleHttp\Client, 1: craft\elements\User, 2: craft\elements\User}
 */
function repClientFor(Company $company, bool $assign = true): array
{
    $rep = createTestUserWithPassword('rep_http_' . uniqid() . '@example.test');
    craftApp()->getUserPermissions()->saveUserPermissions($rep->id, ['viewUsers', 'editUsers', 'b2b-commerce:orderOnBehalf', 'impersonateUsers']);

    if ($assign) {
        Plugin::getInstance()->salesReps->assignRep($rep->id, $company->id);
    }

    $member = createTestUserWithPassword('member_http_' . uniqid() . '@example.test');
    Plugin::getInstance()->companyMembers->addUserToCompany($member->id, $company->id, CompanyRole::Purchaser);

    $client = httpClient();
    loginAs($client, $rep->email, httpTestPassword());

    return [$client, $rep, $member];
}

it('refuses act-as without the orderOnBehalf permission', function () {
    $company = createTestCompany(Company::STATUS_APPROVED, 'HTTP NoPerm Co');
    $member = createTestUserWithPassword('m_' . uniqid() . '@example.test');
    Plugin::getInstance()->companyMembers->addUserToCompany($member->id, $company->id, CompanyRole::Purchaser);

    $stranger = createTestUserWithPassword('stranger_' . uniqid() . '@example.test');
    $client = httpClient();
    loginAs($client, $stranger->email, httpTestPassword());

    $response = postAction($client, 'b2b-commerce/sales-rep/act', ['userId' => $member->id]);

    expect($response->getStatusCode())->toBe(403);
});

it('refuses act-as for an unassigned company', function () {
    $company = createTestCompany(Company::STATUS_APPROVED, 'HTTP Unassigned Co');
    [$client, , $member] = repClientFor($company, assign: false);

    $response = postAction($client, 'b2b-commerce/sales-rep/act', ['userId' => $member->id]);

    expect($response->getStatusCode())->toBe(403);
});

it('starts and ends impersonation for an assigned company', function () {
    $company = createTestCompany(Company::STATUS_APPROVED, 'HTTP Assigned Co');
    [$client, $rep, $member] = repClientFor($company);

    $act = postAction($client, 'b2b-commerce/sales-rep/act', ['userId' => $member->id]);
    expect($act->getStatusCode())->toBeIn([200, 302]);

    // The active session is now the member.
    $info = json_decode((string) $client->get('/actions/users/session-info', [
        'headers' => ['Accept' => 'application/json'],
    ])->getBody(), true);
    expect((int) ($info['id'] ?? 0))->toBe($member->id);

    // Ending impersonation restores the rep.
    postAction($client, 'b2b-commerce/sales-rep/end');
    $info = json_decode((string) $client->get('/actions/users/session-info', [
        'headers' => ['Accept' => 'application/json'],
    ])->getBody(), true);
    expect((int) ($info['id'] ?? 0))->toBe($rep->id);
});
