<?php

namespace totalwebcreations\b2bcommerce\fieldlayoutelements;

use Craft;
use craft\base\ElementInterface;

/**
 * Credit limit field for the Company main content area.
 */
class CreditLimitField extends BaseCompanyTextField
{
    public string $attribute = 'creditLimit';

    protected string $inputType = 'number';

    protected string|int|null $step = 'any';

    protected int|float|null $min = 0;

    protected function defaultLabel(?ElementInterface $element = null, bool $static = false): ?string
    {
        return Craft::t('b2b-commerce', 'Credit limit');
    }
}
