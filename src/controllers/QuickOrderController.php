<?php

namespace totalwebcreations\b2bcommerce\controllers;

use Craft;
use craft\commerce\Plugin as Commerce;
use craft\web\Controller;
use totalwebcreations\b2bcommerce\Plugin;
use yii\web\Response;

class QuickOrderController extends Controller
{
    protected array|bool|int $allowAnonymous = ['add' => self::ALLOW_ANONYMOUS_LIVE];

    public function actionAdd(): ?Response
    {
        $this->requirePostRequest();

        $canPurchase = Plugin::getInstance()->priceVisibility->canPurchase(
            Craft::$app->getUser()->getIdentity()
        );

        if (!$canPurchase) {
            return $this->asFailure(
                Craft::t('b2b-commerce', 'You need an approved business account to order.')
            );
        }

        $input = (string) Craft::$app->getRequest()->getBodyParam('lines', '');

        $cart = Commerce::getInstance()->getCarts()->getCart(true);

        $result = Plugin::getInstance()->quickOrder->addToCart($cart, $input);

        return $this->asSuccess(
            Craft::t('b2b-commerce', '{count} items added to your cart.', ['count' => $result['added']]),
            $result,
        );
    }
}
