<?php

use totalwebcreations\b2bcommerce\Plugin;

it('buckets an outstanding invoice by its days past due', function () {
    $company = statementCompany(14);
    $order = completedOrderOnGateway($company, creditTestInvoiceGateway()->id, 200.0);
    backdateOrder($order, 20); // due 6 days ago -> 1-30 bucket

    $statement = Plugin::getInstance()->statements->getStatement($company->id, new DateTimeImmutable('now'));

    expect($statement['buckets']['1-30'])->toBe($order->getOutstandingBalance())
        ->and($statement['buckets']['current'])->toBe(0.0)
        ->and($statement['totalOutstanding'])->toBe($order->getOutstandingBalance())
        ->and($statement['lines'])->toHaveCount(1)
        ->and($statement['lines'][0]['bucket'])->toBe('1-30');
});

it('places a not-yet-due invoice in the current bucket', function () {
    $company = statementCompany(30);
    $order = completedOrderOnGateway($company, creditTestInvoiceGateway()->id, 150.0);
    backdateOrder($order, 5); // due in 25 days

    $statement = Plugin::getInstance()->statements->getStatement($company->id, new DateTimeImmutable('now'));

    expect($statement['buckets']['current'])->toBe($order->getOutstandingBalance());
});

it('excludes a settled (refunded) invoice from the statement', function () {
    $company = statementCompany(0);
    $active = completedOrderOnGateway($company, creditTestInvoiceGateway()->id, 100.0);
    $refunded = completedOrderOnGateway($company, creditTestInvoiceGateway()->id, 300.0);
    backdateOrder($active, 40);
    backdateOrder($refunded, 40);
    setOrderStatusHandle($refunded, 'refunded');

    $statement = Plugin::getInstance()->statements->getStatement($company->id, new DateTimeImmutable('now'));

    expect($statement['totalOutstanding'])->toBe($active->getOutstandingBalance())
        ->and($statement['lines'])->toHaveCount(1);
});

it('excludes a fully paid invoice from the statement', function () {
    $company = statementCompany(0);
    $order = completedOrderOnGateway($company, creditTestInvoiceGateway()->id, 80.0);
    backdateOrder($order, 40);
    recordCreditPurchase($order, $order->getTotalPrice());

    $statement = Plugin::getInstance()->statements->getStatement($company->id, new DateTimeImmutable('now'));

    expect($statement['totalOutstanding'])->toBe(0.0)
        ->and($statement['lines'])->toBeEmpty();
});

it('excludes an overpaid invoice from the statement', function () {
    $company = statementCompany(0);
    $order = completedOrderOnGateway($company, creditTestInvoiceGateway()->id, 80.0);
    backdateOrder($order, 40);
    recordCreditPurchase($order, $order->getTotalPrice() + 20.0);

    $statement = Plugin::getInstance()->statements->getStatement($company->id, new DateTimeImmutable('now'));

    expect($statement['totalOutstanding'])->toBe(0.0);
});
