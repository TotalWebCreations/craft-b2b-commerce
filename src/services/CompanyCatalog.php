<?php

namespace totalwebcreations\b2bcommerce\services;

use Craft;
use craft\commerce\base\PurchasableInterface;
use craft\commerce\elements\conditions\products\CatalogPricingRuleProductCondition;
use craft\commerce\elements\db\ProductQuery;
use craft\commerce\elements\Product;
use craft\commerce\elements\Variant;
use craft\helpers\Json;
use totalwebcreations\b2bcommerce\elements\Company;
use yii\base\Component;

/**
 * Company-specific catalog evaluation. The stored condition is a Commerce product condition-builder
 * condition (CatalogPricingRuleProductCondition); a null/empty condition means the full catalog.
 *
 * isPurchasableAllowed() is the AUTHORITATIVE server-side check intended to back the add-to-cart veto
 * across every add path — the security boundary. That veto is wired in a later batch; this service is
 * the evaluator it will call. applyToProductQuery()/any storefront visibility filtering built on top
 * are convenience-only and must never be relied on for enforcement.
 */
class CompanyCatalog extends Component
{
    /**
     * Whether this company's members may buy the purchasable. A company with no catalog condition
     * has the full catalog (returns true). When a condition IS set, the purchasable must resolve to
     * a Commerce Product that matches it; a purchasable with no owning Product (a custom, non-Product
     * purchasable) is outside a product-defined catalog and is refused — fail-closed.
     */
    public function isPurchasableAllowed(PurchasableInterface $purchasable, Company $company): bool
    {
        $condition = $this->getConditionForCompany($company);

        if ($condition === null) {
            return true;
        }

        $product = $this->resolveProduct($purchasable);

        if ($product === null) {
            return false;
        }

        return $condition->matchElement($product);
    }

    /**
     * Rehydrates the company's stored condition, or null when there is none / it carries no rules
     * (an empty builder must read as "full catalog", not "match nothing").
     */
    public function getConditionForCompany(Company $company): ?CatalogPricingRuleProductCondition
    {
        $stored = $company->catalogCondition;

        if ($stored === null || trim($stored) === '') {
            return null;
        }

        $config = Json::decodeIfJson($stored);

        if (!is_array($config) || ($config['conditionRules'] ?? []) === []) {
            return null;
        }

        $config['class'] = CatalogPricingRuleProductCondition::class;

        /** @var CatalogPricingRuleProductCondition $condition */
        $condition = Craft::$app->getConditions()->createCondition($config);
        $condition->elementType = Product::class;

        return $condition;
    }

    /**
     * Applies the company's condition to a product query (convenience filtering). Unrestricted
     * companies and null companies pass the query through untouched.
     */
    public function applyToProductQuery(ProductQuery $query, ?Company $company): ProductQuery
    {
        if ($company === null) {
            return $query;
        }

        $condition = $this->getConditionForCompany($company);

        if ($condition === null) {
            return $query;
        }

        $condition->modifyQuery($query);

        return $query;
    }

    /**
     * Normalizes a posted condition-builder value into the JSON stored on the company, or null when
     * the builder carries no rules (so an emptied builder reverts the company to the full catalog).
     */
    public function normalizeStoredCondition(mixed $value): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }

        if (is_string($value)) {
            $value = Json::decodeIfJson($value);
        }

        if (!is_array($value)) {
            return null;
        }

        $value['class'] = CatalogPricingRuleProductCondition::class;

        /** @var CatalogPricingRuleProductCondition $condition */
        $condition = Craft::$app->getConditions()->createCondition($value);

        if ($condition->getConditionRules() === []) {
            return null;
        }

        return Json::encode($condition->getConfig());
    }

    private function resolveProduct(PurchasableInterface $purchasable): ?Product
    {
        if ($purchasable instanceof Product) {
            return $purchasable;
        }

        if ($purchasable instanceof Variant) {
            return $purchasable->getProduct();
        }

        return null;
    }
}
