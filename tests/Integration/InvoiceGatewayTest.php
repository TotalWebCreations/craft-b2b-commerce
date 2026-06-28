<?php

use craft\commerce\elements\Order;
use craft\commerce\Plugin as Commerce;
use craft\elements\User;
use totalwebcreations\b2bcommerce\elements\Company;
use totalwebcreations\b2bcommerce\enums\CompanyRole;
use totalwebcreations\b2bcommerce\gateways\InvoiceGateway;
use totalwebcreations\b2bcommerce\Plugin;

/**
 * Saves an empty cart order for the given customer (or a guest when null) and
 * tracks it for hard-delete afterwards.
 */
function createGatewayTestOrder(?User $customer): Order
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
 * Creates a tracked company with the given status and persists the
 * allowInvoicePayment flag and credit limit so getCompanyForUser rehydrates them
 * from the database. A credit limit is required for the gateway to consider the
 * company to have credit room.
 */
function createInvoiceCompany(string $status, bool $allowInvoicePayment, ?float $creditLimit = 1000.0): Company
{
    $company = createTestCompany($status);
    $company->allowInvoicePayment = $allowInvoicePayment;
    $company->creditLimit = $creditLimit;

    if (!craftApp()->getElements()->saveElement($company)) {
        throw new RuntimeException('Could not save test company: ' . implode(', ', $company->getFirstErrors()));
    }

    return $company;
}

/**
 * Attaches a fresh user to a company and returns an order for that user.
 */
function orderForCompanyMember(Company $company): Order
{
    $user = createTestUser('invoicegw_' . uniqid() . '@example.test');
    Plugin::getInstance()->companyMembers->addUserToCompany($user->id, $company->id, CompanyRole::Admin);

    return createGatewayTestOrder($user);
}

it('registers the gateway type with Commerce', function () {
    expect(Commerce::getInstance()->getGateways()->getAllGatewayTypes())
        ->toContain(InvoiceGateway::class);
});

it('names the gateway "Pay on account"', function () {
    expect(InvoiceGateway::displayName())->toBe('Pay on account');
});

it('is unavailable for a guest order', function () {
    $gateway = new InvoiceGateway();
    $order = createGatewayTestOrder(null);

    expect($gateway->availableForUseWithOrder($order))->toBeFalse();
});

it('is unavailable for a pending company', function () {
    $gateway = new InvoiceGateway();
    $company = createInvoiceCompany(Company::STATUS_PENDING, true);
    $order = orderForCompanyMember($company);

    expect($gateway->availableForUseWithOrder($order))->toBeFalse();
});

it('is unavailable for an approved company without the invoice flag', function () {
    $gateway = new InvoiceGateway();
    $company = createInvoiceCompany(Company::STATUS_APPROVED, false);
    $order = orderForCompanyMember($company);

    expect($gateway->availableForUseWithOrder($order))->toBeFalse();
});

it('is available for an approved company with the invoice flag', function () {
    $gateway = new InvoiceGateway();
    $company = createInvoiceCompany(Company::STATUS_APPROVED, true);
    $order = orderForCompanyMember($company);

    expect($gateway->availableForUseWithOrder($order))->toBeTrue();
});

it('is unavailable when invoicing is toggled off', function () {
    $gateway = new InvoiceGateway();
    $company = createInvoiceCompany(Company::STATUS_APPROVED, true);
    $order = orderForCompanyMember($company);

    $plugin = Plugin::getInstance();
    Craft::$app->getPlugins()->savePluginSettings($plugin, ['enableInvoicing' => false]);

    try {
        expect($gateway->availableForUseWithOrder($order))->toBeFalse();
    } finally {
        Craft::$app->getPlugins()->savePluginSettings($plugin, ['enableInvoicing' => true]);
    }
});
