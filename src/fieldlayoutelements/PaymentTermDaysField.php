<?php

namespace totalwebcreations\b2bcommerce\fieldlayoutelements;

use Craft;
use craft\base\ElementInterface;

/**
 * Payment term (days) field for the Company main content area.
 */
class PaymentTermDaysField extends BaseCompanyTextField
{
    public string $attribute = 'paymentTermDays';

    protected string $inputType = 'number';

    protected int|float|null $min = 0;

    protected function defaultLabel(?ElementInterface $element = null, bool $static = false): ?string
    {
        return Craft::t('b2b-commerce', 'Payment term (days)');
    }
}
