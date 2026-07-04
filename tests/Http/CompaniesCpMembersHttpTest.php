<?php

use craft\elements\User;
use GuzzleHttp\Client;
use Psr\Http\Message\ResponseInterface;
use totalwebcreations\b2bcommerce\elements\Company;
use totalwebcreations\b2bcommerce\enums\CompanyRole;
use totalwebcreations\b2bcommerce\Plugin;

/**
 * Posts a control-panel action request. CP controllers require a CP request, so the
 * action is posted under the /admin prefix (not the bare /actions endpoint) with a
 * freshly fetched CSRF token, mirroring how a CP form submits.
 *
 * @param array<string, mixed> $params
 */
function postCpAction(Client $client, string $action, array $params = []): ResponseInterface
{
    $token = csrfToken($client);

    return $client->post("/admin/actions/{$action}", [
        'headers' => [
            'Accept' => 'text/html',
            'X-CSRF-Token' => $token,
        ],
        'form_params' => $params + ['CRAFT_CSRF_TOKEN' => $token],
    ]);
}

/**
 * Creates a logged-in CP client for a fresh user granted the manageCompanies permission.
 *
 * @return array{0: Client, 1: User}
 */
function cpManagerClient(): array
{
    $user = createTestUserWithPassword('cp_manager_' . uniqid() . '@example.test');
    craftApp()->getUserPermissions()->saveUserPermissions($user->id, ['accessCp', 'b2b-commerce:manageCompanies']);

    $client = httpClient();
    loginAs($client, $user->email, httpTestPassword());

    return [$client, $user];
}

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
