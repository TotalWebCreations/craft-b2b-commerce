<?php

use craft\commerce\elements\Order;
use craft\commerce\elements\Variant;
use craft\commerce\Plugin as Commerce;
use craft\elements\User;
use totalwebcreations\b2bcommerce\enums\CompanyRole;
use totalwebcreations\b2bcommerce\Plugin;
use yii\base\InvalidArgumentException;

/**
 * Saves and completes an order carrying a single line item for the given variant,
 * tracking it for hard-delete afterwards. Completing it links any company the
 * customer belongs to, exactly as a real checkout would.
 */
function createCompletedOrderWithVariant(?User $customer, Variant $variant, int $qty = 2): Order
{
    $order = new Order();
    $order->number = md5(uniqid((string) mt_rand(), true));

    if ($customer !== null) {
        $order->setCustomer($customer);
    }

    $lineItem = Commerce::getInstance()->getLineItems()->resolveLineItem($order, $variant->id);
    $lineItem->qty = $qty;
    $order->addLineItem($lineItem);

    if (!craftApp()->getElements()->saveElement($order)) {
        throw new RuntimeException('Could not save source order: ' . implode(', ', $order->getFirstErrors()));
    }

    if (!$order->markAsComplete()) {
        throw new RuntimeException('Could not complete source order.');
    }

    trackElement($order);

    return $order;
}

/**
 * Saves a tracked, incomplete order for the given customer to stand in as a cart.
 */
function createReorderCart(User $customer): Order
{
    $order = new Order();
    $order->number = md5(uniqid((string) mt_rand(), true));
    $order->setCustomer($customer);

    if (!craftApp()->getElements()->saveElement($order)) {
        throw new RuntimeException('Could not save reorder cart: ' . implode(', ', $order->getFirstErrors()));
    }

    trackElement($order);

    return $order;
}

it('reorders a customer their own completed order into the cart', function () {
    $sku = 'RO-OWN-' . substr(uniqid(), -6);
    $variant = createTestVariant($sku);
    $actor = createTestUser('reorder_own_' . uniqid() . '@example.test');

    $source = createCompletedOrderWithVariant($actor, $variant, 3);
    $cart = createReorderCart($actor);

    $result = Plugin::getInstance()->quickOrder->reorder($cart, $source, $actor);

    $quantities = [];

    foreach ($cart->getLineItems() as $lineItem) {
        $quantities[$lineItem->getSku()] = $lineItem->qty;
    }

    expect($result['added'])->toBe(1)
        ->and($result['errors'])->toBe([])
        ->and($cart->getLineItems())->toHaveCount(1)
        ->and($quantities[$sku])->toBe(3);
});

it('lets a colleague reorder an order that belongs to the same company', function () {
    $sku = 'RO-COLLEAGUE-' . substr(uniqid(), -6);
    $variant = createTestVariant($sku);

    $company = createTestCompany('approved');
    $owner = createTestUser('reorder_owner_' . uniqid() . '@example.test');
    $colleague = createTestUser('reorder_colleague_' . uniqid() . '@example.test');
    Plugin::getInstance()->companyMembers->addUserToCompany($owner->id, $company->id, CompanyRole::Admin);
    Plugin::getInstance()->companyMembers->addUserToCompany($colleague->id, $company->id, CompanyRole::Purchaser);

    $source = createCompletedOrderWithVariant($owner, $variant, 2);

    expect($source->b2bCompany?->id)->toBe($company->id);

    $cart = createReorderCart($colleague);

    $result = Plugin::getInstance()->quickOrder->reorder($cart, $source, $colleague);

    expect($result['added'])->toBe(1)
        ->and($cart->getLineItems())->toHaveCount(1);
});

it('refuses to reorder an order that belongs to a stranger', function () {
    $sku = 'RO-STRANGER-' . substr(uniqid(), -6);
    $variant = createTestVariant($sku);

    $owner = createTestUser('reorder_stranger_owner_' . uniqid() . '@example.test');
    $stranger = createTestUser('reorder_stranger_' . uniqid() . '@example.test');

    $source = createCompletedOrderWithVariant($owner, $variant);
    $cart = createReorderCart($stranger);

    expect(fn () => Plugin::getInstance()->quickOrder->reorder($cart, $source, $stranger))
        ->toThrow(InvalidArgumentException::class);

    expect($cart->getLineItems())->toHaveCount(0);
});

it('reports a per-line error for a purchasable that no longer exists', function () {
    $sku = 'RO-DELETED-' . substr(uniqid(), -6);
    $variant = createTestVariant($sku);
    $actor = createTestUser('reorder_deleted_' . uniqid() . '@example.test');

    $source = createCompletedOrderWithVariant($actor, $variant);
    $sourceId = $source->id;

    craftApp()->getElements()->deleteElement($variant, true);

    // Reload so the line item resolves its purchasable fresh (and finds it gone).
    $source = Order::find()->id($sourceId)->isCompleted(true)->one();

    $cart = createReorderCart($actor);

    $result = Plugin::getInstance()->quickOrder->reorder($cart, $source, $actor);

    expect($result['added'])->toBe(0)
        ->and($cart->getLineItems())->toHaveCount(0)
        ->and($result['errors'])->toHaveKey(1)
        ->and($result['errors'][1])->toContain('no longer available');
});

it('refuses to reorder an order that is not completed', function () {
    $actor = createTestUser('reorder_incomplete_' . uniqid() . '@example.test');
    $source = createReorderCart($actor);
    $cart = createReorderCart($actor);

    expect(fn () => Plugin::getInstance()->quickOrder->reorder($cart, $source, $actor))
        ->toThrow(InvalidArgumentException::class);
});
