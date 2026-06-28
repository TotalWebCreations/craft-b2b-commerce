<?php

use craft\commerce\elements\Order;
use craft\commerce\gateways\Manual;
use craft\commerce\Plugin as Commerce;
use craft\commerce\records\Transaction as TransactionRecord;
use totalwebcreations\b2bcommerce\elements\Company;
use totalwebcreations\b2bcommerce\enums\CompanyRole;
use totalwebcreations\b2bcommerce\gateways\InvoiceGateway;
use totalwebcreations\b2bcommerce\Plugin;

/**
 * Returns the shared, persisted InvoiceGateway fixture, creating it on first use.
 * Gateways live in project config, so it is created once and reused across tests
 * (like the product-type fixture) rather than rebuilt and torn down per test.
 */
function creditTestInvoiceGateway(): InvoiceGateway
{
    $handle = 'b2bCreditTestInvoice';
    $existing = Commerce::getInstance()->getGateways()->getGatewayByHandle($handle);

    if ($existing instanceof InvoiceGateway) {
        return $existing;
    }

    $gateway = new InvoiceGateway();
    $gateway->name = 'B2B Credit Test Invoice';
    $gateway->handle = $handle;

    if (!Commerce::getInstance()->getGateways()->saveGateway($gateway)) {
        throw new RuntimeException('Could not save invoice gateway: ' . implode(', ', $gateway->getErrorSummary(true)));
    }

    return $gateway;
}

/**
 * Returns the shared, persisted non-invoice (Manual) gateway fixture, created on first use.
 * Used to prove that orders paid via another gateway type never count towards the balance.
 */
function creditTestManualGateway(): Manual
{
    $handle = 'b2bCreditTestManual';
    $existing = Commerce::getInstance()->getGateways()->getGatewayByHandle($handle);

    if ($existing instanceof Manual) {
        return $existing;
    }

    $gateway = new Manual();
    $gateway->name = 'B2B Credit Test Manual';
    $gateway->handle = $handle;

    if (!Commerce::getInstance()->getGateways()->saveGateway($gateway)) {
        throw new RuntimeException('Could not save manual gateway: ' . implode(', ', $gateway->getErrorSummary(true)));
    }

    return $gateway;
}

/**
 * Creates a tracked, approved company with the invoice flag and the given credit limit.
 */
function creditTestCompany(?float $creditLimit): Company
{
    $company = createTestCompany(Company::STATUS_APPROVED);
    $company->allowInvoicePayment = true;
    $company->creditLimit = $creditLimit;

    if (!craftApp()->getElements()->saveElement($company)) {
        throw new RuntimeException('Could not save test company: ' . implode(', ', $company->getFirstErrors()));
    }

    return $company;
}

/**
 * Completes a tracked order on the given gateway for a fresh member of the company, carrying a
 * single line item priced at $price. Completing it links the customer's company exactly as a real
 * checkout would (Order::EVENT_AFTER_COMPLETE_ORDER), so the b2b_order_company row is real.
 */
function completedOrderOnGateway(Company $company, int $gatewayId, float $price): Order
{
    $user = createTestUser('creditbal_' . uniqid() . '@example.test');
    Plugin::getInstance()->companyMembers->addUserToCompany($user->id, $company->id, CompanyRole::Admin);

    $variant = createTestVariant('CRD-' . uniqid(), $price);

    $order = new Order();
    $order->number = md5(uniqid((string) mt_rand(), true));
    $order->setCustomer($user);
    $order->gatewayId = $gatewayId;

    $lineItem = Commerce::getInstance()->getLineItems()->resolveLineItem($order, $variant->id);
    $lineItem->qty = 1;
    $order->addLineItem($lineItem);

    if (!craftApp()->getElements()->saveElement($order)) {
        throw new RuntimeException('Could not save test order: ' . implode(', ', $order->getFirstErrors()));
    }

    if (!$order->markAsComplete()) {
        throw new RuntimeException('Could not complete test order.');
    }

    trackElement($order);

    return $order;
}

/**
 * Builds a tracked cart (incomplete order) priced at $price for a fresh member of the company,
 * to feed InvoiceGateway::availableForUseWithOrder().
 */
function cartForMember(Company $company, float $price): Order
{
    $user = createTestUser('creditcart_' . uniqid() . '@example.test');
    Plugin::getInstance()->companyMembers->addUserToCompany($user->id, $company->id, CompanyRole::Admin);

    $variant = createTestVariant('CRDCART-' . uniqid(), $price);

    $order = new Order();
    $order->number = md5(uniqid((string) mt_rand(), true));
    $order->setCustomer($user);

    $lineItem = Commerce::getInstance()->getLineItems()->resolveLineItem($order, $variant->id);
    $lineItem->qty = 1;
    $order->addLineItem($lineItem);

    if (!craftApp()->getElements()->saveElement($order)) {
        throw new RuntimeException('Could not save test cart: ' . implode(', ', $order->getFirstErrors()));
    }

    trackElement($order);

    return $order;
}

/**
 * Records a successful purchase transaction for the order, mirroring an out-of-band capture.
 * The row cascade-deletes with the order element (orderId foreign key).
 */
function recordCreditPurchase(Order $order, float $amount): void
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

it('reports a zero balance for a company without orders', function () {
    $company = creditTestCompany(500.0);

    expect(Plugin::getInstance()->creditBalance->getOutstandingBalance($company->id))->toBe(0.0);
});

it('counts only orders paid on an invoice gateway', function () {
    $company = creditTestCompany(500.0);
    $invoiceOrder = completedOrderOnGateway($company, creditTestInvoiceGateway()->id, 30.0);
    completedOrderOnGateway($company, creditTestManualGateway()->id, 100.0);

    expect(Plugin::getInstance()->creditBalance->getOutstandingBalance($company->id))
        ->toBe($invoiceOrder->getTotalPrice());
});

it('lowers the outstanding balance by a partial payment', function () {
    $company = creditTestCompany(500.0);
    $order = completedOrderOnGateway($company, creditTestInvoiceGateway()->id, 40.0);

    recordCreditPurchase($order, 15.0);

    expect(Plugin::getInstance()->creditBalance->getOutstandingBalance($company->id))
        ->toBe($order->getTotalPrice() - 15.0);
});

it('allows a charge that lands exactly on the credit limit', function () {
    $company = creditTestCompany(50.0);
    completedOrderOnGateway($company, creditTestInvoiceGateway()->id, 40.0);

    expect(Plugin::getInstance()->creditBalance->canCover($company->id, 10.0))->toBeTrue();
});

it('refuses a charge that pushes past the credit limit', function () {
    $company = creditTestCompany(50.0);
    completedOrderOnGateway($company, creditTestInvoiceGateway()->id, 40.0);

    expect(Plugin::getInstance()->creditBalance->canCover($company->id, 11.0))->toBeFalse();
});

it('refuses any charge for a company without a credit limit', function () {
    $company = creditTestCompany(null);

    expect(Plugin::getInstance()->creditBalance->canCover($company->id, 5.0))->toBeFalse();
});

it('keeps counting an invoice order whose gateway reference was cleared after completion', function () {
    // The balance is keyed on the b2b_order_company.isInvoice snapshot, not the live gatewayId.
    // Commerce's archiveGatewayById() nulls gatewayId on every order that used the gateway; here we
    // replicate exactly that side effect on a completed invoice order. The snapshot is untouched, so
    // the receivable must still count -- THIS is the regression the snapshot exists to prevent.
    $company = creditTestCompany(500.0);
    $order = completedOrderOnGateway($company, creditTestInvoiceGateway()->id, 30.0);

    craftApp()->getDb()->createCommand()
        ->update('{{%commerce_orders}}', ['gatewayId' => null], ['id' => $order->id])
        ->execute();

    expect(Plugin::getInstance()->creditBalance->getOutstandingBalance($company->id))
        ->toBe($order->getTotalPrice());
});

it('makes the invoice gateway unavailable once a new order would exceed the credit limit', function () {
    $gateway = new InvoiceGateway();
    $company = creditTestCompany(50.0);
    completedOrderOnGateway($company, creditTestInvoiceGateway()->id, 40.0);

    $overLimitCart = cartForMember($company, 20.0);
    $withinLimitCart = cartForMember($company, 5.0);

    expect($gateway->availableForUseWithOrder($overLimitCart))->toBeFalse()
        ->and($gateway->availableForUseWithOrder($withinLimitCart))->toBeTrue();
});
