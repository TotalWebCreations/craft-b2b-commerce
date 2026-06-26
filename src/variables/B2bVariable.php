<?php

namespace totalwebcreations\b2bcommerce\variables;

use Craft;
use craft\elements\Address;
use craft\elements\User;
use totalwebcreations\b2bcommerce\elements\Company;
use totalwebcreations\b2bcommerce\Plugin;
use yii\base\InvalidArgumentException;

class B2bVariable
{
    public function getCanViewPrices(): bool
    {
        return Plugin::getInstance()->priceVisibility->canViewPrices(
            Craft::$app->getUser()->getIdentity()
        );
    }

    public function getCanPurchase(): bool
    {
        return Plugin::getInstance()->priceVisibility->canPurchase(
            Craft::$app->getUser()->getIdentity()
        );
    }

    public function getCompany(): ?Company
    {
        $user = Craft::$app->getUser()->getIdentity();

        if ($user === null) {
            return null;
        }

        return Plugin::getInstance()->companyMembers->getCompanyForUser($user->id);
    }

    /** @return array<int, array{user: User, role: string}> */
    public function getTeamMembers(): array
    {
        $company = $this->getCompany();

        if ($company === null) {
            return [];
        }

        $rows = [];

        foreach (Plugin::getInstance()->companyMembers->getMemberUsers($company->id) as $member) {
            $rows[] = [
                'user' => $member['user'],
                'role' => $member['role']->value,
            ];
        }

        return $rows;
    }

    /** @return array<int, Address> */
    public function getCompanyAddresses(): array
    {
        $company = $this->getCompany();

        if ($company === null) {
            return [];
        }

        return Plugin::getInstance()->companyAddresses->getAddresses($company->id);
    }

    /** @return array<int, array{id: int, name: string, createdByUserId: ?int, itemCount: int}> */
    public function getOrderLists(): array
    {
        $company = $this->getCompany();

        if ($company === null) {
            return [];
        }

        return Plugin::getInstance()->orderLists->getLists($company->id);
    }

    /** @return array<int, array{purchasableId: int, qty: int, sku: string, description: ?string}> */
    public function getOrderListItems(int $listId): array
    {
        $company = $this->getCompany();

        if ($company === null) {
            return [];
        }

        try {
            return Plugin::getInstance()->orderLists->getItems($company, $listId);
        } catch (InvalidArgumentException) {
            return [];
        }
    }
}
