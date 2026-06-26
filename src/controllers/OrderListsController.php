<?php

namespace totalwebcreations\b2bcommerce\controllers;

use Craft;
use craft\commerce\Plugin as Commerce;
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
    public function actionCreate(): ?Response
    {
        $this->requirePostRequest();
        $company = $this->requireCompany();
        $request = Craft::$app->getRequest();

        try {
            Plugin::getInstance()->orderLists->createList(
                $company,
                (string) $request->getBodyParam('name', ''),
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
        $company = $this->requireCompany();
        $request = Craft::$app->getRequest();

        try {
            Plugin::getInstance()->orderLists->renameList(
                $company,
                (int) $request->getRequiredBodyParam('listId'),
                (string) $request->getBodyParam('name', ''),
            );
        } catch (InvalidArgumentException $exception) {
            return $this->asFailure($exception->getMessage());
        }

        return $this->asSuccess(Craft::t('b2b-commerce', 'List renamed.'));
    }

    public function actionDelete(): ?Response
    {
        $this->requirePostRequest();
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
        $company = $this->requireCompany();
        $request = Craft::$app->getRequest();

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
