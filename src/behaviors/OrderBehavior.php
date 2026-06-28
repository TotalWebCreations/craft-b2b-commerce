<?php

namespace totalwebcreations\b2bcommerce\behaviors;

use DateInterval;
use DateTime;
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

    /**
     * The date this order's invoice is due: the order date plus the company's payment term.
     * Null when the order is not completed, has no order date, is not linked to a company, or
     * the company has no payment term configured.
     */
    public function getB2bPaymentDueDate(): ?DateTime
    {
        if (!$this->owner->isCompleted) {
            return null;
        }

        $dateOrdered = $this->owner->dateOrdered;

        if ($dateOrdered === null) {
            return null;
        }

        $company = $this->getB2bCompany();

        if ($company === null || $company->paymentTermDays === null) {
            return null;
        }

        $dueDate = clone $dateOrdered;
        $dueDate->add(new DateInterval("P{$company->paymentTermDays}D"));

        return $dueDate;
    }
}
