<?php

use craft\elements\Address;
use totalwebcreations\b2bcommerce\enums\CompanyRole;
use totalwebcreations\b2bcommerce\Plugin;

/**
 * @return array<string, mixed>
 */
function httpAddressAttributes(): array
{
    return [
        'title' => 'HTTP office',
        'fullName' => 'Jane Buyer',
        'addressLine1' => '12 Market Street',
        'postalCode' => '1011AB',
        'locality' => 'Amsterdam',
        'countryCode' => 'NL',
    ];
}

it('does not delete a foreign address when a member targets it (IDOR)', function () {
    $companyA = createTestCompany();
    $companyB = createTestCompany();

    $adminA = createTestUserWithPassword('addr_admin_a_' . uniqid() . '@example.test');
    Plugin::getInstance()->companyMembers->addUserToCompany($adminA->id, $companyA->id, CompanyRole::Admin);

    $addressB = Plugin::getInstance()->companyAddresses->saveAddress($companyB, httpAddressAttributes());
    trackElement($addressB);

    $client = httpClient();
    loginAs($client, $adminA->email, httpTestPassword());

    $response = postAction($client, 'b2b-commerce/addresses/delete', [
        'addressId' => $addressB->id,
    ]);

    expect($response->getStatusCode())->toBe(400)
        ->and(Address::find()->id($addressB->id)->status(null)->trashed(null)->exists())->toBeTrue();
});

it('lets an admin save an address for their own company', function () {
    $company = createTestCompany();
    $admin = createTestUserWithPassword('addr_admin_' . uniqid() . '@example.test');
    Plugin::getInstance()->companyMembers->addUserToCompany($admin->id, $company->id, CompanyRole::Admin);

    $client = httpClient();
    loginAs($client, $admin->email, httpTestPassword());

    $response = postAction($client, 'b2b-commerce/addresses/save', httpAddressAttributes());

    $addresses = Plugin::getInstance()->companyAddresses->getAddresses($company->id);

    foreach ($addresses as $address) {
        trackElement($address);
    }

    expect($response->getStatusCode())->toBe(200)
        ->and($addresses)->toHaveCount(1)
        ->and($addresses[0]->addressLine1)->toBe('12 Market Street')
        ->and($addresses[0]->getPrimaryOwnerId())->toBe($company->id);
});
