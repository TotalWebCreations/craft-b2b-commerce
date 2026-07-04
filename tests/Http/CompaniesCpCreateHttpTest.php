<?php

use GuzzleHttp\Client;
use totalwebcreations\b2bcommerce\elements\Company;

/**
 * Creates a logged-in CP client for a fresh user granted access to the control
 * panel but WITHOUT the manageCompanies permission, so the create flow must be
 * refused for it.
 */
function cpNonManagerClient(): Client
{
    $user = createTestUserWithPassword('cp_nonmanager_' . uniqid() . '@example.test');
    craftApp()->getUserPermissions()->saveUserPermissions($user->id, ['accessCp']);

    $client = httpClient();
    loginAs($client, $user->email, httpTestPassword());

    return $client;
}

it('renders the New company button linking to the create action for a CP manager', function () {
    [$client] = cpManagerClient();

    $response = $client->get('/admin/b2b/companies', [
        'headers' => ['Accept' => 'text/html'],
    ]);

    $body = (string) $response->getBody();

    expect($response->getStatusCode())->toBe(200)
        ->and($body)->toContain('actions/elements/create')
        ->and($body)->toContain(rawurlencode(Company::class));
});

it('creates a company draft via the create action and redirects to its edit page', function () {
    [$client] = cpManagerClient();

    $response = $client->get('/admin/actions/elements/create', [
        'query' => ['elementType' => Company::class],
        'headers' => ['Accept' => 'text/html'],
    ]);

    $location = $response->getHeaderLine('Location');

    // The created draft is a real element; track it so it is hard-deleted on teardown.
    if (preg_match('~/b2b/companies/(\d+)~', $location, $matches)) {
        $draft = Company::find()
            ->id((int) $matches[1])
            ->drafts(null)
            ->provisionalDrafts(null)
            ->status(null)
            ->one();

        if ($draft !== null) {
            trackElement($draft);
        }
    }

    expect($response->getStatusCode())->toBe(302)
        ->and($location)->toContain('/b2b/companies/')
        ->and($location)->toContain('draftId=');
});

it('forbids creating a company without the manageCompanies permission', function () {
    $client = cpNonManagerClient();

    $response = $client->get('/admin/actions/elements/create', [
        'query' => ['elementType' => Company::class],
        'headers' => ['Accept' => 'text/html'],
    ]);

    expect($response->getStatusCode())->toBe(403);
});

it('persists a brand-new company with a title and registration number', function () {
    $company = new Company();
    $company->title = 'Fresh Co ' . uniqid();
    $company->registrationNumber = 'REG-' . uniqid();
    $company->companyStatus = Company::STATUS_PENDING;

    $saved = craftApp()->getElements()->saveElement($company);
    trackElement($company);

    $found = Company::find()->id($company->id)->status(null)->one();

    expect($saved)->toBeTrue()
        ->and($found)->not->toBeNull()
        ->and($found->registrationNumber)->toBe($company->registrationNumber);
});
