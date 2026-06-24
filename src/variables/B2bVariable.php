<?php

namespace totalwebcreations\b2bcommerce\variables;

use Craft;
use craft\elements\Address;
use craft\elements\User;
use totalwebcreations\b2bcommerce\elements\Company;
use totalwebcreations\b2bcommerce\Plugin;

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

        $members = Plugin::getInstance()->companyMembers->getMembers($company->id);

        if ($members === []) {
            return [];
        }

        $userIds = array_column($members, 'userId');

        /** @var array<int, User> $users */
        $users = User::find()
            ->id($userIds)
            ->status(null)
            ->indexBy('id')
            ->all();

        $rows = [];

        foreach ($members as $member) {
            $user = $users[$member['userId']] ?? null;

            if ($user === null) {
                continue;
            }

            $rows[] = [
                'user' => $user,
                'role' => $member['role'],
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
}
