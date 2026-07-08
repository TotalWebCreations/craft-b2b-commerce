<?php

namespace totalwebcreations\b2bcommerce\fieldlayoutelements;

use Craft;
use craft\base\ElementInterface;
use craft\fieldlayoutelements\BaseNativeField;
use craft\helpers\Cp;

/**
 * Require-purchase-order lightswitch field for the Company main content area.
 */
class RequirePoNumberField extends BaseNativeField
{
    public bool $mandatory = true;

    public string $attribute = 'requirePoNumber';

    public function __construct(array $config = [])
    {
        unset($config['required']);

        parent::__construct($config);
    }

    public function inputHtml(?ElementInterface $element = null, bool $static = false): ?string
    {
        return Cp::lightswitchHtml([
            'id' => $this->id(),
            'name' => $this->attribute(),
            'on' => (bool) $element?->{$this->attribute()},
            'disabled' => $static,
        ]);
    }

    protected function defaultLabel(?ElementInterface $element = null, bool $static = false): ?string
    {
        return Craft::t('b2b-commerce', 'Require purchase order number');
    }

    protected function defaultInstructions(?ElementInterface $element = null, bool $static = false): ?string
    {
        return Craft::t('b2b-commerce', 'Require a purchase order number before this company can complete an order.');
    }
}
