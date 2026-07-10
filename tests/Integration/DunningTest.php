<?php

use totalwebcreations\b2bcommerce\Plugin;

function withDunning(array $offsets): void
{
    $settings = Plugin::getInstance()->getSettings();
    $settings->enableDunning = true;
    $settings->dunningOffsets = $offsets;
}

it('finds a reminder for an invoice past a configured offset', function () {
    withDunning([7]);
    $company = statementCompany(0);
    $order = completedOrderOnGateway($company, creditTestInvoiceGateway()->id, 100.0);
    backdateOrder($order, 10);

    $due = Plugin::getInstance()->dunning->dueReminders($company, new DateTimeImmutable('now'));

    expect($due)->toHaveCount(1)
        ->and($due[0]['offset'])->toBe(7)
        ->and((int) $due[0]['order']->id)->toBe((int) $order->id);
});

it('does not find a reminder before the invoice passes the offset', function () {
    withDunning([30]);
    $company = statementCompany(0);
    $order = completedOrderOnGateway($company, creditTestInvoiceGateway()->id, 100.0);
    backdateOrder($order, 10); // only 10 days overdue, offset is 30

    expect(Plugin::getInstance()->dunning->dueReminders($company, new DateTimeImmutable('now')))->toBeEmpty();
});

it('sends a reminder once and de-dups the second run', function () {
    withDunning([7]);
    $company = statementCompany(0);
    $order = completedOrderOnGateway($company, creditTestInvoiceGateway()->id, 100.0);
    backdateOrder($order, 10);

    $dunning = Plugin::getInstance()->dunning;

    expect($dunning->sendReminder($company, $order, 7, 10))->toBeTrue()
        ->and($dunning->hasReminderBeenSent((int) $order->id, 7))->toBeTrue()
        ->and($dunning->dueReminders($company, new DateTimeImmutable('now')))->toBeEmpty()
        ->and($dunning->sendReminder($company, $order, 7, 10))->toBeFalse();
});

it('sends each configured offset exactly once', function () {
    withDunning([7, 14]);
    $company = statementCompany(0);
    $order = completedOrderOnGateway($company, creditTestInvoiceGateway()->id, 100.0);
    backdateOrder($order, 20); // past both 7 and 14

    $dunning = Plugin::getInstance()->dunning;
    $due = $dunning->dueReminders($company, new DateTimeImmutable('now'));

    expect($due)->toHaveCount(2);

    foreach ($due as $reminder) {
        $dunning->sendReminder($company, $reminder['order'], $reminder['offset'], $reminder['daysPastDue']);
    }

    expect($dunning->dueReminders($company, new DateTimeImmutable('now')))->toBeEmpty();
});

it('never duns a fully paid invoice', function () {
    withDunning([7]);
    $company = statementCompany(0);
    $order = completedOrderOnGateway($company, creditTestInvoiceGateway()->id, 100.0);
    backdateOrder($order, 10);
    recordCreditPurchase($order, $order->getTotalPrice());

    expect(Plugin::getInstance()->dunning->dueReminders($company, new DateTimeImmutable('now')))->toBeEmpty();
});

it('never duns an overpaid invoice', function () {
    withDunning([7]);
    $company = statementCompany(0);
    $order = completedOrderOnGateway($company, creditTestInvoiceGateway()->id, 100.0);
    backdateOrder($order, 10);
    recordCreditPurchase($order, $order->getTotalPrice() + 50.0);

    expect(Plugin::getInstance()->dunning->dueReminders($company, new DateTimeImmutable('now')))->toBeEmpty();
});

it('does not log a failed send, so a later run retries the same reminder', function () {
    withDunning([7]);
    $company = statementCompany(0);
    $order = completedOrderOnGateway($company, creditTestInvoiceGateway()->id, 100.0);
    backdateOrder($order, 10);

    $dunning = Plugin::getInstance()->dunning;

    withMailerForcedToFail(function () use ($dunning, $company, $order) {
        expect($dunning->sendReminder($company, $order, 7, 10))->toBeFalse();
    });

    expect($dunning->hasReminderBeenSent((int) $order->id, 7))->toBeFalse()
        ->and($dunning->dueReminders($company, new DateTimeImmutable('now')))->toHaveCount(1);

    // A later run, once the mailer is healthy again, retries and succeeds instead of having
    // silently recorded the failed attempt as sent.
    expect($dunning->sendReminder($company, $order, 7, 10))->toBeTrue()
        ->and($dunning->hasReminderBeenSent((int) $order->id, 7))->toBeTrue();
});
