<?php

use totalwebcreations\b2bcommerce\enums\CompanyRole;
use totalwebcreations\b2bcommerce\Plugin;

it('adds SKUs from an uploaded CSV file to an approved buyer cart', function () {
    $skuA = 'CSV-OK-A-' . substr(uniqid(), -6);
    $skuB = 'CSV-OK-B-' . substr(uniqid(), -6);
    createTestVariant($skuA);
    createTestVariant($skuB);

    $company = createTestCompany('approved');
    $buyer = createTestUserWithPassword('csvupload_' . uniqid() . '@example.test');
    Plugin::getInstance()->companyMembers->addUserToCompany($buyer->id, $company->id, CompanyRole::Admin);

    $client = httpClient();
    loginAs($client, $buyer->email, httpTestPassword());

    $response = postMultipartAction($client, 'b2b-commerce/quick-order/upload-csv', [
        [
            'name' => 'csvFile',
            'contents' => "{$skuA},2\n{$skuB},3\n",
            'filename' => 'order.csv',
            'headers' => ['Content-Type' => 'text/csv'],
        ],
    ]);

    $data = json_decode((string) $response->getBody(), true);

    expect($response->getStatusCode())->toBe(200)
        ->and($data['added'] ?? null)->toBe(2);
});

it('rejects a CSV upload larger than the size limit with a clean failure', function () {
    $company = createTestCompany('approved');
    $buyer = createTestUserWithPassword('csvupload_big_' . uniqid() . '@example.test');
    Plugin::getInstance()->companyMembers->addUserToCompany($buyer->id, $company->id, CompanyRole::Admin);

    $client = httpClient();
    loginAs($client, $buyer->email, httpTestPassword());

    // The controller caps uploads at 1 MB and returns before parsing; ~1.1 MB trips it.
    $oversized = str_repeat("SKU,1\n", 200000);

    $response = postMultipartAction($client, 'b2b-commerce/quick-order/upload-csv', [
        [
            'name' => 'csvFile',
            'contents' => $oversized,
            'filename' => 'big.csv',
            'headers' => ['Content-Type' => 'text/csv'],
        ],
    ]);

    $data = json_decode((string) $response->getBody(), true);

    expect($response->getStatusCode())->toBe(400)
        ->and($data['added'] ?? null)->toBeNull()
        ->and($data['message'] ?? '')->toBeString()->not->toBe('');
});
