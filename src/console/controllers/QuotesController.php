<?php

namespace totalwebcreations\b2bcommerce\console\controllers;

use craft\console\Controller;
use totalwebcreations\b2bcommerce\Plugin;
use yii\console\ExitCode;

/**
 * Maintenance commands for the quote workflow.
 */
class QuotesController extends Controller
{
    /**
     * Expires every still-open quote whose validity window has closed.
     *
     * Cron-friendly: run it on a schedule (for example hourly) so quotes with a
     * validUntil in the past flip to expired without any manual intervention.
     */
    public function actionExpire(): int
    {
        $this->stdout("Expiring overdue quotes...\n");

        $count = Plugin::getInstance()->quotes->expireOverdue();

        $this->stdout("Expired {$count} quote(s).\n");

        return ExitCode::OK;
    }
}
