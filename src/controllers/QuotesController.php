<?php

namespace totalwebcreations\b2bcommerce\controllers;

use Craft;
use craft\commerce\Plugin as Commerce;
use craft\web\Controller;
use totalwebcreations\b2bcommerce\controllers\concerns\ReadsStringBodyParams;
use totalwebcreations\b2bcommerce\controllers\concerns\RequiresFeature;
use totalwebcreations\b2bcommerce\Plugin;
use yii\base\InvalidArgumentException;
use yii\web\Response;

class QuotesController extends Controller
{
    use ReadsStringBodyParams;
    use RequiresFeature;

    public function actionRequest(): ?Response
    {
        $this->requirePostRequest();
        $this->requireLogin();

        if ($response = $this->requireFeature('enableQuotes')) {
            return $response;
        }

        $cart = Commerce::getInstance()->getCarts()->getCart(true);
        $actor = Craft::$app->getUser()->getIdentity();
        $notes = $this->stringBodyParam('notes');

        try {
            Plugin::getInstance()->quotes->requestQuote($cart, $actor, $notes !== '' ? $notes : null);
        } catch (InvalidArgumentException $exception) {
            return $this->asFailure($exception->getMessage());
        }

        return $this->asSuccess(
            Craft::t('b2b-commerce', 'Your quote request has been submitted.')
        );
    }
}
