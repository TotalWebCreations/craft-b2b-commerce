<?php

namespace totalwebcreations\b2bcommerce\console\controllers;

use craft\console\Controller;
use totalwebcreations\b2bcommerce\elements\Company;
use totalwebcreations\b2bcommerce\Plugin;
use yii\console\ExitCode;
use yii\helpers\Console;

/**
 * VAT ID maintenance commands.
 */
class TaxIdController extends Controller
{
    /**
     * Revalidates the VAT ID of every company that has one against VIES.
     *
     * Bypasses the known-valid cache so every VAT ID is genuinely re-checked. Cron-friendly:
     * companies whose VAT ID cannot be checked because VIES is unreachable are skipped with a
     * warning and the command exits with code 75 (TEMPFAIL) so schedulers can retry; otherwise it
     * exits 0, with invalid VAT IDs reported per company for the merchant to follow up.
     */
    public function actionRevalidate(): int
    {
        /** @var Company[] $companies */
        $companies = Company::find()->site('*')->unique()->status(null)->all();
        $companies = array_filter(
            $companies,
            fn(Company $company): bool => trim((string) $company->taxId) !== ''
        );

        if ($companies === []) {
            $this->stdout("No companies with a VAT ID found.\n");

            return ExitCode::OK;
        }

        $service = Plugin::getInstance()->taxIdValidation;
        $valid = 0;
        $invalid = 0;
        $skipped = 0;

        foreach ($companies as $company) {
            $this->stdout("Validating \"{$company->title}\" ({$company->taxId})... ");

            $result = $service->validate($company->taxId, refresh: true);

            if ($result === null) {
                $skipped++;
                $this->stdout("skipped: VIES unreachable\n", Console::FG_YELLOW);

                continue;
            }

            if ($result === false) {
                $invalid++;
                $this->stdout("invalid\n", Console::FG_RED);

                continue;
            }

            $valid++;
            $this->stdout("valid\n", Console::FG_GREEN);
        }

        $this->stdout(sprintf(
            "Done: %d valid, %d invalid, %d skipped (VIES unreachable).\n",
            $valid,
            $invalid,
            $skipped
        ));

        if ($skipped > 0) {
            $this->stderr("VIES was unreachable for {$skipped} VAT ID(s); run the command again later.\n", Console::FG_YELLOW);

            return ExitCode::TEMPFAIL;
        }

        return ExitCode::OK;
    }
}
