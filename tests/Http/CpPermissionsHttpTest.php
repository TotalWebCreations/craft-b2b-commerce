<?php

it('forbids a CP user without manageCompanies from the company members page', function () {
    $company = createTestCompany();

    $user = createTestUserWithPassword('cp_user_' . uniqid() . '@example.test');

    // Grant control-panel access but deliberately withhold manageCompanies, so a
    // 403 can only come from the controller's permission check, not the CP gate.
    craftApp()->getUserPermissions()->saveUserPermissions($user->id, ['accessCp']);

    $client = httpClient();
    loginAs($client, $user->email, httpTestPassword());

    $response = $client->get("/admin/b2b/companies/{$company->id}/members", [
        'headers' => ['Accept' => 'text/html'],
    ]);

    expect($response->getStatusCode())->toBe(403);
});

it('forbids a CP user without manageQuotes from the quotes workbench', function () {
    $user = createTestUserWithPassword('cp_user_' . uniqid() . '@example.test');

    // Grant control-panel access but deliberately withhold manageQuotes, so a 403 can
    // only come from the controller's permission check, not the CP gate.
    craftApp()->getUserPermissions()->saveUserPermissions($user->id, ['accessCp']);

    $client = httpClient();
    loginAs($client, $user->email, httpTestPassword());

    $response = $client->get('/admin/b2b/quotes', [
        'headers' => ['Accept' => 'text/html'],
    ]);

    expect($response->getStatusCode())->toBe(403);
});
