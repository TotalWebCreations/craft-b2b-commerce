<?php

use craft\commerce\elements\Product;
use craft\commerce\elements\Variant;
use craft\commerce\elements\conditions\products\CatalogPricingRuleProductCondition;
use craft\commerce\elements\conditions\products\ProductTypeConditionRule;
use craft\commerce\models\ProductType;
use craft\commerce\models\ProductTypeSite;
use craft\commerce\Plugin as Commerce;
use craft\helpers\Json;
use totalwebcreations\b2bcommerce\elements\Company;
use totalwebcreations\b2bcommerce\Plugin;

it('persists and reloads a company catalog condition as raw JSON', function () {
    $json = '{"class":"craft\\\\commerce\\\\elements\\\\conditions\\\\products\\\\CatalogPricingRuleProductCondition","conditionRules":[]}';

    $company = createTestCompany('approved', 'Catalog Persist Co');
    $company->catalogCondition = $json;

    expect(craftApp()->getElements()->saveElement($company))->toBeTrue();

    $reloaded = Company::find()->id($company->id)->status(null)->one();

    expect($reloaded->catalogCondition)->toBe($json);
});

it('leaves the catalog condition null by default (full catalog)', function () {
    $company = createTestCompany('approved', 'Catalog Null Co');

    expect(craftApp()->getElements()->saveElement($company))->toBeTrue();

    $reloaded = Company::find()->id($company->id)->status(null)->one();

    expect($reloaded->catalogCondition)->toBeNull();
});

/**
 * A second throwaway product type, so a catalog condition scoped to the default
 * quick-order type refuses a variant built under this one.
 */
function catalogOtherProductType(): ProductType
{
    $handle = 'b2bCatalogOtherTest';
    $existing = Commerce::getInstance()->getProductTypes()->getProductTypeByHandle($handle);

    if ($existing !== null) {
        return $existing;
    }

    $type = new ProductType();
    $type->name = 'B2B Catalog Other Test';
    $type->handle = $handle;
    $type->maxVariants = 1;
    $type->hasVariantTitleField = false;
    $type->variantTitleFormat = '{sku}';

    $siteSettings = [];

    foreach (craftApp()->getSites()->getAllSites() as $site) {
        $siteSetting = new ProductTypeSite();
        $siteSetting->siteId = $site->id;
        $siteSetting->hasUrls = false;
        $siteSettings[$site->id] = $siteSetting;
    }

    $type->setSiteSettings($siteSettings);

    if (!Commerce::getInstance()->getProductTypes()->saveProductType($type)) {
        throw new RuntimeException('Could not save catalog test product type: ' . implode(', ', $type->getErrorSummary(true)));
    }

    return $type;
}

/**
 * Creates a tracked single-variant product under the given type, returning the variant.
 */
function createTestVariantOfType(ProductType $type, string $sku, float $price = 10.0): Variant
{
    $product = new Product();
    $product->typeId = $type->id;
    $product->title = 'Catalog ' . $sku;
    $product->enabled = true;

    if (!craftApp()->getElements()->saveElement($product)) {
        throw new RuntimeException('Could not save catalog test product: ' . implode(', ', $product->getErrorSummary(true)));
    }

    trackElement($product);

    $variant = new Variant();
    $variant->sku = $sku;
    $variant->setBasePrice($price);
    $variant->setPrimaryOwner($product);
    $variant->setOwner($product);

    if (!craftApp()->getElements()->saveElement($variant)) {
        throw new RuntimeException('Could not save catalog test variant: ' . implode(', ', $variant->getErrorSummary(true)));
    }

    trackElement($variant);

    return $variant;
}

/**
 * A JSON catalog condition matching only products of the given type.
 */
function catalogConditionForType(ProductType $type): string
{
    $condition = new CatalogPricingRuleProductCondition();
    $condition->elementType = Product::class;

    $rule = new ProductTypeConditionRule();
    $rule->setValues([$type->uid]);

    $condition->addConditionRule($rule);

    return Json::encode($condition->getConfig());
}

it('allows any purchasable when the company has no catalog condition', function () {
    $company = createTestCompany('approved', 'Catalog Unrestricted Co');
    $variant = createTestVariant('CAT-UNR-' . substr(uniqid(), -6));

    expect(Plugin::getInstance()->companyCatalog->isPurchasableAllowed($variant, $company))->toBeTrue();
});

it('allows a purchasable whose product matches the condition', function () {
    $company = createTestCompany('approved', 'Catalog Allow Co');
    $company->catalogCondition = catalogConditionForType(quickOrderProductType());
    craftApp()->getElements()->saveElement($company);

    $allowed = createTestVariant('CAT-ALLOW-' . substr(uniqid(), -6));

    expect(Plugin::getInstance()->companyCatalog->isPurchasableAllowed($allowed, $company))->toBeTrue();
});

it('refuses a purchasable whose product does not match the condition', function () {
    $company = createTestCompany('approved', 'Catalog Refuse Co');
    $company->catalogCondition = catalogConditionForType(quickOrderProductType());
    craftApp()->getElements()->saveElement($company);

    $restricted = createTestVariantOfType(catalogOtherProductType(), 'CAT-DENY-' . substr(uniqid(), -6));

    expect(Plugin::getInstance()->companyCatalog->isPurchasableAllowed($restricted, $company))->toBeFalse();
});

it('returns null for an empty or rule-less stored condition', function () {
    $company = createTestCompany('approved', 'Catalog Empty Co');

    expect(Plugin::getInstance()->companyCatalog->getConditionForCompany($company))->toBeNull();

    $company->catalogCondition = '{"class":"craft\\\\commerce\\\\elements\\\\conditions\\\\products\\\\CatalogPricingRuleProductCondition","conditionRules":[]}';

    expect(Plugin::getInstance()->companyCatalog->getConditionForCompany($company))->toBeNull();
});
