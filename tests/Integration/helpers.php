<?php

use craft\base\ElementInterface;
use craft\commerce\elements\Product;
use craft\commerce\elements\Variant;
use craft\commerce\models\ProductType;
use craft\commerce\models\ProductTypeSite;
use craft\commerce\Plugin as Commerce;
use craft\elements\User;
use totalwebcreations\b2bcommerce\elements\Company;

/**
 * Elements created during a test, hard-deleted afterwards.
 *
 * @var array<int, ElementInterface> $GLOBALS['b2bTrackedElements']
 */
$GLOBALS['b2bTrackedElements'] = [];

/**
 * Registers an element for automatic hard-delete in afterEach.
 */
function trackElement(ElementInterface $element): void
{
    $GLOBALS['b2bTrackedElements'][] = $element;
}

/**
 * Hard-deletes every tracked element and resets the tracker.
 */
function deleteTrackedElements(): void
{
    if ($GLOBALS['b2bTrackedElements'] === []) {
        return;
    }

    $elementsService = craftApp()->getElements();

    foreach (array_reverse($GLOBALS['b2bTrackedElements']) as $element) {
        $elementsService->deleteElement($element, true);
    }

    $GLOBALS['b2bTrackedElements'] = [];
}

/**
 * Creates and saves a tracked Company with a unique title.
 */
function createTestCompany(string $status = 'approved', string $title = 'Test Co'): Company
{
    $company = new Company();
    $company->title = $title . ' ' . uniqid();
    $company->companyStatus = $status;

    if (!craftApp()->getElements()->saveElement($company)) {
        throw new RuntimeException('Could not save test company: ' . implode(', ', $company->getFirstErrors()));
    }

    trackElement($company);

    return $company;
}

/**
 * Returns a reusable throwaway product type, creating it on first use. Product
 * types live in project config, so it is created once and reused across tests
 * rather than rebuilt (and torn down) per test.
 */
function quickOrderProductType(): ProductType
{
    $handle = 'b2bQuickOrderTest';
    $existing = Commerce::getInstance()->getProductTypes()->getProductTypeByHandle($handle);

    if ($existing !== null) {
        return $existing;
    }

    $type = new ProductType();
    $type->name = 'B2B Quick Order Test';
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
        throw new RuntimeException('Could not save test product type: ' . implode(', ', $type->getErrorSummary(true)));
    }

    return $type;
}

/**
 * Creates and saves a tracked product with a single variant carrying the given SKU.
 */
function createTestVariant(string $sku, float $price = 10.0, bool $enabled = true): Variant
{
    $product = new Product();
    $product->typeId = quickOrderProductType()->id;
    $product->title = 'Quick order ' . $sku;
    $product->enabled = $enabled;

    if (!craftApp()->getElements()->saveElement($product)) {
        throw new RuntimeException('Could not save test product: ' . implode(', ', $product->getErrorSummary(true)));
    }

    trackElement($product);

    $variant = new Variant();
    $variant->sku = $sku;
    $variant->setBasePrice($price);
    $variant->setPrimaryOwner($product);
    $variant->setOwner($product);

    if (!craftApp()->getElements()->saveElement($variant)) {
        throw new RuntimeException('Could not save test variant: ' . implode(', ', $variant->getErrorSummary(true)));
    }

    trackElement($variant);

    return $variant;
}

/**
 * Creates and saves a tracked User with a unique username and the given email.
 */
function createTestUser(string $email, bool $active = true): User
{
    $user = new User();
    $user->email = $email;
    $user->username = 'test_' . uniqid();
    $user->active = $active;

    if (!craftApp()->getElements()->saveElement($user)) {
        throw new RuntimeException('Could not save test user: ' . implode(', ', $user->getFirstErrors()));
    }

    trackElement($user);

    return $user;
}
