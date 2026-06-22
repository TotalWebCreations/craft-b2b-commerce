<?php

namespace totalwebcreations\b2bcommerce\modules\companies\services;

use Craft;
use craft\commerce\elements\Order;
use craft\db\Query;
use craft\helpers\Db;
use totalwebcreations\b2bcommerce\elements\Company;
use totalwebcreations\b2bcommerce\Plugin;
use yii\base\Component;
use yii\base\Event;
use yii\base\Exception;

class OrderCompanyLink extends Component
{
    public function handleBeforeCompleteOrder(Event $event): void
    {
        $order = $event->sender;

        if (!$order instanceof Order) {
            return;
        }

        $this->enforcePurchaseBackstop($order);
        $this->linkCompany($order);
    }

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

    private function enforcePurchaseBackstop(Order $order): void
    {
        $request = Craft::$app->getRequest();

        // Storefront-only guard: never intervene in console or control-panel completions.
        if ($request->getIsConsoleRequest() || $request->getIsCpRequest()) {
            return;
        }

        if (!Plugin::getInstance()->getSettings()->hidePricesForGuests) {
            return;
        }

        if (Plugin::getInstance()->priceVisibility->canPurchase($order->getCustomer())) {
            return;
        }

        $message = Craft::t('b2b-commerce', 'This order cannot be completed with the current account status.');

        $order->addError('customerId', $message);

        // Order::EVENT_BEFORE_COMPLETE_ORDER is not cancelable (markAsComplete() ignores the event
        // result and saves without validation), so aborting completion requires throwing. The
        // checkout controller catches this and returns the cart carrying the order error above.
        throw new Exception($message);
    }

    private function linkCompany(Order $order): void
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
