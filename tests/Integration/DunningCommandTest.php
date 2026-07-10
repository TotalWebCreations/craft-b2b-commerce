<?php

use totalwebcreations\b2bcommerce\console\controllers\DunningController;
use totalwebcreations\b2bcommerce\Plugin;
use yii\console\ExitCode;

function runDunningCommand(): int
{
    $controller = new DunningController('dunning', Plugin::getInstance());
    ob_start();

    try {
        return $controller->actionRun();
    } finally {
        ob_end_clean();
    }
}

it('is a no-op when dunning is disabled', function () {
    Plugin::getInstance()->getSettings()->enableDunning = false;
    $company = statementCompany(0);
    $order = completedOrderOnGateway($company, creditTestInvoiceGateway()->id, 100.0);
    backdateOrder($order, 30);

    expect(runDunningCommand())->toBe(ExitCode::OK)
        ->and(Plugin::getInstance()->dunning->hasReminderBeenSent((int) $order->id, 7))->toBeFalse();
});

it('sends and logs reminders when dunning is enabled', function () {
    $settings = Plugin::getInstance()->getSettings();
    $settings->enableDunning = true;
    $settings->dunningOffsets = [7];

    $company = statementCompany(0);
    $order = completedOrderOnGateway($company, creditTestInvoiceGateway()->id, 100.0);
    backdateOrder($order, 30);

    expect(runDunningCommand())->toBe(ExitCode::OK)
        ->and(Plugin::getInstance()->dunning->hasReminderBeenSent((int) $order->id, 7))->toBeTrue();
});
