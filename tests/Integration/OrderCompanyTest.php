<?php

use craft\commerce\elements\Order;
use craft\commerce\records\Transaction as TransactionRecord;
use craft\db\Query;
use craft\elements\User;
use totalwebcreations\b2bcommerce\enums\CompanyRole;
use totalwebcreations\b2bcommerce\Plugin;

/**
 * Saves an empty cart order for the given customer (or a guest when null) and
 * tracks it for hard-delete afterwards.
 */
function createTestOrder(?User $customer): Order
{
    $order = new Order();
    $order->number = md5(uniqid((string) mt_rand(), true));

    if ($customer !== null) {
        $order->setCustomer($customer);
    }

    if (!craftApp()->getElements()->saveElement($order)) {
        throw new RuntimeException('Could not save test order: ' . implode(', ', $order->getFirstErrors()));
    }

    trackElement($order);

    return $order;
}

/**
 * Persists a successful `purchase` transaction for the order, mirroring what a payment
 * gateway records once it has captured funds. This is exactly the state getTotalPaid()
 * measures (sum of successful purchase/capture transactions). The transaction row is removed
 * automatically when the order element is hard-deleted (orderId foreign key cascade).
 */
function recordSuccessfulPurchase(Order $order, float $amount = 100.0): void
{
    $record = new TransactionRecord();
    $record->orderId = $order->id;
    $record->type = TransactionRecord::TYPE_PURCHASE;
    $record->status = TransactionRecord::STATUS_SUCCESS;
    $record->amount = $amount;
    $record->currency = 'USD';
    $record->save(false);

    $order->setTransactions(null);
}

/**
 * Reports whether a b2b_order_company row exists for the given order.
 */
function orderCompanyRowExists(int $orderId): bool
{
    return (new Query())
        ->from('{{%b2b_order_company}}')
        ->where(['orderId' => $orderId])
        ->exists();
}

it('links a completed order to the customer company and resolves it back', function () {
    $user = createTestUser('ordercompany_' . uniqid() . '@example.test');
    $company = createTestCompany('approved');
    Plugin::getInstance()->companyMembers->addUserToCompany($user->id, $company->id, CompanyRole::Admin);

    $order = createTestOrder($user);
    expect($order->markAsComplete())->toBeTrue();

    expect(orderCompanyRowExists($order->id))->toBeTrue()
        ->and($order->b2bCompany)->not->toBeNull()
        ->and($order->b2bCompany->id)->toBe($company->id);
});

it('refuses to complete an order for a blocked customer on a site request', function () {
    $user = createTestUser('blockedorder_' . uniqid() . '@example.test');
    $company = createTestCompany('blocked');
    Plugin::getInstance()->companyMembers->addUserToCompany($user->id, $company->id, CompanyRole::Admin);

    $plugin = Plugin::getInstance();
    Craft::$app->getPlugins()->savePluginSettings($plugin, ['hidePricesForGuests' => true]);

    $order = createTestOrder($user);
    $refused = false;

    try {
        asSiteRequest(function () use ($order, &$refused) {
            try {
                $order->markAsComplete();
            } catch (Throwable) {
                $refused = true;
            }
        });
    } finally {
        Craft::$app->getPlugins()->savePluginSettings($plugin, ['hidePricesForGuests' => false]);
    }

    $completedInDb = (new Query())
        ->from('{{%commerce_orders}}')
        ->where(['id' => $order->id, 'isCompleted' => true])
        ->exists();

    expect($refused)->toBeTrue()
        ->and($completedInDb)->toBeFalse()
        ->and(orderCompanyRowExists($order->id))->toBeFalse()
        ->and($order->getErrors('customerId'))->not->toBeEmpty();
});

it('exempts a paid order of a blocked customer from the checkout backstop', function () {
    $user = createTestUser('paidblocked_' . uniqid() . '@example.test');
    $company = createTestCompany('blocked');
    Plugin::getInstance()->companyMembers->addUserToCompany($user->id, $company->id, CompanyRole::Admin);

    $plugin = Plugin::getInstance();
    Craft::$app->getPlugins()->savePluginSettings($plugin, ['hidePricesForGuests' => true]);

    $order = createTestOrder($user);
    $threw = false;

    try {
        recordSuccessfulPurchase($order);

        // Guards the webhook-retry scenario: the gateway already captured funds, so the backstop must
        // exempt the paid order rather than throw. The backstop method is exercised directly (it is
        // what markAsComplete() fires on EVENT_BEFORE_COMPLETE_ORDER); driving a full completion in a
        // faked site request is unrelated console/web-response friction in the test harness.
        expect($order->getTotalPaid())->toBeGreaterThan(0);

        asSiteRequest(function () use ($order, &$threw) {
            try {
                $order->getCustomer();
                Plugin::getInstance()->orderCompanyLink->enforcePurchasePolicy($order);
            } catch (Throwable) {
                $threw = true;
            }
        });
    } finally {
        Craft::$app->getPlugins()->savePluginSettings($plugin, ['hidePricesForGuests' => false]);
    }

    expect($threw)->toBeFalse()
        ->and($order->getErrors('customerId'))->toBeEmpty();
});

it('pins the storefront abort coupling relied on by the checkout controller', function () {
    $user = createTestUser('abortcoupling_' . uniqid() . '@example.test');
    $company = createTestCompany('blocked');
    Plugin::getInstance()->companyMembers->addUserToCompany($user->id, $company->id, CompanyRole::Admin);

    $plugin = Plugin::getInstance();
    Craft::$app->getPlugins()->savePluginSettings($plugin, ['hidePricesForGuests' => true]);

    $order = createTestOrder($user);
    $refused = false;

    try {
        asSiteRequest(function () use ($order, &$refused) {
            try {
                $order->markAsComplete();
            } catch (Throwable) {
                $refused = true;
            }
        });
    } finally {
        Craft::$app->getPlugins()->savePluginSettings($plugin, ['hidePricesForGuests' => false]);
    }

    $completedInDb = (new Query())
        ->from('{{%commerce_orders}}')
        ->where(['id' => $order->id, 'isCompleted' => true])
        ->exists();

    // Replicates the production coupling: the backstop error lives on the customerId attribute
    // with the exact EN message, validate(null, clearErrors: false) still fails on it (the
    // _returnCart short-circuit condition), and the order was never persisted as completed.
    expect($refused)->toBeTrue()
        ->and($order->getErrors('customerId'))
        ->toBe(['This order cannot be completed with the current account status.'])
        ->and($order->validate(null, false))->toBeFalse()
        ->and($completedInDb)->toBeFalse();
});

it('completes a guest order without linking a company or erroring', function () {
    $order = createTestOrder(null);

    expect($order->markAsComplete())->toBeTrue()
        ->and(orderCompanyRowExists($order->id))->toBeFalse()
        ->and($order->b2bCompany)->toBeNull();
});
