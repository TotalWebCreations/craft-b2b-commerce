<?php

namespace totalwebcreations\b2bcommerce\fieldlayoutelements;

use Craft;
use craft\base\ElementInterface;
use craft\commerce\elements\conditions\products\CatalogPricingRuleProductCondition;
use craft\commerce\elements\Product;
use craft\fieldlayoutelements\BaseNativeField;
use totalwebcreations\b2bcommerce\elements\Company;
use totalwebcreations\b2bcommerce\Plugin;

/**
 * Company product-catalog condition builder for the Company main content area. Renders Commerce's
 * product condition builder; an empty condition means the full catalog, so the feature stays dormant
 * until a merchant adds a rule.
 *
 * SECURITY NOTE: this field only captures the merchant's intent. Server-side enforcement lives in
 * CompanyCatalog::isPurchasableAllowed() — this field, and any storefront filtering built on top of
 * it, is never itself the authoritative catalog boundary.
 */
class CatalogConditionField extends BaseNativeField
{
    public bool $mandatory = true;

    public string $attribute = 'catalogCondition';

    public function __construct(array $config = [])
    {
        unset($config['required']);

        parent::__construct($config);
    }

    public function inputHtml(?ElementInterface $element = null, bool $static = false): ?string
    {
        $condition = $this->conditionFor($element);
        $condition->name = $this->attribute();

        return $condition->getBuilderHtml();
    }

    protected function defaultLabel(?ElementInterface $element = null, bool $static = false): ?string
    {
        return Craft::t('b2b-commerce', 'Product catalog');
    }

    protected function defaultInstructions(?ElementInterface $element = null, bool $static = false): ?string
    {
        return Craft::t(
            'b2b-commerce',
            'Restrict which products this company’s members may see and buy. Leave empty to allow the full catalog.'
        );
    }

    private function conditionFor(?ElementInterface $element): CatalogPricingRuleProductCondition
    {
        if ($element instanceof Company) {
            $stored = Plugin::getInstance()->companyCatalog->getConditionForCompany($element);

            if ($stored !== null) {
                $stored->mainTag = 'div';

                return $stored;
            }
        }

        $condition = new CatalogPricingRuleProductCondition();
        $condition->mainTag = 'div';
        $condition->elementType = Product::class;

        return $condition;
    }
}
