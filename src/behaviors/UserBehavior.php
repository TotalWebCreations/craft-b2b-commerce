<?php

namespace totalwebcreations\b2bcommerce\behaviors;

use craft\elements\User;
use totalwebcreations\b2bcommerce\elements\Company;
use totalwebcreations\b2bcommerce\Plugin;
use yii\base\Behavior;

/** @property User $owner */
class UserBehavior extends Behavior
{
    public function getB2bCompany(): ?Company
    {
        if ($this->owner->id === null) {
            return null;
        }

        return Plugin::getInstance()->companyMembers->getCompanyForUser($this->owner->id);
    }
}
