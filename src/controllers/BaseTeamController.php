<?php

namespace totalwebcreations\b2bcommerce\controllers;

use Craft;
use craft\web\Controller;
use totalwebcreations\b2bcommerce\elements\Company;
use totalwebcreations\b2bcommerce\Plugin;
use yii\web\ForbiddenHttpException;

abstract class BaseTeamController extends Controller
{
    protected function requireCompany(): Company
    {
        $user = Craft::$app->getUser()->getIdentity();

        if ($user === null) {
            throw new ForbiddenHttpException();
        }

        $company = $user->b2bCompany;

        if ($company === null) {
            throw new ForbiddenHttpException();
        }

        return $company;
    }

    protected function requireTeamAdmin(): Company
    {
        $company = $this->requireCompany();
        $user = Craft::$app->getUser()->getIdentity();

        $role = Plugin::getInstance()->companyMembers->getRoleForUser($user->id, $company->id);

        if ($role?->canManageTeam() !== true) {
            throw new ForbiddenHttpException();
        }

        return $company;
    }
}
