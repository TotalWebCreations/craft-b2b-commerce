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
        $invoiceGatewayIds = $this->getInvoiceGatewayIds();

        if ($invoiceGatewayIds === []) {
            return 0.0;
        }

        // Candidate orders are narrowed in SQL (linked to this company, completed, paid via an
        // invoice gateway), but the balance itself is summed from the Order elements. The
        // commerce_orders.totalPaid column is only a snapshot written on the last order save
        // (Order::afterSave), whereas Order::getTotalPaid() derives the true figure live from
        // successful purchase/capture transactions minus refunds. An invoice gateway captures
        // funds out of band -- a later payment inserts a transaction without necessarily
        // re-saving the order -- so the column goes stale exactly for this use case. Summing the
        // getter is therefore the only correct measure of what is still owed.
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
                'orders.isCompleted' => true,
                'orders.gatewayId' => $invoiceGatewayIds,
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

            if ($balance <= 0) {
                continue;
            }

            $outstanding += $balance;
        }

        return $outstanding;
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

    /**
     * Ids of every InvoiceGateway instance, archived ones included. Resolved from Commerce's
     * Gateways service, which already caches the gateway set per request (Gateways::$_allGateways)
     * and invalidates that cache whenever a gateway is saved or archived, so no second cache is
     * kept here: a stale one would silently miscount the balance if the gateway set changed.
     *
     * Archived invoice gateways are unioned in on purpose: an order still carrying an archived
     * invoice gateway's id is a real receivable and must keep counting against the company's
     * credit -- dropping it would understate the balance and hand out phantom credit room.
     *
     * Note the limit of a gatewayId-keyed balance: Commerce's Gateways::archiveGatewayById() nulls
     * gatewayId on every existing order as it archives, so orders archived AFTER completion lose
     * this reference entirely and fall out of the sum regardless of this union. The union therefore
     * only catches orders that still reference an archived gateway (e.g. data written out of band).
     * Recovering the nulled ones would need a durable invoice marker rather than the live gatewayId,
     * which is out of scope here.
     *
     * @return array<int, int>
     */
    private function getInvoiceGatewayIds(): array
    {
        $gateways = Commerce::getInstance()->getGateways();

        $active = $gateways->getAllGateways()->all();
        $archived = $gateways->getAllArchivedGateways();

        return collect([...$active, ...$archived])
            ->filter(fn($gateway) => $gateway instanceof InvoiceGateway)
            ->map(fn($gateway) => (int) $gateway->id)
            ->unique()
            ->values()
            ->all();
    }
}
