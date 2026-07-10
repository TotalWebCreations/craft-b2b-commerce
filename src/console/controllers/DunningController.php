<?php

namespace totalwebcreations\b2bcommerce\console\controllers;

use Craft;
use craft\console\Controller;
use DateTimeImmutable;
use totalwebcreations\b2bcommerce\elements\Company;
use totalwebcreations\b2bcommerce\Plugin;
use yii\console\ExitCode;
use yii\helpers\Console;

/**
 * Dunning (overdue-invoice payment reminder) commands.
 */
class DunningController extends Controller
{
    /**
     * The named mutex lock guarding a run, so two overlapping invocations (e.g. an overrunning cron
     * job) can never both compute and send reminders for the same companies at once.
     */
    private const MUTEX_LOCK_NAME = 'b2b-commerce:dunning:run';

    /**
     * Sends overdue-invoice payment reminders for every company, once per configured day-offset.
     *
     * Cron-friendly. Opt-in via the enableDunning setting; a no-op when it is off. For each
     * outstanding invoice order past a dunningOffsets threshold with no reminder yet logged for that
     * offset, it emails the company's administrators and records the send so it never repeats. Send
     * failures are reported and counted but never abort the run: {@see \totalwebcreations\b2bcommerce\modules\invoicing\services\Dunning::sendReminder()}
     * never throws, and a per-company failure here is also caught so one company's problem never
     * stops the rest of the run. The whole run is guarded by a short-timeout named mutex, so an
     * overlapping invocation skips cleanly instead of racing the first one and double-sending.
     */
    public function actionRun(): int
    {
        $settings = Plugin::getInstance()->getSettings();

        if (!$settings->enableDunning) {
            $this->stdout("Dunning is disabled (enable it in the plugin settings).\n", Console::FG_YELLOW);

            return ExitCode::OK;
        }

        $mutex = Craft::$app->getMutex();

        if (!$mutex->acquire(self::MUTEX_LOCK_NAME, 3)) {
            $this->stdout("Another dunning run is already in progress; skipping.\n", Console::FG_YELLOW);

            return ExitCode::OK;
        }

        try {
            return $this->runDunning();
        } finally {
            $mutex->release(self::MUTEX_LOCK_NAME);
        }
    }

    private function runDunning(): int
    {
        $dunning = Plugin::getInstance()->dunning;
        $asOf = new DateTimeImmutable('now');

        /** @var Company[] $companies */
        $companies = Company::find()->site('*')->unique()->status(null)->all();

        $sent = 0;
        $failed = 0;

        foreach ($companies as $company) {
            try {
                $reminders = $dunning->dueReminders($company, $asOf);
            } catch (\Throwable $e) {
                // A single company's outstanding-orders lookup must never abort the run for every
                // other company.
                $failed++;
                $this->stderr("Could not compute dunning reminders for \"{$company->title}\": {$e->getMessage()}\n", Console::FG_RED);

                continue;
            }

            foreach ($reminders as $reminder) {
                $order = $reminder['order'];
                $reference = $order->reference ?: $order->getShortNumber();

                // Output before processing so a hang is attributable to the invoice being sent.
                $this->stdout(sprintf(
                    "Reminding \"%s\" about invoice %s (%d day(s) overdue, offset %d)... ",
                    $company->title,
                    $reference,
                    $reminder['daysPastDue'],
                    $reminder['offset'],
                ));

                if ($dunning->sendReminder($company, $order, $reminder['offset'], $reminder['daysPastDue'])) {
                    $sent++;
                    $this->stdout("sent\n", Console::FG_GREEN);

                    continue;
                }

                $failed++;
                $this->stdout("failed\n", Console::FG_RED);
            }
        }

        $this->stdout(sprintf("Done: %d reminder(s) sent, %d failed.\n", $sent, $failed));

        return ExitCode::OK;
    }
}
