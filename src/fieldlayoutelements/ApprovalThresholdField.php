<?php

namespace totalwebcreations\b2bcommerce\fieldlayoutelements;

use Craft;
use craft\base\ElementInterface;

/**
 * Approval threshold field for the Company main content area.
 */
class ApprovalThresholdField extends BaseCompanyTextField
{
    public string $attribute = 'approvalThreshold';

    protected string $inputType = 'number';

    protected string|int|null $step = 'any';

    protected int|float|null $min = 0;

    protected function defaultLabel(?ElementInterface $element = null, bool $static = false): ?string
    {
        return Craft::t('b2b-commerce', 'Approval threshold');
    }

    protected function defaultInstructions(?ElementInterface $element = null, bool $static = false): ?string
    {
        return Craft::t('b2b-commerce', 'Orders above this amount require approval. Leave empty to disable.');
    }
}
