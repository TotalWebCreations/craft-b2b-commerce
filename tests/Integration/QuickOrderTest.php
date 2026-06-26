<?php

use craft\commerce\elements\Order;
use craft\commerce\events\AddLineItemEvent;
use totalwebcreations\b2bcommerce\Plugin;
use yii\base\Event;
use yii\base\InvalidArgumentException;

/**
 * Creates and saves a tracked empty cart order.
 */
function createTestQuickOrderCart(): Order
{
    $order = new Order();
    $order->number = md5(uniqid((string) mt_rand(), true));

    if (!craftApp()->getElements()->saveElement($order)) {
        throw new RuntimeException('Could not save test cart: ' . implode(', ', $order->getFirstErrors()));
    }

    trackElement($order);

    return $order;
}

it('adds resolvable SKUs to the cart, sums duplicates and reports per-line errors', function () {
    $suffix = substr(uniqid(), -6);
    $skuA = 'QO-A-' . $suffix;
    $skuB = 'QO-B-' . $suffix;
    createTestVariant($skuA);
    createTestVariant($skuB);

    $input = implode("\n", [
        "{$skuA} 2",              // line 1: valid
        'QO-UNKNOWN 1',           // line 2: unknown SKU
        strtolower($skuA) . ' 3', // line 3: duplicate of line 1 in different casing -> merges into line 1
        "{$skuB} abc",            // line 4: invalid quantity
        "{$skuB} 4",              // line 5: valid
    ]);

    $cart = createTestQuickOrderCart();

    $result = Plugin::getInstance()->quickOrder->addToCart($cart, $input);

    $quantities = [];

    foreach ($cart->getLineItems() as $lineItem) {
        $quantities[$lineItem->getSku()] = $lineItem->qty;
    }

    expect($result['added'])->toBe(2)
        ->and($cart->getLineItems())->toHaveCount(2)
        ->and($quantities[$skuA])->toBe(5)
        ->and($quantities[$skuB])->toBe(4)
        ->and(array_keys($result['errors']))->toBe([2, 4])
        ->and($result['errors'][2])->toBe("Unknown SKU \"QO-UNKNOWN\"")
        ->and($result['errors'][4])->toBe('Invalid quantity');
});

it('refuses input with more lines than the cap without resolving anything', function () {
    $lines = [];

    for ($i = 1; $i <= 501; $i++) {
        $lines[] = "SKU-CAP-{$i} 1";
    }

    $cart = createTestQuickOrderCart();

    expect(fn () => Plugin::getInstance()->quickOrder->addToCart($cart, implode("\n", $lines)))
        ->toThrow(InvalidArgumentException::class, 'Too many lines')
        ->and($cart->getLineItems())->toHaveCount(0);
});

it('reports an unavailable SKU when the product is disabled', function () {
    $sku = 'QO-DISABLED-' . substr(uniqid(), -6);
    createTestVariant($sku, 15.0, false);

    $cart = createTestQuickOrderCart();

    $result = Plugin::getInstance()->quickOrder->addToCart($cart, "{$sku} 1");

    expect($result['added'])->toBe(0)
        ->and($cart->getLineItems())->toHaveCount(0)
        ->and($result['errors'][1])->toBe("SKU \"{$sku}\" is not available");
});

it('adds nothing and surfaces the guard message when the add-to-cart guard blocks a line', function () {
    // The real add-to-cart guard (EVENT_BEFORE_ADD_LINE_ITEM keyed on canPurchase)
    // needs a full web request, which this console harness cannot fake through a
    // Commerce cart mutation. The guard's decision is covered by PriceVisibilityTest;
    // here we stand in a blocking handler to assert the quick-order service reports
    // nothing added and surfaces the message the guard puts on the line item.
    $sku = 'QO-GUARD-' . substr(uniqid(), -6);
    createTestVariant($sku);

    $cart = createTestQuickOrderCart();
    $message = 'You need an approved business account to order.';

    $handler = function (AddLineItemEvent $event) use ($message) {
        $event->isValid = false;
        $event->lineItem->addError('purchasableId', $message);
    };

    Event::on(Order::class, Order::EVENT_BEFORE_ADD_LINE_ITEM, $handler);

    try {
        $result = Plugin::getInstance()->quickOrder->addToCart($cart, "{$sku} 2");
    } finally {
        Event::off(Order::class, Order::EVENT_BEFORE_ADD_LINE_ITEM, $handler);
    }

    expect($result['added'])->toBe(0)
        ->and($cart->getLineItems())->toHaveCount(0)
        ->and($result['errors'][1])->toBe($message);
});
