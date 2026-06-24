<?php

use craft\elements\Address;
use totalwebcreations\b2bcommerce\Plugin;
use yii\base\InvalidArgumentException;

/**
 * @param array<string, mixed> $overrides
 * @return array<string, mixed>
 */
function addressAttributes(array $overrides = []): array
{
    return array_merge([
        'title' => 'Head office',
        'fullName' => 'Jane Buyer',
        'addressLine1' => '12 Market Street',
        'postalCode' => '1011AB',
        'locality' => 'Amsterdam',
        'countryCode' => 'NL',
    ], $overrides);
}

it('adds an address to a company and lists it', function () {
    $company = createTestCompany();

    $address = Plugin::getInstance()->companyAddresses->saveAddress($company, addressAttributes());
    trackElement($address);

    $addresses = Plugin::getInstance()->companyAddresses->getAddresses($company->id);

    expect($addresses)->toHaveCount(1)
        ->and($addresses[0]->id)->toBe($address->id)
        ->and($addresses[0]->addressLine1)->toBe('12 Market Street')
        ->and($addresses[0]->getPrimaryOwnerId())->toBe($company->id);
});

it('scopes the list to the owning company', function () {
    $companyA = createTestCompany();
    $companyB = createTestCompany();

    $addressA = Plugin::getInstance()->companyAddresses->saveAddress($companyA, addressAttributes());
    trackElement($addressA);

    expect(Plugin::getInstance()->companyAddresses->getAddresses($companyB->id))->toBe([]);
});

it('updates an address owned by the company', function () {
    $company = createTestCompany();
    $address = Plugin::getInstance()->companyAddresses->saveAddress($company, addressAttributes());
    trackElement($address);

    Plugin::getInstance()->companyAddresses->saveAddress(
        $company,
        addressAttributes(['addressLine1' => '99 New Road']),
        $address->id,
    );

    $addresses = Plugin::getInstance()->companyAddresses->getAddresses($company->id);

    expect($addresses)->toHaveCount(1)
        ->and($addresses[0]->id)->toBe($address->id)
        ->and($addresses[0]->addressLine1)->toBe('99 New Road');
});

it('refuses to save an address that belongs to another company', function () {
    $companyA = createTestCompany();
    $companyB = createTestCompany();

    $address = Plugin::getInstance()->companyAddresses->saveAddress($companyA, addressAttributes());
    trackElement($address);

    expect(fn () => Plugin::getInstance()->companyAddresses->saveAddress(
        $companyB,
        addressAttributes(),
        $address->id,
    ))->toThrow(InvalidArgumentException::class, 'This address does not belong to this company.');
});

it('refuses to delete an address that belongs to another company', function () {
    $companyA = createTestCompany();
    $companyB = createTestCompany();

    $address = Plugin::getInstance()->companyAddresses->saveAddress($companyA, addressAttributes());
    trackElement($address);

    expect(fn () => Plugin::getInstance()->companyAddresses->deleteAddress($companyB, $address->id))
        ->toThrow(InvalidArgumentException::class, 'This address does not belong to this company.');
});

it('deletes an address and removes it from the list', function () {
    $company = createTestCompany();
    $address = Plugin::getInstance()->companyAddresses->saveAddress($company, addressAttributes());
    trackElement($address);

    Plugin::getInstance()->companyAddresses->deleteAddress($company, $address->id);

    expect(Plugin::getInstance()->companyAddresses->getAddresses($company->id))->toBe([]);
});

it('cascades address deletion when the owning company is hard-deleted', function () {
    $company = createTestCompany();
    $address = Plugin::getInstance()->companyAddresses->saveAddress($company, addressAttributes());
    $addressId = $address->id;

    // Hard-delete the company: addresses.primaryOwnerId has an ON DELETE CASCADE
    // foreign key to elements.id, so the address row is removed with it.
    craftApp()->getElements()->deleteElement($company, true);
    $GLOBALS['b2bTrackedElements'] = array_values(array_filter(
        $GLOBALS['b2bTrackedElements'],
        fn ($tracked) => $tracked->id !== $company->id,
    ));

    expect(Address::find()->id($addressId)->status(null)->trashed(null)->exists())->toBeFalse();
});
