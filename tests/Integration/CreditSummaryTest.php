<?php

use craft\commerce\elements\Order;
use craft\commerce\Plugin as Commerce;
use craft\elements\User;
use totalwebcreations\b2bcommerce\elements\Company;
use totalwebcreations\b2bcommerce\enums\CompanyRole;
use totalwebcreations\b2bcommerce\Plugin;
use totalwebcreations\b2bcommerce\variables\B2bVariable;

/**
 * Creates a tracked, approved company with the given credit limit and payment term.
 */
function summaryCompany(?float $creditLimit = null, ?int $paymentTermDays = null): Company
{
    $company = createTestCompany(Company::STATUS_APPROVED);
    $company->creditLimit = $creditLimit;
    $company->paymentTermDays = $paymentTermDays;

    if (!craftApp()->getElements()->saveElement($company)) {
        throw new RuntimeException('Could not save test company: ' . implode(', ', $company->getFirstErrors()));
    }

    return $company;
}

/**
 * Completes a tracked order for a fresh member of the company, carrying a single line item.
 * Completing links the customer's company (Order::EVENT_AFTER_COMPLETE_ORDER) and stamps
 * dateOrdered exactly as a real checkout would.
 */
function summaryCompletedOrder(Company $company): Order
{
    $user = createTestUser('creditsummary_' . uniqid() . '@example.test');
    Plugin::getInstance()->companyMembers->addUserToCompany($user->id, $company->id, CompanyRole::Admin);

    $variant = createTestVariant('CSM-' . uniqid(), 25.0);

    $order = new Order();
    $order->number = md5(uniqid((string) mt_rand(), true));
    $order->setCustomer($user);

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
 * Runs the callback while $user is the signed-in identity, restoring the previous identity after.
 */
function asSummaryIdentity(User $user, callable $callback): void
{
    $userComponent = craftApp()->getUser();
    $previous = $userComponent->getIdentity();
    $userComponent->setIdentity($user);

    try {
        $callback();
    } finally {
        $userComponent->setIdentity($previous);
    }
}

it('computes the payment due date from the order date and the company payment term', function () {
    $company = summaryCompany(null, 30);
    $order = summaryCompletedOrder($company);

    $expected = (clone $order->dateOrdered)->modify('+30 days');

    expect($order->b2bPaymentDueDate)->not->toBeNull()
        ->and($order->b2bPaymentDueDate->format('Y-m-d'))->toBe($expected->format('Y-m-d'));
});

it('returns no due date when the company has no payment term', function () {
    $company = summaryCompany(null, null);
    $order = summaryCompletedOrder($company);

    expect($order->b2bPaymentDueDate)->toBeNull();
});

it('returns no due date for an order that is not completed', function () {
    $company = summaryCompany(null, 30);
    $user = createTestUser('creditsummary_' . uniqid() . '@example.test');
    Plugin::getInstance()->companyMembers->addUserToCompany($user->id, $company->id, CompanyRole::Admin);

    $order = new Order();
    $order->number = md5(uniqid((string) mt_rand(), true));
    $order->setCustomer($user);

    if (!craftApp()->getElements()->saveElement($order)) {
        throw new RuntimeException('Could not save test cart: ' . implode(', ', $order->getFirstErrors()));
    }

    trackElement($order);

    expect($order->b2bPaymentDueDate)->toBeNull();
});

it('exposes no credit summary when the visitor has no company', function () {
    $user = createTestUser('creditsummary_nocompany_' . uniqid() . '@example.test');
    $variable = new B2bVariable();

    asSummaryIdentity($user, function () use ($variable) {
        expect($variable->getCreditSummary())->toBeNull();
    });
});

it('reports the available room under a company credit limit', function () {
    $company = summaryCompany(500.0);
    $user = createTestUser('creditsummary_limit_' . uniqid() . '@example.test');
    Plugin::getInstance()->companyMembers->addUserToCompany($user->id, $company->id, CompanyRole::Admin);

    $variable = new B2bVariable();

    asSummaryIdentity($user, function () use ($variable) {
        $summary = $variable->getCreditSummary();

        expect($summary)->not->toBeNull()
            ->and($summary['outstanding'])->toBe(0.0)
            ->and($summary['creditLimit'])->toBe(500.0)
            ->and($summary['available'])->toBe(500.0);
    });
});

it('reports a null credit limit and available room when none is set', function () {
    $company = summaryCompany(null);
    $user = createTestUser('creditsummary_nolimit_' . uniqid() . '@example.test');
    Plugin::getInstance()->companyMembers->addUserToCompany($user->id, $company->id, CompanyRole::Admin);

    $variable = new B2bVariable();

    asSummaryIdentity($user, function () use ($variable) {
        $summary = $variable->getCreditSummary();

        expect($summary)->not->toBeNull()
            ->and($summary['outstanding'])->toBe(0.0)
            ->and($summary['creditLimit'])->toBeNull()
            ->and($summary['available'])->toBeNull();
    });
});
