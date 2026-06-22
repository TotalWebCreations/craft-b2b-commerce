<?php

namespace totalwebcreations\b2bcommerce\behaviors;

use craft\commerce\elements\Order;
use totalwebcreations\b2bcommerce\elements\Company;
use totalwebcreations\b2bcommerce\Plugin;
use yii\base\Behavior;

/** @property Order $owner */
class OrderBehavior extends Behavior
{
    private Company|false|null $b2bCompany = false;

    public function getB2bCompany(): ?Company
    {
        if ($this->b2bCompany !== false) {
            return $this->b2bCompany;
        }

        if ($this->owner->id === null) {
            return $this->b2bCompany = null;
        }

        return $this->b2bCompany = Plugin::getInstance()->orderCompanyLink->getCompanyForOrder($this->owner->id);
    }
}
