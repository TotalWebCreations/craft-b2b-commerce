<?php

use totalwebcreations\b2bcommerce\elements\Company;

it('renders the B2B overview with its stat tiles for a permitted user', function () {
    createTestCompany(Company::STATUS_PENDING);

    [$client] = cpManagerClient();

    $response = $client->get('/admin/b2b', [
        'headers' => ['Accept' => 'text/html'],
    ]);

    $body = (string) $response->getBody();

    expect($response->getStatusCode())->toBe(200)
        ->and($body)->toContain('b2b-overview')
        ->and($body)->toContain('Companies')
        ->and($body)->toContain('Pending registrations')
        ->and($body)->toContain('Outstanding on account');
});

it('forbids a CP user without manageCompanies from the overview', function () {
    $user = createTestUserWithPassword('cp_user_' . uniqid() . '@example.test');

    // Control-panel access but not manageCompanies, so a 403 can only come from the
    // controller's permission check, not the CP gate.
    craftApp()->getUserPermissions()->saveUserPermissions($user->id, ['accessCp']);

    $client = httpClient();
    loginAs($client, $user->email, httpTestPassword());

    $response = $client->get('/admin/b2b', [
        'headers' => ['Accept' => 'text/html'],
    ]);

    expect($response->getStatusCode())->toBe(403);
});
