<?php

use totalwebcreations\b2bcommerce\enums\CompanyRole;
use totalwebcreations\b2bcommerce\Plugin;

it('adds resolvable SKUs to an approved buyer cart over HTTP and maps per-line errors', function () {
    $sku = 'QO-HTTP-OK-' . substr(uniqid(), -6);
    createTestVariant($sku);

    $company = createTestCompany('approved');
    $buyer = createTestUserWithPassword('quickorder_' . uniqid() . '@example.test');
    Plugin::getInstance()->companyMembers->addUserToCompany($buyer->id, $company->id, CompanyRole::Admin);

    $client = httpClient();
    loginAs($client, $buyer->email, httpTestPassword());

    // Line 1 resolves and is added; line 2 is an unknown SKU and must map to an error.
    $response = postAction($client, 'b2b-commerce/quick-order/add', [
        'lines' => "{$sku} 3\nQO-HTTP-UNKNOWN 1",
    ]);

    $data = json_decode((string) $response->getBody(), true);

    // The dev site renders in Dutch, so assert response structure rather than message
    // text (the English message text is asserted in the Integration suite).
    expect($response->getStatusCode())->toBe(200)
        ->and($data['added'] ?? null)->toBe(1)
        ->and($data['errors'] ?? [])->toHaveKey('2')
        ->and($data['errors']['2'])->toBeString()->not->toBe('');
});

it('does not 500 when the lines param arrives as an array', function () {
    $company = createTestCompany('approved');
    $buyer = createTestUserWithPassword('quickorder_array_' . uniqid() . '@example.test');
    Plugin::getInstance()->companyMembers->addUserToCompany($buyer->id, $company->id, CompanyRole::Admin);

    $client = httpClient();
    loginAs($client, $buyer->email, httpTestPassword());

    // A bot posting `lines[]=x` used to trigger an "Array to string conversion" 500.
    $response = postAction($client, 'b2b-commerce/quick-order/add', [
        'lines' => ['injected', 'array'],
    ]);

    $data = json_decode((string) $response->getBody(), true);

    expect($response->getStatusCode())->toBe(200)
        ->and($data['added'] ?? null)->toBe(0);
});
