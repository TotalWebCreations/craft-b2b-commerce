<?php

namespace totalwebcreations\b2bcommerce\fieldlayoutelements;

use Craft;
use craft\base\ElementInterface;

/**
 * VAT / tax ID field for the Company main content area.
 */
class TaxIdField extends BaseCompanyTextField
{
    public string $attribute = 'taxId';

    protected function defaultLabel(?ElementInterface $element = null, bool $static = false): ?string
    {
        return Craft::t('b2b-commerce', 'Tax ID');
    }

    protected function defaultInstructions(?ElementInterface $element = null, bool $static = false): ?string
    {
        return Craft::t('b2b-commerce', 'EU VAT ID with country prefix (e.g. NL123456789B01). Validated against VIES when VAT ID validation is enabled in the plugin settings.');
    }
}
