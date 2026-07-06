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

        // Pricing-group membership follows the status change automatically: saving the company here
        // fires Company::afterSave, which resyncs members whenever the status changed — approving
        // places members in the pricing group, blocking removes them. See Company::afterSave and
        // CustomerGroupSync.
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

            if (!Craft::$app->getUsers()->sendActivationEmail($user)) {
                Craft::warning("Failed to send activation email to {$user->email}", 'b2b-commerce');
            }
        }
    }

    private function notifyMembers(Company $company, string $messageKey): void
    {
        foreach ($this->getMemberUsers($company) as $user) {
            $sent = Craft::$app->getMailer()
                ->composeFromKey($messageKey, ['company' => $company, 'user' => $user])
                ->setTo($user)
                ->send();

            if (!$sent) {
                Craft::warning("Failed to send `{$messageKey}` email to {$user->email}", 'b2b-commerce');
            }
        }
    }

    /** @return User[] */
    private function getMemberUsers(Company $company): array
    {
        return array_column(
            Plugin::getInstance()->companyMembers->getMemberUsers($company->id),
            'user'
        );
    }
}
