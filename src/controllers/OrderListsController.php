<?php

namespace totalwebcreations\b2bcommerce\controllers;

use Craft;
use craft\commerce\Plugin as Commerce;
use totalwebcreations\b2bcommerce\controllers\concerns\ReadsStringBodyParams;
use totalwebcreations\b2bcommerce\controllers\concerns\RequiresFeature;
use totalwebcreations\b2bcommerce\Plugin;
use yii\base\InvalidArgumentException;
use yii\web\Response;

/**
 * Order lists are day-to-day work material, not team administration. Every company
 * member — regardless of role — may view, create, rename, delete lists, edit their
 * items and drop a list into the cart. So each action only calls requireCompany();
 * there is deliberately no team-admin gate here (unlike AddressesController).
 */
class OrderListsController extends BaseTeamController
{
    use ReadsStringBodyParams;
    use RequiresFeature;

    public function actionCreate(): ?Response
    {
        $this->requirePostRequest();

        if ($response = $this->requireFeature('enableQuickOrder')) {
            return $response;
        }

        $company = $this->requireCompany();
        $request = Craft::$app->getRequest();

        try {
            Plugin::getInstance()->orderLists->createList(
                $company,
                $this->stringBodyParam('name'),
                Craft::$app->getUser()->getId(),
            );
        } catch (InvalidArgumentException $exception) {
            return $this->asFailure($exception->getMessage());
        }

        return $this->asSuccess(Craft::t('b2b-commerce', 'List created.'));
    }

    public function actionRename(): ?Response
    {
        $this->requirePostRequest();

        if ($response = $this->requireFeature('enableQuickOrder')) {
            return $response;
        }

        $company = $this->requireCompany();
        $request = Craft::$app->getRequest();

        try {
            Plugin::getInstance()->orderLists->renameList(
                $company,
                (int) $request->getRequiredBodyParam('listId'),
                $this->stringBodyParam('name'),
            );
        } catch (InvalidArgumentException $exception) {
            return $this->asFailure($exception->getMessage());
        }

        return $this->asSuccess(Craft::t('b2b-commerce', 'List renamed.'));
    }

    public function actionDelete(): ?Response
    {
        $this->requirePostRequest();

        if ($response = $this->requireFeature('enableQuickOrder')) {
            return $response;
        }

        $company = $this->requireCompany();
        $request = Craft::$app->getRequest();

        try {
            Plugin::getInstance()->orderLists->deleteList(
                $company,
                (int) $request->getRequiredBodyParam('listId'),
            );
        } catch (InvalidArgumentException $exception) {
            return $this->asFailure($exception->getMessage());
        }

        return $this->asSuccess(Craft::t('b2b-commerce', 'List deleted.'));
    }

    public function actionSetItem(): ?Response
    {
        $this->requirePostRequest();

        if ($response = $this->requireFeature('enableQuickOrder')) {
            return $response;
        }

        $company = $this->requireCompany();
        $request = Craft::$app->getRequest();

        try {
            Plugin::getInstance()->orderLists->setItem(
                $company,
                (int) $request->getRequiredBodyParam('listId'),
                (int) $request->getRequiredBodyParam('purchasableId'),
                (int) $request->getBodyParam('qty', 0),
            );
        } catch (InvalidArgumentException $exception) {
            return $this->asFailure($exception->getMessage());
        }

        return $this->asSuccess(Craft::t('b2b-commerce', 'List updated.'));
    }

    public function actionAddToCart(): ?Response
    {
        $this->requirePostRequest();

        if ($response = $this->requireFeature('enableQuickOrder')) {
            return $response;
        }

        $company = $this->requireCompany();
        $request = Craft::$app->getRequest();

        if (!Plugin::getInstance()->priceVisibility->canPurchase(Craft::$app->getUser()->getIdentity())) {
            return $this->asFailure(
                Craft::t('b2b-commerce', 'You need an approved business account to order.')
            );
        }

        $cart = Commerce::getInstance()->getCarts()->getCart(true);

        try {
            $result = Plugin::getInstance()->orderLists->addListToCart(
                $cart,
                $company,
                (int) $request->getRequiredBodyParam('listId'),
            );
        } catch (InvalidArgumentException $exception) {
            return $this->asFailure($exception->getMessage());
        }

        return $this->asSuccess(
            Craft::t('b2b-commerce', '{count} items added to your cart.', ['count' => $result['added']]),
            $result,
        );
    }
}
