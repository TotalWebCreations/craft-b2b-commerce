<?php

use craft\commerce\elements\Order;
use craft\commerce\records\Transaction as TransactionRecord;
use craft\db\Query;
use totalwebcreations\b2bcommerce\enums\CompanyRole;
use totalwebcreations\b2bcommerce\Plugin;

/**
 * Persists a successful transaction of the given type for the order, mirroring what a
 * gateway records. Rows cascade-delete with the order element (orderId foreign key).
 */
function recordTransaction(Order $order, string $type, float $amount): void
{
    $record = new TransactionRecord();
    $record->orderId = $order->id;
    $record->type = $type;
    $record->status = TransactionRecord::STATUS_SUCCESS;
    $record->amount = $amount;
    $record->currency = 'USD';
    $record->save(false);

    $order->setTransactions(null);
}

/**
 * Pins the full-refund invariant the checkout backstop leans on.
 *
 * A completed, fully paid order that is later fully refunded must report getTotalPaid() === 0
 * (Commerce sums successful purchase/capture transactions and subtracts successful refunds).
 * The paid-order exemption in OrderCompanyLink::enforcePurchasePolicy() therefore stops
 * applying to a refunded order — the exemption exists only to avoid fighting a gateway that
 * already captured funds. What this test guards against is the second half: nothing in the
 * refund path re-triggers completion or knocks isCompleted back off, so an order that was
 * legitimately completed stays completed even after its money is returned. A regression that
 * either left getTotalPaid() positive after a full refund, or unset the completed state, would
 * break this.
 */
it('drops getTotalPaid to zero after a full refund while the order stays completed', function () {
    $user = createTestUser('refund_' . uniqid() . '@example.test');
    $company = createTestCompany('approved');
    Plugin::getInstance()->companyMembers->addUserToCompany($user->id, $company->id, CompanyRole::Admin);

    $order = new Order();
    $order->number = md5(uniqid((string) mt_rand(), true));
    $order->setCustomer($user);

    expect(craftApp()->getElements()->saveElement($order))->toBeTrue();
    trackElement($order);

    expect($order->markAsComplete())->toBeTrue();

    recordTransaction($order, TransactionRecord::TYPE_PURCHASE, 100.0);
    expect($order->getTotalPaid())->toBe(100.0);

    recordTransaction($order, TransactionRecord::TYPE_REFUND, 100.0);

    $completedInDb = (new Query())
        ->from('{{%commerce_orders}}')
        ->where(['id' => $order->id, 'isCompleted' => true])
        ->exists();

    expect($order->getTotalPaid())->toBe(0.0)
        ->and($order->isCompleted)->toBeTrue()
        ->and($completedInDb)->toBeTrue();
});
