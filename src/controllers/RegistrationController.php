<?php

namespace totalwebcreations\b2bcommerce\controllers;

use Craft;
use craft\web\Controller;
use totalwebcreations\b2bcommerce\Plugin;
use yii\base\InvalidArgumentException;
use yii\web\Response;

class RegistrationController extends Controller
{
    protected array|bool|int $allowAnonymous = ['register' => self::ALLOW_ANONYMOUS_LIVE];

    public function actionRegister(): ?Response
    {
        $this->requirePostRequest();
        $request = Craft::$app->getRequest();

        try {
            Plugin::getInstance()->registration->register(
                companyName: (string)$request->getRequiredBodyParam('companyName'),
                registrationNumber: $request->getBodyParam('registrationNumber'),
                taxId: $request->getBodyParam('taxId'),
                firstName: (string)$request->getRequiredBodyParam('firstName'),
                lastName: (string)$request->getRequiredBodyParam('lastName'),
                email: (string)$request->getRequiredBodyParam('email'),
            );
        } catch (InvalidArgumentException $exception) {
            return $this->asFailure($exception->getMessage());
        }

        return $this->asSuccess(Craft::t('b2b-commerce', 'Thanks! Your registration is pending review. You will receive an email once your account is approved.'));
    }
}
