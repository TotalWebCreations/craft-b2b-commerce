<?php

namespace totalwebcreations\b2bcommerce\console\controllers;

use Craft;
use craft\console\Controller;
use craft\elements\User;
use totalwebcreations\b2bcommerce\elements\Company;
use totalwebcreations\b2bcommerce\enums\CompanyRole;
use totalwebcreations\b2bcommerce\Plugin;
use yii\console\ExitCode;

class SeedController extends Controller
{
    public function actionIndex(): int
    {
        $existingUser = User::find()->email('buyer@acme.test')->status(null)->one();

        if ($existingUser) {
            $this->stdout("Demo data already seeded (user #{$existingUser->id} exists). Nothing to do.\n");

            return ExitCode::OK;
        }

        $existingCompany = Company::find()->title('Acme Wholesale Ltd')->status(null)->one();

        if ($existingCompany) {
            $this->stdout("Demo data already seeded (company #{$existingCompany->id} exists). Nothing to do.\n");

            return ExitCode::OK;
        }

        $this->stdout("Creating demo company with admin user...\n");

        $company = new Company();
        $company->title = 'Acme Wholesale Ltd';
        $company->registrationNumber = '12345678';
        $company->taxId = 'NL123456789B01';
        $company->companyStatus = Company::STATUS_APPROVED;
        $company->creditLimit = 5000;
        $company->paymentTermDays = 30;
        $company->allowInvoicePayment = true;

        if (!Craft::$app->getElements()->saveElement($company)) {
            $this->stderr('Failed: ' . implode(', ', $company->getFirstErrors()) . "\n");

            return ExitCode::UNSPECIFIED_ERROR;
        }

        $user = new User();
        $user->username = 'buyer@acme.test';
        $user->email = 'buyer@acme.test';
        $user->firstName = 'Demo';
        $user->lastName = 'Buyer';
        $user->active = true;

        if (!Craft::$app->getElements()->saveElement($user)) {
            $this->stderr('Failed: ' . implode(', ', $user->getFirstErrors()) . "\n");

            return ExitCode::UNSPECIFIED_ERROR;
        }

        Plugin::getInstance()->companyMembers->addUserToCompany($user->id, $company->id, CompanyRole::Admin);

        $this->stdout("Created company #{$company->id} with user #{$user->id} (admin).\n");

        return ExitCode::OK;
    }
}
