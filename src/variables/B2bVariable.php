<?php

namespace totalwebcreations\b2bcommerce\variables;

use Craft;
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
}
