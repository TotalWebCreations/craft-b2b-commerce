<?php

namespace totalwebcreations\b2bcommerce\modules\companies\services;

use Craft;
use craft\commerce\elements\Order;
use craft\db\Query;
use craft\helpers\Db;
use totalwebcreations\b2bcommerce\elements\Company;
use totalwebcreations\b2bcommerce\Plugin;
use yii\base\Component;
use yii\base\Exception;

class OrderCompanyLink extends Component
{
    public function getCompanyForOrder(int $orderId): ?Company
    {
        $companyId = (new Query())
            ->select('companyId')
            ->from('{{%b2b_order_company}}')
            ->where(['orderId' => $orderId])
            ->scalar();

        if (!$companyId) {
            return null;
        }

        return Plugin::getInstance()->companyMembers->getCompanyById((int) $companyId);
    }

    public function enforcePurchasePolicy(Order $order): void
    {
        $request = Craft::$app->getRequest();

        // Storefront-only guard: never intervene in console or control-panel completions.
        if ($request->getIsConsoleRequest() || $request->getIsCpRequest()) {
            return;
        }

        if (!Plugin::getInstance()->getSettings()->hidePricesForGuests) {
            return;
        }

        // Never refuse an order that is already (partially) paid. Payment gateways complete paid
        // orders out-of-band via a webhook that calls markAsComplete(); throwing here would make the
        // completion fail and the gateway retry the webhook forever against an order it already
        // captured. getTotalPaid() is Commerce's own paid measure: the sum of successful purchase and
        // capture transactions minus refunds (Commerce's Order::getTotalPaid).
        if ($order->getTotalPaid() > 0) {
            return;
        }

        if (Plugin::getInstance()->priceVisibility->canPurchase($order->getCustomer())) {
            return;
        }

        $message = Craft::t('b2b-commerce', 'This order cannot be completed with the current account status.');

        // The error MUST be set on an order attribute (customerId) before throwing: Commerce's
        // CartController catches the exception and _returnCart() only skips re-saving the
        // half-completed order because its $cart->validate($attributes, false) call (clearErrors
        // false, vendor CartController.php ~line 652/588) fails on this persisted error and
        // short-circuits the save. Without an attribute error, the completed order would persist.
        $order->addError('customerId', $message);

        // Order::EVENT_BEFORE_COMPLETE_ORDER is not cancelable (markAsComplete() ignores the event
        // result and saves without validation), so aborting completion requires throwing. The
        // checkout controller catches this and returns the cart carrying the order error above.
        throw new Exception($message);
    }

    public function linkCompany(Order $order): void
    {
        $customer = $order->getCustomer();

        if ($customer === null) {
            return;
        }

        $company = Plugin::getInstance()->companyMembers->getCompanyForUser($customer->id);

        if ($company === null) {
            return;
        }

        Db::upsert('{{%b2b_order_company}}', [
            'orderId' => $order->id,
            'companyId' => $company->id,
        ]);
    }
}
