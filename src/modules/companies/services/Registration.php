<?php

namespace totalwebcreations\b2bcommerce\modules\companies\services;

use Craft;
use craft\elements\User;
use craft\helpers\App;
use totalwebcreations\b2bcommerce\elements\Company;
use totalwebcreations\b2bcommerce\enums\CompanyRole;
use totalwebcreations\b2bcommerce\Plugin;
use yii\base\Component;
use yii\base\InvalidArgumentException;

class Registration extends Component
{
    public function register(
        string $companyName,
        ?string $registrationNumber,
        ?string $taxId,
        string $firstName,
        string $lastName,
        string $email,
    ): Company {
        $existingUser = User::find()->email($email)->status(null)->one();

        if ($existingUser !== null) {
            throw new InvalidArgumentException(
                Craft::t('b2b-commerce', 'An account with this email address already exists.')
            );
        }

        $transaction = Craft::$app->getDb()->beginTransaction();

        try {
            $company = new Company();
            $company->title = $companyName;
            $company->registrationNumber = $registrationNumber;
            $company->taxId = $taxId;
            $company->companyStatus = Company::STATUS_PENDING;

            if (!Craft::$app->getElements()->saveElement($company)) {
                throw new InvalidArgumentException(implode(' ', $company->getFirstErrors()));
            }

            $user = new User();
            $user->username = $email;
            $user->email = $email;
            $user->firstName = $firstName;
            $user->lastName = $lastName;
            $user->pending = true;

            if (!Craft::$app->getElements()->saveElement($user)) {
                throw new InvalidArgumentException(implode(' ', $user->getFirstErrors()));
            }

            Plugin::getInstance()->companyMembers->addUserToCompany($user->id, $company->id, CompanyRole::Admin);

            $transaction->commit();
        } catch (\Throwable $e) {
            $transaction->rollBack();

            throw $e;
        }

        $this->notifyAdmin($company, $user);

        return $company;
    }

    private function notifyAdmin(Company $company, User $user): void
    {
        $to = Plugin::getInstance()->getSettings()->adminNotificationEmail
            ?: App::parseEnv(App::mailSettings()->fromEmail);

        if (!$to) {
            return;
        }

        $sent = Craft::$app->getMailer()
            ->compose()
            ->setTo($to)
            ->setSubject(Craft::t('b2b-commerce', 'New B2B registration: {company}', ['company' => $company->title]))
            ->setTextBody(Craft::t('b2b-commerce', '{name} ({email}) registered company "{company}". Review it in the control panel: {url}', [
                'name' => $user->getFullName(),
                'email' => $user->email,
                'company' => $company->title,
                'url' => $company->getCpEditUrl(),
            ]))
            ->send();

        if (!$sent) {
            Craft::warning("Failed to send registration notification to {$to}", 'b2b-commerce');
        }
    }
}
