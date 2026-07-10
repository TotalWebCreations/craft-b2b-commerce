<?php

namespace totalwebcreations\b2bcommerce\console\controllers;

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
     * Sends overdue-invoice payment reminders for every company, once per configured day-offset.
     *
     * Cron-friendly. Opt-in via the enableDunning setting; a no-op when it is off. For each
     * outstanding invoice order past a dunningOffsets threshold with no reminder yet logged for that
     * offset, it emails the company's administrators and records the send so it never repeats. Send
     * failures are reported and counted but never abort the run: {@see \totalwebcreations\b2bcommerce\modules\invoicing\services\Dunning::sendReminder()}
     * never throws, and a per-company failure here is also caught so one company's problem never
     * stops the rest of the run.
     */
    public function actionRun(): int
    {
        $settings = Plugin::getInstance()->getSettings();

        if (!$settings->enableDunning) {
            $this->stdout("Dunning is disabled (enable it in the plugin settings).\n", Console::FG_YELLOW);

            return ExitCode::OK;
        }

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
