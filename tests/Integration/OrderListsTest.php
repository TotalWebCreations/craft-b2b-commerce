<?php

use craft\commerce\elements\Order;
use craft\db\Query;
use totalwebcreations\b2bcommerce\Plugin;
use yii\base\InvalidArgumentException;

/**
 * Creates and saves a tracked empty cart order for order-list tests.
 */
function createOrderListCart(): Order
{
    $order = new Order();
    $order->number = md5(uniqid((string) mt_rand(), true));

    if (!craftApp()->getElements()->saveElement($order)) {
        throw new RuntimeException('Could not save test cart: ' . implode(', ', $order->getFirstErrors()));
    }

    trackElement($order);

    return $order;
}

/**
 * Counts the item rows stored for a list, straight from the database.
 */
function orderListItemRowCount(int $listId): int
{
    return (int) (new Query())
        ->from('{{%b2b_order_list_items}}')
        ->where(['listId' => $listId])
        ->count();
}

it('creates, renames and deletes a list and reports its item count', function () {
    $company = createTestCompany();
    $variant = createTestVariant('OL-CRUD-' . substr(uniqid(), -6));

    $listId = Plugin::getInstance()->orderLists->createList($company, 'Weekly staples', null);

    Plugin::getInstance()->orderLists->setItem($company, $listId, $variant->id, 3);

    $lists = Plugin::getInstance()->orderLists->getLists($company->id);

    expect($lists)->toHaveCount(1)
        ->and($lists[0]['id'])->toBe($listId)
        ->and($lists[0]['name'])->toBe('Weekly staples')
        ->and($lists[0]['itemCount'])->toBe(1);

    Plugin::getInstance()->orderLists->renameList($company, $listId, 'Renamed staples');

    expect(Plugin::getInstance()->orderLists->getLists($company->id)[0]['name'])->toBe('Renamed staples');

    Plugin::getInstance()->orderLists->deleteList($company, $listId);

    expect(Plugin::getInstance()->orderLists->getLists($company->id))->toBe([]);
});

it('refuses to touch a list that belongs to another company and mutates nothing', function () {
    $companyA = createTestCompany();
    $companyB = createTestCompany();

    $listId = Plugin::getInstance()->orderLists->createList($companyA, 'Company A list', null);

    expect(fn () => Plugin::getInstance()->orderLists->renameList($companyB, $listId, 'Hijacked'))
        ->toThrow(InvalidArgumentException::class, 'This list does not belong to this company.');

    // The list is untouched and remains invisible to company B.
    expect(Plugin::getInstance()->orderLists->getLists($companyA->id)[0]['name'])->toBe('Company A list')
        ->and(Plugin::getInstance()->orderLists->getLists($companyB->id))->toBe([]);

    // Clean up the tracked list row (no element tracks it).
    Plugin::getInstance()->orderLists->deleteList($companyA, $listId);
});

it('upserts an item: adds, updates the quantity and removes it on zero', function () {
    $company = createTestCompany();
    $variant = createTestVariant('OL-UPSERT-' . substr(uniqid(), -6));

    $listId = Plugin::getInstance()->orderLists->createList($company, 'Upsert list', null);
    $service = Plugin::getInstance()->orderLists;

    $service->setItem($company, $listId, $variant->id, 2);

    $items = $service->getItems($company, $listId);

    expect($items)->toHaveCount(1)
        ->and($items[0]['purchasableId'])->toBe($variant->id)
        ->and($items[0]['qty'])->toBe(2)
        ->and($items[0]['sku'])->toBe($variant->sku);

    $service->setItem($company, $listId, $variant->id, 5);

    $items = $service->getItems($company, $listId);

    expect($items)->toHaveCount(1)
        ->and($items[0]['qty'])->toBe(5);

    $service->setItem($company, $listId, $variant->id, 0);

    expect($service->getItems($company, $listId))->toBe([]);

    $service->deleteList($company, $listId);
});

it('rejects an item for a purchasable that does not exist', function () {
    $company = createTestCompany();
    $listId = Plugin::getInstance()->orderLists->createList($company, 'Bogus list', null);

    expect(fn () => Plugin::getInstance()->orderLists->setItem($company, $listId, 999999999, 1))
        ->toThrow(InvalidArgumentException::class, 'This purchasable does not exist.');

    Plugin::getInstance()->orderLists->deleteList($company, $listId);
});

it('adds available items to the cart and reports an error for an unavailable one', function () {
    $company = createTestCompany();
    $available = createTestVariant('OL-CART-OK-' . substr(uniqid(), -6));
    $unavailable = createTestVariant('OL-CART-BAD-' . substr(uniqid(), -6), 12.0, false);

    $listId = Plugin::getInstance()->orderLists->createList($company, 'Cart list', null);
    Plugin::getInstance()->orderLists->setItem($company, $listId, $available->id, 4);
    Plugin::getInstance()->orderLists->setItem($company, $listId, $unavailable->id, 1);

    $cart = createOrderListCart();

    $result = Plugin::getInstance()->orderLists->addListToCart($cart, $company, $listId);

    $quantities = [];

    foreach ($cart->getLineItems() as $lineItem) {
        $quantities[$lineItem->getSku()] = $lineItem->qty;
    }

    expect($result['added'])->toBe(1)
        ->and($cart->getLineItems())->toHaveCount(1)
        ->and($quantities[$available->sku])->toBe(4)
        ->and($result['errors'])->toHaveKey(2)
        ->and($result['errors'][2])->toContain('is not available');

    Plugin::getInstance()->orderLists->deleteList($company, $listId);
});

it('adds nothing for an empty list', function () {
    $company = createTestCompany();
    $listId = Plugin::getInstance()->orderLists->createList($company, 'Empty list', null);

    $cart = createOrderListCart();

    $result = Plugin::getInstance()->orderLists->addListToCart($cart, $company, $listId);

    expect($result)->toBe(['added' => 0, 'errors' => []])
        ->and($cart->getLineItems())->toHaveCount(0);

    Plugin::getInstance()->orderLists->deleteList($company, $listId);
});

it('cascades item deletion when its list is deleted', function () {
    $company = createTestCompany();
    $variant = createTestVariant('OL-CASCADE-' . substr(uniqid(), -6));

    $listId = Plugin::getInstance()->orderLists->createList($company, 'Cascade list', null);
    Plugin::getInstance()->orderLists->setItem($company, $listId, $variant->id, 2);

    expect(orderListItemRowCount($listId))->toBe(1);

    Plugin::getInstance()->orderLists->deleteList($company, $listId);

    // The list_items.listId foreign key is ON DELETE CASCADE, so the row is gone.
    expect(orderListItemRowCount($listId))->toBe(0);
});
