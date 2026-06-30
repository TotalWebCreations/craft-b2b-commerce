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

class ApprovalsController extends Controller
{
    use ReadsStringBodyParams;
    use RequiresFeature;

    public function actionSubmit(): ?Response
    {
        $this->requirePostRequest();
        $this->requireLogin();

        if ($response = $this->requireFeature('enableApprovals')) {
            return $response;
        }

        $cart = Commerce::getInstance()->getCarts()->getCart();
        $actor = Craft::$app->getUser()->getIdentity();

        try {
            Plugin::getInstance()->approvals->submitForApproval($cart, $actor);
        } catch (InvalidArgumentException $exception) {
            return $this->asFailure($exception->getMessage());
        }

        return $this->asSuccess(
            Craft::t('b2b-commerce', 'Your order has been submitted for approval.')
        );
    }

    public function actionApprove(): ?Response
    {
        $this->requirePostRequest();
        $this->requireLogin();

        if ($response = $this->requireFeature('enableApprovals')) {
            return $response;
        }

        $orderId = (int) $this->request->getBodyParam('orderId');
        $actor = Craft::$app->getUser()->getIdentity();

        try {
            Plugin::getInstance()->approvals->approve($orderId, $actor);
        } catch (InvalidArgumentException $exception) {
            return $this->asFailure($exception->getMessage());
        }

        return $this->asSuccess(
            Craft::t('b2b-commerce', 'The order has been approved.')
        );
    }

    public function actionDecline(): ?Response
    {
        $this->requirePostRequest();
        $this->requireLogin();

        if ($response = $this->requireFeature('enableApprovals')) {
            return $response;
        }

        $orderId = (int) $this->request->getBodyParam('orderId');
        $reason = $this->stringBodyParam('reason');
        $actor = Craft::$app->getUser()->getIdentity();

        try {
            Plugin::getInstance()->approvals->decline($orderId, $actor, $reason);
        } catch (InvalidArgumentException $exception) {
            return $this->asFailure($exception->getMessage());
        }

        return $this->asSuccess(
            Craft::t('b2b-commerce', 'The order has been declined.')
        );
    }

    public function actionResume(): ?Response
    {
        $this->requirePostRequest();
        $this->requireLogin();

        if ($response = $this->requireFeature('enableApprovals')) {
            return $response;
        }

        $orderId = (int) $this->request->getBodyParam('orderId');
        $actor = Craft::$app->getUser()->getIdentity();

        try {
            $order = Plugin::getInstance()->approvals->resumeCheckout($orderId, $actor);
        } catch (InvalidArgumentException $exception) {
            return $this->asFailure($exception->getMessage());
        }

        return $this->asSuccess(
            Craft::t('b2b-commerce', 'Your order is ready for checkout.'),
            ['cartNumber' => $order->number]
        );
    }
}
