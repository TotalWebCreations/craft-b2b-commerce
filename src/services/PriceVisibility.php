<?php

namespace totalwebcreations\b2bcommerce\services;

use craft\elements\User;
use totalwebcreations\b2bcommerce\elements\Company;
use totalwebcreations\b2bcommerce\Plugin;
use yii\base\Component;

class PriceVisibility extends Component
{
    public function canViewPrices(?User $user): bool
    {
        if (!Plugin::getInstance()->getSettings()->hidePricesForGuests) {
            return true;
        }

        if ($user === null) {
            return false;
        }

        $company = Plugin::getInstance()->companyMembers->getCompanyForUser($user->id);

        return $company?->companyStatus === Company::STATUS_APPROVED;
    }

    public function canPurchase(?User $user): bool
    {
        return $this->canViewPrices($user);
    }
}
