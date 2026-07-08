<?php

namespace totalwebcreations\b2bcommerce\modules\checkout\services;

use Craft;
use craft\commerce\elements\Order;
use craft\db\Query;
use craft\helpers\Db;
use totalwebcreations\b2bcommerce\Plugin;
use yii\base\Component;
use yii\base\Exception;

/**
 * Stores and enforces the buyer-entered purchase-order (PO) number for an order. The PO lives in
 * b2b_order_references keyed by orderId — Commerce's own `reference` is auto-generated and is not a
 * buyer PO. Because a cart is an order with an id, the row keys immediately during checkout.
 */
class OrderReferences extends Component
{
    public function getPoNumber(int $orderId): ?string
    {
        $poNumber = (new Query())
            ->select('poNumber')
            ->from('{{%b2b_order_references}}')
            ->where(['orderId' => $orderId])
            ->scalar();

        if ($poNumber === false || $poNumber === null || $poNumber === '') {
            return null;
        }

        return (string) $poNumber;
    }

    public function setPoNumber(Order $order, ?string $poNumber): void
    {
        if ($order->id === null) {
            return;
        }

        $poNumber = $poNumber !== null ? trim($poNumber) : null;

        Db::upsert('{{%b2b_order_references}}', [
            'orderId' => $order->id,
            'poNumber' => $poNumber !== '' ? $poNumber : null,
        ]);
    }

    /**
     * Completion backstop: refuse an order whose linked company requires a PO number when none is
     * present. Storefront-only (console and CP completions are the merchant override). The error is
     * set on the customerId attribute and thrown for the exact same reason as enforcePurchasePolicy:
     * EVENT_BEFORE_COMPLETE_ORDER is not cancelable, and Commerce's _returnCart short-circuit relies
     * on a persisted attribute error to avoid saving the half-completed order.
     */
    public function enforceRequiredPoNumber(Order $order): void
    {
        $request = Craft::$app->getRequest();

        if ($request->getIsConsoleRequest() || $request->getIsCpRequest()) {
            return;
        }

        if ($order->id === null) {
            return;
        }

        $customer = $order->getCustomer();

        if ($customer === null) {
            return;
        }

        $company = Plugin::getInstance()->companyMembers->getCompanyForUser($customer->id);

        if ($company === null || !$company->requirePoNumber) {
            return;
        }

        if ($this->getPoNumber($order->id) !== null) {
            return;
        }

        $message = Craft::t('b2b-commerce', 'A purchase order number is required for this order.');

        $order->addError('customerId', $message);

        throw new Exception($message);
    }
}
