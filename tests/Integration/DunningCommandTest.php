<?php

use Craft;
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

/**
 * A test-only DunningController subclass that captures stdout writes into a property instead of the
 * real STDOUT stream. Necessary because yii\console\Controller::stdout() writes via
 * fwrite(\STDOUT, ...), which ob_start()/ob_get_clean() cannot intercept -- a plain output-buffering
 * capture always sees an empty string for it. Production code and behavior are otherwise untouched.
 */
class CapturingDunningController extends DunningController
{
    public string $capturedOutput = '';

    public function stdout($string)
    {
        $this->capturedOutput .= $string;

        return strlen($string);
    }
}

/**
 * Like runDunningCommand(), but returns the captured stdout alongside the exit code instead of
 * discarding it, so a test can assert on the per-reminder and summary output lines.
 *
 * @return array{0: int, 1: string}
 */
function runDunningCommandCapturingOutput(): array
{
    $controller = new CapturingDunningController('dunning', Plugin::getInstance());

    $exitCode = $controller->actionRun();

    return [$exitCode, $controller->capturedOutput];
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

it('still sends the second reminder after the first fails, counting one success and one failure', function () {
    $settings = Plugin::getInstance()->getSettings();
    $settings->enableDunning = true;
    $settings->dunningOffsets = [7];

    $companyA = statementCompany(0);
    $orderA = completedOrderOnGateway($companyA, creditTestInvoiceGateway()->id, 100.0);
    backdateOrder($orderA, 30);
    $failingRecipient = companyAdminEmail($companyA);

    $companyB = statementCompany(0);
    $orderB = completedOrderOnGateway($companyB, creditTestInvoiceGateway()->id, 100.0);
    backdateOrder($orderB, 30);

    $exitCode = null;
    $output = '';

    // Only companyA's reminder is forced to fail (targeted by recipient, not by processing order), so
    // this is deterministic regardless of which company the controller happens to reach first.
    withMailerFailingForRecipient($failingRecipient, function () use (&$exitCode, &$output) {
        [$exitCode, $output] = runDunningCommandCapturingOutput();
    });

    expect($exitCode)->toBe(ExitCode::OK)
        ->and(Plugin::getInstance()->dunning->hasReminderBeenSent((int) $orderA->id, 7))->toBeFalse()
        ->and(Plugin::getInstance()->dunning->hasReminderBeenSent((int) $orderB->id, 7))->toBeTrue();

    // The one failure never aborted the batch: companyB's reminder still went out.
    expect($output)->toMatch('/Reminding "' . preg_quote($companyA->title, '/') . '".*failed/')
        ->and($output)->toMatch('/Reminding "' . preg_quote($companyB->title, '/') . '".*sent/');

    // The run summary reflects both outcomes (at least one sent, at least one failed): the dev site
    // may carry other dunning-eligible fixtures alongside this test's own two, so the counts are
    // asserted as lower bounds rather than exact totals.
    preg_match('/Done: (\d+) reminder\(s\) sent, (\d+) failed\./', $output, $matches);

    expect($matches)->toHaveCount(3)
        ->and((int) $matches[1])->toBeGreaterThanOrEqual(1)
        ->and((int) $matches[2])->toBeGreaterThanOrEqual(1);
});

it('skips cleanly and sends nothing when another run already holds the mutex', function () {
    $settings = Plugin::getInstance()->getSettings();
    $settings->enableDunning = true;
    $settings->dunningOffsets = [7];

    $company = statementCompany(0);
    $order = completedOrderOnGateway($company, creditTestInvoiceGateway()->id, 100.0);
    backdateOrder($order, 30);

    $mutex = Craft::$app->getMutex();
    $lockName = 'b2b-commerce:dunning:run';

    expect($mutex->acquire($lockName, 3))->toBeTrue();
    $mailBefore = mailCount();

    try {
        [$exitCode, $output] = runDunningCommandCapturingOutput();

        expect($exitCode)->toBe(ExitCode::OK)
            ->and($output)->toMatch('/Another dunning run is already in progress; skipping\./')
            ->and(mailCount())->toBe($mailBefore)
            ->and(Plugin::getInstance()->dunning->hasReminderBeenSent((int) $order->id, 7))->toBeFalse();
    } finally {
        $mutex->release($lockName);
    }
});
