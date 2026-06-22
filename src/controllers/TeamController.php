<?php

namespace totalwebcreations\b2bcommerce\controllers;

use Craft;
use craft\web\Controller;
use totalwebcreations\b2bcommerce\elements\Company;
use totalwebcreations\b2bcommerce\enums\CompanyRole;
use totalwebcreations\b2bcommerce\Plugin;
use yii\base\InvalidArgumentException;
use yii\web\ForbiddenHttpException;
use yii\web\Response;

class TeamController extends Controller
{
    public function actionInvite(): ?Response
    {
        $this->requirePostRequest();
        $company = $this->requireTeamAdmin();
        $request = Craft::$app->getRequest();

        $role = CompanyRole::tryFrom((string)$request->getRequiredBodyParam('role'));

        if ($role === null) {
            return $this->asFailure(Craft::t('b2b-commerce', 'Invalid role.'));
        }

        try {
            Plugin::getInstance()->companyMembers->inviteMember(
                $company,
                (string)$request->getRequiredBodyParam('email'),
                (string)$request->getRequiredBodyParam('firstName'),
                (string)$request->getRequiredBodyParam('lastName'),
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

        $role = CompanyRole::tryFrom((string)$request->getRequiredBodyParam('role'));

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

    private function requireTeamAdmin(): Company
    {
        $user = Craft::$app->getUser()->getIdentity();

        if ($user === null) {
            throw new ForbiddenHttpException();
        }

        $company = $user->b2bCompany;

        if ($company === null) {
            throw new ForbiddenHttpException();
        }

        $role = Plugin::getInstance()->companyMembers->getRoleForUser($user->id, $company->id);

        if ($role?->canManageTeam() !== true) {
            throw new ForbiddenHttpException();
        }

        return $company;
    }
}
