<?php

namespace totalwebcreations\b2bcommerce\controllers;

use Craft;
use totalwebcreations\b2bcommerce\controllers\concerns\ReadsStringBodyParams;
use totalwebcreations\b2bcommerce\enums\CompanyRole;
use totalwebcreations\b2bcommerce\Plugin;
use yii\base\InvalidArgumentException;
use yii\web\Response;

class TeamController extends BaseTeamController
{
    use ReadsStringBodyParams;

    public function actionInvite(): ?Response
    {
        $this->requirePostRequest();
        $company = $this->requireTeamAdmin();
        $request = Craft::$app->getRequest();

        $role = CompanyRole::tryFrom($this->requiredStringBodyParam('role'));

        if ($role === null) {
            return $this->asFailure(Craft::t('b2b-commerce', 'Invalid role.'));
        }

        try {
            Plugin::getInstance()->companyMembers->inviteMember(
                $company,
                $this->requiredStringBodyParam('email'),
                $this->requiredStringBodyParam('firstName'),
                $this->requiredStringBodyParam('lastName'),
                $role,
            );
        } catch (InvalidArgumentException $exception) {
            return $this->asFailure($exception->getMessage());
        }

        return $this->asSuccess(Craft::t('b2b-commerce', 'Invitation sent.'));
    }

    public function actionChangeRole(): ?Response
    {
        $this->requirePostRequest();
        $company = $this->requireTeamAdmin();
        $request = Craft::$app->getRequest();

        $role = CompanyRole::tryFrom($this->requiredStringBodyParam('role'));

        if ($role === null) {
            return $this->asFailure(Craft::t('b2b-commerce', 'Invalid role.'));
        }

        try {
            Plugin::getInstance()->companyMembers->changeRole(
                $company,
                (int)$request->getRequiredBodyParam('userId'),
                $role,
            );
        } catch (InvalidArgumentException $exception) {
            return $this->asFailure($exception->getMessage());
        }

        return $this->asSuccess(Craft::t('b2b-commerce', 'Role updated.'));
    }

    public function actionRemove(): ?Response
    {
        $this->requirePostRequest();
        $company = $this->requireTeamAdmin();
        $request = Craft::$app->getRequest();

        try {
            Plugin::getInstance()->companyMembers->removeMember(
                $company,
                (int)$request->getRequiredBodyParam('userId'),
            );
        } catch (InvalidArgumentException $exception) {
            return $this->asFailure($exception->getMessage());
        }

        return $this->asSuccess(Craft::t('b2b-commerce', 'Member removed.'));
    }
}
