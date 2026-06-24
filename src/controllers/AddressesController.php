<?php

namespace totalwebcreations\b2bcommerce\controllers;

use Craft;
use totalwebcreations\b2bcommerce\Plugin;
use yii\base\InvalidArgumentException;
use yii\web\Response;

class AddressesController extends BaseTeamController
{
    public function actionSave(): ?Response
    {
        $this->requirePostRequest();
        $company = $this->requireTeamAdmin();
        $request = Craft::$app->getRequest();

        $addressId = $request->getBodyParam('addressId');
        $addressId = $addressId !== null && $addressId !== '' ? (int)$addressId : null;

        $attributes = [
            'title' => $request->getBodyParam('title'),
            'fullName' => $request->getBodyParam('fullName'),
            'addressLine1' => $request->getBodyParam('addressLine1'),
            'addressLine2' => $request->getBodyParam('addressLine2'),
            'postalCode' => $request->getBodyParam('postalCode'),
            'locality' => $request->getBodyParam('locality'),
            'administrativeArea' => $request->getBodyParam('administrativeArea'),
            'countryCode' => $request->getBodyParam('countryCode'),
        ];

        try {
            Plugin::getInstance()->companyAddresses->saveAddress($company, $attributes, $addressId);
        } catch (InvalidArgumentException $exception) {
            return $this->asFailure($exception->getMessage());
        }

        return $this->asSuccess(Craft::t('b2b-commerce', 'Address saved.'));
    }

    public function actionDelete(): ?Response
    {
        $this->requirePostRequest();
        $company = $this->requireTeamAdmin();
        $request = Craft::$app->getRequest();

        try {
            Plugin::getInstance()->companyAddresses->deleteAddress(
                $company,
                (int)$request->getRequiredBodyParam('addressId'),
            );
        } catch (InvalidArgumentException $exception) {
            return $this->asFailure($exception->getMessage());
        }

        return $this->asSuccess(Craft::t('b2b-commerce', 'Address deleted.'));
    }
}
