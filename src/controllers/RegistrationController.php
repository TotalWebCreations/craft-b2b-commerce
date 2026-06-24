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

        $honeypotFieldName = Plugin::getInstance()->getSettings()->honeypotFieldName;

        if ($this->isHoneypotTriggered($request->getBodyParam($honeypotFieldName))) {
            return $this->successResponse();
        }

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

        return $this->successResponse();
    }

    private function isHoneypotTriggered(mixed $value): bool
    {
        if ($value === null) {
            return false;
        }

        // A non-string value (e.g. an array) only ever comes from a bot probing
        // the form, so we treat it as a trip without casting it to a string.
        if (!is_string($value)) {
            return true;
        }

        return $value !== '';
    }

    private function successResponse(): Response
    {
        return $this->asSuccess(Craft::t('b2b-commerce', 'Thanks! Your registration is pending review. You will receive an email once your account is approved.'));
    }
}
