<?php

namespace totalwebcreations\b2bcommerce\fieldlayoutelements;

use Craft;
use craft\base\ElementInterface;
use craft\fieldlayoutelements\TitleField;

/**
 * Company name field (backed by the element title) for the main content area.
 */
class CompanyTitleField extends TitleField
{
    public function defaultLabel(?ElementInterface $element = null, bool $static = false): ?string
    {
        return Craft::t('b2b-commerce', 'Name');
    }
}
