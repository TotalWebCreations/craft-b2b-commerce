<?php

namespace totalwebcreations\b2bcommerce\fieldlayoutelements;

use Craft;
use craft\base\ElementInterface;
use craft\fieldlayoutelements\BaseNativeField;
use craft\helpers\Cp;

/**
 * Pay on account lightswitch field for the Company main content area.
 */
class AllowInvoicePaymentField extends BaseNativeField
{
    public bool $mandatory = true;

    public string $attribute = 'allowInvoicePayment';

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
        return Craft::t('b2b-commerce', 'Allow pay on account');
    }
}
