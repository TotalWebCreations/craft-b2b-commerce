<?php

namespace totalwebcreations\b2bcommerce\services;

use Craft;
use craft\commerce\base\PurchasableInterface;
use craft\commerce\elements\conditions\products\CatalogPricingRuleProductCondition;
use craft\commerce\elements\db\ProductQuery;
use craft\commerce\elements\Product;
use craft\commerce\elements\Variant;
use craft\helpers\Json;
use RuntimeException;
use Throwable;
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
 *
 * isPurchasableAllowed() fails CLOSED: a present-but-unusable stored condition (corrupt JSON, a
 * non-array payload, a missing/invalid rule structure) or any exception raised while building or
 * matching the condition denies the purchasable rather than opening the full catalog. Only a
 * genuinely absent condition (column NULL, empty string, whitespace) grants the full catalog.
 */
class CompanyCatalog extends Component
{
    /**
     * Whether this company's members may buy the purchasable. A company with no catalog condition
     * has the full catalog (returns true). When a condition IS set, the purchasable must resolve to
     * a Commerce Product that matches it; a purchasable with no owning Product (a custom, non-Product
     * purchasable) is outside a product-defined catalog and is refused — fail-closed.
     *
     * Never throws: any failure while resolving/matching the condition (corrupt stored condition,
     * a tampered element type, a construction error, ...) is logged and treated as a denial, so
     * callers can rely on the return value unconditionally.
     */
    public function isPurchasableAllowed(PurchasableInterface $purchasable, Company $company): bool
    {
        try {
            return $this->evaluatePurchasableAllowed($purchasable, $company);
        } catch (Throwable $e) {
            Craft::warning(
                "Denying purchasable for company {$company->id}: catalog condition could not be evaluated ({$e->getMessage()}).",
                __METHOD__
            );

            return false;
        }
    }

    /**
     * Rehydrates the company's stored condition, or null when there is none / it carries no rules
     * (an empty builder must read as "full catalog", not "match nothing"). A present-but-unusable
     * stored condition also reads as null here, since this method backs convenience-only filtering
     * (see class docblock) and is not the enforcement path.
     */
    public function getConditionForCompany(Company $company): ?CatalogPricingRuleProductCondition
    {
        $stored = $company->catalogCondition;

        if ($stored === null || trim($stored) === '') {
            return null;
        }

        try {
            return $this->buildConditionFromStoredValue($stored);
        } catch (Throwable) {
            return null;
        }
    }

    /**
     * The enforcement path behind {@see isPurchasableAllowed()}. Left to throw on any unusable
     * input; the caller turns every exception into a denial.
     */
    private function evaluatePurchasableAllowed(PurchasableInterface $purchasable, Company $company): bool
    {
        $stored = $company->catalogCondition;

        if ($stored === null || trim($stored) === '') {
            return true;
        }

        $condition = $this->buildConditionFromStoredValue($stored);

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
     * Builds the matching-capable condition for a known non-null, non-empty stored value. Returns
     * null only when the stored value is well-formed and explicitly carries no rules (full catalog).
     * Any other unusable shape (unparseable JSON, a non-array payload, a missing/invalid
     * `conditionRules` structure, or a construction failure) throws, so present-but-corrupt input
     * never resolves to "no restriction".
     */
    private function buildConditionFromStoredValue(string $stored): ?CatalogPricingRuleProductCondition
    {
        $config = Json::decodeIfJson($stored);

        if (!is_array($config)) {
            throw new RuntimeException('Stored catalog condition is not a usable array.');
        }

        if (!array_key_exists('conditionRules', $config) || !is_array($config['conditionRules'])) {
            throw new RuntimeException('Stored catalog condition is missing a usable conditionRules structure.');
        }

        if ($config['conditionRules'] === []) {
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
