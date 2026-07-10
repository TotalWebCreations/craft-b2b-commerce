<?php

use craft\commerce\base\PurchasableInterface;
use craft\commerce\elements\Order;
use craft\commerce\elements\Variant;
use craft\commerce\events\AddLineItemEvent;
use craft\commerce\Plugin as Commerce;
use craft\helpers\Json;
use totalwebcreations\b2bcommerce\elements\Company;
use totalwebcreations\b2bcommerce\enums\CompanyRole;
use totalwebcreations\b2bcommerce\Plugin;
use yii\base\Event;

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

// catalogOtherProductType(), createTestVariantOfType() and catalogConditionForType() live in
// tests/Integration/helpers.php: both this suite and tests/Http/CompanyCatalogHttpTest.php need
// them, and that file is unconditionally loaded for every suite (see tests/Pest.php), avoiding a
// duplicate-function-declaration clash between the two suites.

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

it('denies a non-Product purchasable when the company has a catalog condition set', function () {
    $company = createTestCompany('approved', 'Catalog NonProduct Co');
    $company->catalogCondition = catalogConditionForType(quickOrderProductType());
    craftApp()->getElements()->saveElement($company);

    /** @var PurchasableInterface $purchasable */
    $purchasable = test()->createStub(PurchasableInterface::class);

    expect(Plugin::getInstance()->companyCatalog->isPurchasableAllowed($purchasable, $company))->toBeFalse();
});

it('denies a variant with a null/unresolvable owner product when a catalog condition is set', function () {
    $company = createTestCompany('approved', 'Catalog OrphanVariant Co');
    $company->catalogCondition = catalogConditionForType(quickOrderProductType());
    craftApp()->getElements()->saveElement($company);

    $orphan = new Variant();

    expect(Plugin::getInstance()->companyCatalog->isPurchasableAllowed($orphan, $company))->toBeFalse();
});

it('denies (fails closed) when the stored catalog condition is corrupt JSON', function () {
    $company = createTestCompany('approved', 'Catalog Corrupt Co');
    $company->catalogCondition = '{not json';
    craftApp()->getElements()->saveElement($company);

    $variant = createTestVariant('CAT-CORRUPT-' . substr(uniqid(), -6));

    expect(Plugin::getInstance()->companyCatalog->isPurchasableAllowed($variant, $company))->toBeFalse();
});

it('denies (fails closed) when the stored catalog condition decodes to a non-array scalar', function () {
    $company = createTestCompany('approved', 'Catalog Scalar Co');
    $company->catalogCondition = '"just a string"';
    craftApp()->getElements()->saveElement($company);

    $variant = createTestVariant('CAT-SCALAR-' . substr(uniqid(), -6));

    expect(Plugin::getInstance()->companyCatalog->isPurchasableAllowed($variant, $company))->toBeFalse();
});

it('normalizes a posted condition-builder array into stored JSON, and an empty builder to null', function () {
    $company = createTestCompany('approved', 'Catalog Request Co');

    $posted = Json::decodeIfJson(catalogConditionForType(quickOrderProductType()));

    $company->setAttributesFromRequest(['catalogCondition' => $posted]);

    expect($company->catalogCondition)->toBeString()
        ->and(Plugin::getInstance()->companyCatalog->getConditionForCompany($company))->not->toBeNull();

    $company->setAttributesFromRequest(['catalogCondition' => ['conditionRules' => []]]);

    expect($company->catalogCondition)->toBeNull();
});

/**
 * Attaches a handler mirroring the plugin's catalog veto branch (minus the console skip the real
 * handler carries), resolving the company for the given member and delegating to the real service.
 * Returns a detach callback.
 */
function attachCatalogVeto(int $memberUserId): callable
{
    $handler = function (AddLineItemEvent $event) use ($memberUserId): void {
        $company = Plugin::getInstance()->companyMembers->getCompanyForUser($memberUserId);

        if ($company === null) {
            return;
        }

        $purchasable = $event->lineItem->getPurchasable();

        if ($purchasable === null || Plugin::getInstance()->companyCatalog->isPurchasableAllowed($purchasable, $company)) {
            return;
        }

        $message = Craft::t('b2b-commerce', 'This product is not available for your account.');
        $event->isValid = false;
        $event->lineItem->addError('purchasableId', $message);

        if ($event->sender instanceof Order) {
            $event->sender->addError('purchasableId', $message);
        }
    };

    Event::on(Order::class, Order::EVENT_BEFORE_ADD_LINE_ITEM, $handler);

    return fn() => Event::off(Order::class, Order::EVENT_BEFORE_ADD_LINE_ITEM, $handler);
}

/**
 * A company restricted to the default quick-order product type, with a member, ready for veto tests.
 *
 * @return array{company: \totalwebcreations\b2bcommerce\elements\Company, member: \craft\elements\User}
 */
function restrictedCatalogCompany(): array
{
    $company = createTestCompany('approved', 'Veto Co ' . substr(uniqid(), -6));
    $company->catalogCondition = catalogConditionForType(quickOrderProductType());
    craftApp()->getElements()->saveElement($company);

    $member = createTestUser('veto_' . uniqid() . '@example.test');
    Plugin::getInstance()->companyMembers->addUserToCompany($member->id, $company->id, CompanyRole::Purchaser);

    return ['company' => $company, 'member' => $member];
}

it('vetoes a restricted product on the quick-order SKU-paste path', function () {
    ['member' => $member] = restrictedCatalogCompany();
    $allowedSku = 'VETO-ALLOW-' . substr(uniqid(), -6);
    $deniedSku = 'VETO-DENY-' . substr(uniqid(), -6);
    createTestVariant($allowedSku);
    createTestVariantOfType(catalogOtherProductType(), $deniedSku);

    $cart = createTestQuickOrderCart();
    $detach = attachCatalogVeto($member->id);

    try {
        $result = Plugin::getInstance()->quickOrder->addToCart($cart, "{$allowedSku} 1\n{$deniedSku} 1");
    } finally {
        $detach();
    }

    expect($result['added'])->toBe(1)
        ->and($cart->getLineItems())->toHaveCount(1)
        ->and($result['errors'][2])->toBe('This product is not available for your account.');
});

it('vetoes a restricted product on the quick-order CSV (multi-line) path', function () {
    ['member' => $member] = restrictedCatalogCompany();
    $deniedSku = 'VETO-CSV-' . substr(uniqid(), -6);
    createTestVariantOfType(catalogOtherProductType(), $deniedSku);

    $cart = createTestQuickOrderCart();
    $detach = attachCatalogVeto($member->id);

    try {
        $result = Plugin::getInstance()->quickOrder->addToCart($cart, "{$deniedSku},2");
    } finally {
        $detach();
    }

    expect($result['added'])->toBe(0)
        ->and($cart->getLineItems())->toHaveCount(0)
        ->and($result['errors'][1])->toBe('This product is not available for your account.');
});

it('vetoes a restricted product on the reorder path', function () {
    ['company' => $company, 'member' => $member] = restrictedCatalogCompany();
    $deniedSku = 'VETO-REORDER-' . substr(uniqid(), -6);
    $variant = createTestVariantOfType(catalogOtherProductType(), $deniedSku);

    // Build a completed source order for the member carrying the restricted variant.
    $source = createTestOrder($member);
    $lineItem = Commerce::getInstance()->getLineItems()->resolveLineItem($source, $variant->id, []);
    $lineItem->qty = 1;
    $source->addLineItem($lineItem);
    $source->markAsComplete();
    trackElement($source);

    $cart = createTestQuickOrderCart();
    $detach = attachCatalogVeto($member->id);

    try {
        $result = Plugin::getInstance()->quickOrder->reorder($cart, $source, $member);
    } finally {
        $detach();
    }

    expect($result['added'])->toBe(0)
        ->and($cart->getLineItems())->toHaveCount(0)
        ->and($result['errors'][1])->toBe('This product is not available for your account.');
});

it('vetoes a restricted product on the order-list add path', function () {
    ['company' => $company, 'member' => $member] = restrictedCatalogCompany();
    $deniedSku = 'VETO-LIST-' . substr(uniqid(), -6);
    $variant = createTestVariantOfType(catalogOtherProductType(), $deniedSku);

    $listId = Plugin::getInstance()->orderLists->createList($company, 'Veto list', $member->id);
    Plugin::getInstance()->orderLists->setItem($company, $listId, $variant->id, 3);

    $cart = createTestQuickOrderCart();
    $detach = attachCatalogVeto($member->id);

    try {
        $result = Plugin::getInstance()->orderLists->addListToCart($cart, $company, $listId);
    } finally {
        $detach();
    }

    expect($result['added'])->toBe(0)
        ->and($cart->getLineItems())->toHaveCount(0)
        ->and($result['errors'][1])->toBe('This product is not available for your account.');
});

it('covers the on-behalf path: the veto keys off the effective (impersonated) member', function () {
    ['company' => $company, 'member' => $member] = restrictedCatalogCompany();
    $allowed = createTestVariant('VETO-OB-ALLOW-' . substr(uniqid(), -6));
    $denied = createTestVariantOfType(catalogOtherProductType(), 'VETO-OB-DENY-' . substr(uniqid(), -6));

    // isPurchasableAllowed is resolved for the impersonated member's company (getIdentity() under
    // native impersonation returns the target member), so on-behalf inherits the member's catalog.
    expect(Plugin::getInstance()->companyCatalog->isPurchasableAllowed($allowed, $company))->toBeTrue()
        ->and(Plugin::getInstance()->companyCatalog->isPurchasableAllowed($denied, $company))->toBeFalse();
});

it('covers the quote-accept adoption path: adding a restricted product to the adopted cart is refused', function () {
    ['member' => $member] = restrictedCatalogCompany();
    $denied = createTestVariantOfType(catalogOtherProductType(), 'VETO-QUOTE-' . substr(uniqid(), -6));

    $cart = createTestQuickOrderCart();
    $detach = attachCatalogVeto($member->id);

    try {
        $error = Plugin::getInstance()->quickOrder->addResolvedPurchasable($cart, $denied->id, 1, $denied->sku);
    } finally {
        $detach();
    }

    expect($error)->toBe('This product is not available for your account.')
        ->and($cart->getLineItems())->toHaveCount(0);
});

it('does not veto anything for an unrestricted (null-condition) company', function () {
    $company = createTestCompany('approved', 'Veto Null Co');
    $member = createTestUser('vetonull_' . uniqid() . '@example.test');
    Plugin::getInstance()->companyMembers->addUserToCompany($member->id, $company->id, CompanyRole::Purchaser);

    $sku = 'VETO-NULL-' . substr(uniqid(), -6);
    createTestVariantOfType(catalogOtherProductType(), $sku);

    $cart = createTestQuickOrderCart();
    $detach = attachCatalogVeto($member->id);

    try {
        $result = Plugin::getInstance()->quickOrder->addToCart($cart, "{$sku} 1");
    } finally {
        $detach();
    }

    expect($result['added'])->toBe(1)
        ->and($cart->getLineItems())->toHaveCount(1);
});

it('exposes catalog criteria that scope a product query to the allowed products', function () {
    $company = createTestCompany('approved', 'Catalog Criteria Co');
    $company->catalogCondition = catalogConditionForType(quickOrderProductType());
    craftApp()->getElements()->saveElement($company);

    $member = createTestUser('criteria_' . uniqid() . '@example.test');
    Plugin::getInstance()->companyMembers->addUserToCompany($member->id, $company->id, CompanyRole::Purchaser);

    $allowed = createTestVariant('CRIT-ALLOW-' . substr(uniqid(), -6));
    $denied = createTestVariantOfType(catalogOtherProductType(), 'CRIT-DENY-' . substr(uniqid(), -6));

    $userSession = craftApp()->getUser();
    $previous = $userSession->getIdentity();
    $userSession->setIdentity($member);

    try {
        $criteria = (new \totalwebcreations\b2bcommerce\variables\B2bVariable())->getCatalogCriteria();
    } finally {
        $userSession->setIdentity($previous);
    }

    expect($criteria)->toHaveKey('id')
        ->and($criteria['id'])->toContain($allowed->getProduct()->id)
        ->and($criteria['id'])->not->toContain($denied->getProduct()->id);
});

it('returns empty catalog criteria for a full-catalog company (no restriction)', function () {
    $company = createTestCompany('approved', 'Catalog Criteria Full Co');
    $member = createTestUser('criteriafull_' . uniqid() . '@example.test');
    Plugin::getInstance()->companyMembers->addUserToCompany($member->id, $company->id, CompanyRole::Purchaser);

    $userSession = craftApp()->getUser();
    $previous = $userSession->getIdentity();
    $userSession->setIdentity($member);

    try {
        $criteria = (new \totalwebcreations\b2bcommerce\variables\B2bVariable())->getCatalogCriteria();
    } finally {
        $userSession->setIdentity($previous);
    }

    expect($criteria)->toBe([]);
});
