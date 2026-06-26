<?php

namespace totalwebcreations\b2bcommerce\controllers;

use Craft;
use totalwebcreations\b2bcommerce\controllers\concerns\ReadsStringBodyParams;
use totalwebcreations\b2bcommerce\Plugin;
use yii\base\InvalidArgumentException;
use yii\web\Response;

class AddressesController extends BaseTeamController
{
    use ReadsStringBodyParams;

    public function actionSave(): ?Response
    {
        $this->requirePostRequest();
        $company = $this->requireTeamAdmin();

        $addressIdParam = $this->stringBodyParam('addressId');
        $addressId = $addressIdParam !== '' ? (int)$addressIdParam : null;

        $attributes = [
            'title' => $this->stringBodyParam('title'),
            'fullName' => $this->stringBodyParam('fullName'),
            'addressLine1' => $this->stringBodyParam('addressLine1'),
            'addressLine2' => $this->stringBodyParam('addressLine2'),
            'postalCode' => $this->stringBodyParam('postalCode'),
            'locality' => $this->stringBodyParam('locality'),
            'administrativeArea' => $this->stringBodyParam('administrativeArea'),
            'countryCode' => $this->stringBodyParam('countryCode'),
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
