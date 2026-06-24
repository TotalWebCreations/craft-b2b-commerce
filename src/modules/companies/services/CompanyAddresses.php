<?php

namespace totalwebcreations\b2bcommerce\modules\companies\services;

use Craft;
use craft\elements\Address;
use totalwebcreations\b2bcommerce\elements\Company;
use yii\base\Component;
use yii\base\InvalidArgumentException;

class CompanyAddresses extends Component
{
    /** @return array<int, Address> */
    public function getAddresses(int $companyId): array
    {
        return Address::find()
            ->ownerId($companyId)
            ->status(null)
            ->all();
    }

    /**
     * @param array<string, mixed> $attributes
     */
    public function saveAddress(Company $company, array $attributes, ?int $addressId = null): Address
    {
        $address = $this->resolveAddress($company, $addressId);

        $address->setAttributes($attributes);
        $address->setOwnerId($company->id);

        if (!Craft::$app->getElements()->saveElement($address)) {
            throw new InvalidArgumentException(implode(' ', $address->getFirstErrors()));
        }

        return $address;
    }

    public function deleteAddress(Company $company, int $addressId): void
    {
        $address = $this->requireOwnedAddress($company, $addressId);

        Craft::$app->getElements()->deleteElement($address);
    }

    private function resolveAddress(Company $company, ?int $addressId): Address
    {
        if ($addressId === null) {
            return new Address();
        }

        return $this->requireOwnedAddress($company, $addressId);
    }

    private function requireOwnedAddress(Company $company, int $addressId): Address
    {
        $address = Address::find()
            ->id($addressId)
            ->status(null)
            ->one();

        if ($address === null || $address->getPrimaryOwnerId() !== $company->id) {
            throw new InvalidArgumentException(
                Craft::t('b2b-commerce', 'This address does not belong to this company.')
            );
        }

        return $address;
    }
}
