<?php

use totalwebcreations\b2bcommerce\enums\CompanyRole;
use totalwebcreations\b2bcommerce\Plugin;

it('lets a purchaser member create a list for their own company', function () {
    $company = createTestCompany();
    $member = createTestUserWithPassword('orderlists_' . uniqid() . '@example.test');
    Plugin::getInstance()->companyMembers->addUserToCompany($member->id, $company->id, CompanyRole::Purchaser);

    $client = httpClient();
    loginAs($client, $member->email, httpTestPassword());

    $response = postAction($client, 'b2b-commerce/order-lists/create', [
        'name' => 'Weekly staples',
    ]);

    $lists = Plugin::getInstance()->orderLists->getLists($company->id);

    expect($response->getStatusCode())->toBe(200)
        ->and($lists)->toHaveCount(1)
        ->and($lists[0]['name'])->toBe('Weekly staples');
});

it('refuses a guest creating a list without a server error', function () {
    $client = httpClient();

    $response = postAction($client, 'b2b-commerce/order-lists/create', [
        'name' => 'Guest list',
    ]);

    expect($response->getStatusCode())->toBeGreaterThanOrEqual(400)
        ->and($response->getStatusCode())->toBeLessThan(500);
});
