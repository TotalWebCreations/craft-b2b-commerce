<?php

namespace totalwebcreations\b2bcommerce\fieldlayoutelements;

use craft\base\ElementInterface;
use craft\fieldlayoutelements\BaseNativeField;
use craft\helpers\Cp;

/**
 * Shared base for the Company core text and number fields that render in the main content area.
 * Subclasses set the target attribute, label, and (for numbers) the input type and constraints.
 */
abstract class BaseCompanyTextField extends BaseNativeField
{
    public bool $mandatory = true;

    protected string $inputType = 'text';

    protected string|int|null $step = null;

    protected int|float|null $min = null;

    public function inputHtml(?ElementInterface $element = null, bool $static = false): ?string
    {
        return Cp::textHtml([
            'type' => $this->inputType,
            'id' => $this->id(),
            'name' => $this->attribute(),
            'value' => $element?->{$this->attribute()},
            'step' => $this->step,
            'min' => $this->min,
            'disabled' => $static,
        ]);
    }
}
