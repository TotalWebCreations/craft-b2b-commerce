<?php

namespace totalwebcreations\b2bcommerce\fieldlayoutelements;

use Craft;
use craft\base\ElementInterface;

/**
 * Registration number field for the Company main content area.
 */
class RegistrationNumberField extends BaseCompanyTextField
{
    public string $attribute = 'registrationNumber';

    protected function defaultLabel(?ElementInterface $element = null, bool $static = false): ?string
    {
        return Craft::t('b2b-commerce', 'Registration number');
    }

    protected function defaultInstructions(?ElementInterface $element = null, bool $static = false): ?string
    {
        return Craft::t('b2b-commerce', 'Company registration number (e.g. chamber of commerce).');
    }
}
