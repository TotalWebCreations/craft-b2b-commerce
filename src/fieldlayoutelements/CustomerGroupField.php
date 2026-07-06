<?php

namespace totalwebcreations\b2bcommerce\fieldlayoutelements;

use Craft;
use craft\base\ElementInterface;
use craft\fieldlayoutelements\BaseNativeField;
use craft\helpers\Cp;
use craft\models\UserGroup;

/**
 * Pricing user-group selector for the Company main content area. The selected Craft user group is
 * the group the plugin keeps the company's approved members in, so native Commerce catalog pricing
 * rules with a customer condition on that group give the company its wholesale prices.
 */
class CustomerGroupField extends BaseNativeField
{
    public bool $mandatory = true;

    public string $attribute = 'customerGroupId';

    public function __construct(array $config = [])
    {
        unset($config['required']);

        parent::__construct($config);
    }

    public function inputHtml(?ElementInterface $element = null, bool $static = false): ?string
    {
        return Cp::selectHtml([
            'id' => $this->id(),
            'name' => $this->attribute(),
            'options' => $this->groupOptions(),
            'value' => $element?->{$this->attribute()},
            'disabled' => $static,
        ]);
    }

    protected function defaultLabel(?ElementInterface $element = null, bool $static = false): ?string
    {
        return Craft::t('b2b-commerce', 'Pricing group');
    }

    protected function defaultInstructions(?ElementInterface $element = null, bool $static = false): ?string
    {
        return Craft::t(
            'b2b-commerce',
            'Approved members are placed in this user group so Commerce catalog pricing rules targeting the group give them their prices. Use a group with no control-panel or admin permissions.'
        );
    }

    /**
     * @return array<int, array{value: string, label: string}>
     */
    private function groupOptions(): array
    {
        $options = [
            ['value' => '', 'label' => Craft::t('b2b-commerce', 'No pricing group')],
        ];

        foreach (Craft::$app->getUserGroups()->getAllGroups() as $group) {
            /** @var UserGroup $group */
            $options[] = ['value' => (string) $group->id, 'label' => $group->name];
        }

        return $options;
    }
}
