<?php

namespace totalwebcreations\b2bcommerce\modules\invoicing\services;

use craft\commerce\db\Table;
use craft\commerce\Plugin as Commerce;
use craft\db\Query;
use totalwebcreations\b2bcommerce\gateways\InvoiceGateway;
use totalwebcreations\b2bcommerce\Plugin;
use yii\base\Component;

/**
 * Tracks how much a company still owes on its pay-on-account (invoice) orders and
 * whether a new charge would fit inside its credit limit. This is the enforcement
 * behind {@see InvoiceGateway::availableForUseWithOrder()}.
 */
class CreditBalance extends Component
{
    /**
     * The company's total unpaid balance across its completed invoice-gateway orders.
     */
    public function getOutstandingBalance(int $companyId): float
    {
        // Candidate orders are narrowed in SQL (linked to this company, completed, placed on
        // account), but the balance itself is summed from the Order elements. The
        // commerce_orders.totalPaid column is only a snapshot written on the last order save
        // (Order::afterSave), whereas Order::getTotalPaid() derives the true figure live from
        // successful purchase/capture transactions minus refunds. An invoice gateway captures
        // funds out of band -- a later payment inserts a transaction without necessarily
        // re-saving the order -- so the column goes stale exactly for this use case. Summing the
        // getter is therefore the only correct measure of what is still owed.
        //
        // Invoice orders are recognised by the b2b_order_company.isInvoice snapshot, written once
        // at completion, rather than by the live orders.gatewayId. Archiving a gateway nulls
        // gatewayId on every order that used it, so a gatewayId filter would silently drop archived
        // invoice orders and hand out phantom credit room; the snapshot is immune to that.
        $orderIds = (new Query())
            ->select('orders.id')
            // A defensive DISTINCT: the inner join is one-to-one on orderId (b2b_order_company
            // keys on orderId), so duplicates cannot occur today, but a duplicated candidate id
            // would double-count an order's balance -- guard against it rather than trust the join.
            ->distinct()
            ->from(['orders' => Table::ORDERS])
            ->innerJoin(['oc' => '{{%b2b_order_company}}'], '[[oc.orderId]] = [[orders.id]]')
            ->where([
                'oc.companyId' => $companyId,
                'oc.isInvoice' => true,
                'orders.isCompleted' => true,
            ])
            ->column();

        $orders = Commerce::getInstance()->getOrders();
        $outstanding = 0.0;

        foreach ($orderIds as $orderId) {
            $order = $orders->getOrderById((int) $orderId);

            if ($order === null) {
                continue;
            }

            $balance = $order->getOutstandingBalance();

            // An overpaid order has a negative outstanding balance; it does not create credit for
            // the company, so skip it rather than let it offset other unpaid orders.
            if ($balance <= 0) {
                continue;
            }

            $outstanding += $balance;
        }

        return $outstanding;
    }

    /**
     * The company's credit position: what it still owes, its configured limit, and the room left
     * under that limit. `available` is clamped at zero -- an over-limit balance shows no negative
     * room -- and is null when the company has no credit limit set. Centralises the clamp rule
     * shared by the control-panel orders screen and the craft.b2b.creditSummary variable.
     *
     * @return array{
     *     outstanding: float,
     *     creditLimit: ?float,
     *     available: ?float
     * }
     */
    public function getSummary(int $companyId): array
    {
        $company = Plugin::getInstance()->companyMembers->getCompanyById($companyId);
        $creditLimit = $company?->creditLimit;
        $outstanding = $this->getOutstandingBalance($companyId);
        $available = $creditLimit === null ? null : max(0.0, $creditLimit - $outstanding);

        return [
            'outstanding' => $outstanding,
            'creditLimit' => $creditLimit,
            'available' => $available,
        ];
    }

    /**
     * Whether the company can take on an additional charge without exceeding its credit limit.
     * A company without a credit limit is never allowed to pay on account.
     */
    public function canCover(int $companyId, float $amount): bool
    {
        $company = Plugin::getInstance()->companyMembers->getCompanyById($companyId);

        if ($company === null || $company->creditLimit === null) {
            return false;
        }

        // Money totals are summed as floats, so a charge that lands exactly on the limit can miss
        // by a rounding hair (e.g. 49.999999 vs 50). The epsilon keeps "exactly at the limit"
        // decisively allowed rather than tipping into a false refusal.
        return $this->getOutstandingBalance($companyId) + $amount <= $company->creditLimit + 0.001;
    }
}
