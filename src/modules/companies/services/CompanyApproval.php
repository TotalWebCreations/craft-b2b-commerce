<?php

namespace totalwebcreations\b2bcommerce\modules\companies\services;

use Craft;
use craft\elements\User;
use totalwebcreations\b2bcommerce\elements\Company;
use totalwebcreations\b2bcommerce\enums\CompanyStatus;
use totalwebcreations\b2bcommerce\Plugin;
use yii\base\Component;
use yii\base\InvalidArgumentException;

class CompanyApproval extends Component
{
    public function approve(Company $company): void
    {
        $this->transition($company, CompanyStatus::Approved);
        $this->activateMembers($company);
        $this->notifyMembers($company, 'b2b_company_approved');
    }

    public function block(Company $company): void
    {
        $this->transition($company, CompanyStatus::Blocked);
    }

    private function transition(Company $company, CompanyStatus $target): void
    {
        $current = CompanyStatus::from($company->companyStatus);

        if (!$current->canTransitionTo($target)) {
            throw new InvalidArgumentException(
                Craft::t('b2b-commerce', 'Cannot change status from {from} to {to}.', [
                    'from' => $current->value,
                    'to' => $target->value,
                ])
            );
        }

        $company->companyStatus = $target->value;

        if (!Craft::$app->getElements()->saveElement($company)) {
            throw new InvalidArgumentException(implode(' ', $company->getFirstErrors()));
        }
    }

    private function activateMembers(Company $company): void
    {
        foreach ($this->getMemberUsers($company) as $user) {
            if (!$user->pending) {
                continue;
            }

            Craft::$app->getUsers()->sendActivationEmail($user);
        }
    }

    private function notifyMembers(Company $company, string $messageKey): void
    {
        foreach ($this->getMemberUsers($company) as $user) {
            Craft::$app->getMailer()
                ->composeFromKey($messageKey, ['company' => $company, 'user' => $user])
                ->setTo($user)
                ->send();
        }
    }

    /** @return User[] */
    private function getMemberUsers(Company $company): array
    {
        $userIds = array_column(Plugin::getInstance()->companyMembers->getMembers($company->id), 'userId');

        if ($userIds === []) {
            return [];
        }

        return User::find()->id($userIds)->status(null)->all();
    }
}
