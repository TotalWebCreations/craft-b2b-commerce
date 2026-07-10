<?php

namespace totalwebcreations\b2bcommerce\modules\invoicing\services;

use craft\commerce\db\Table;
use craft\commerce\elements\Order;
use craft\commerce\Plugin as Commerce;
use craft\db\Query;
use totalwebcreations\b2bcommerce\gateways\InvoiceGateway;
use totalwebcreations\b2bcommerce\helpers\Money;
use totalwebcreations\b2bcommerce\helpers\SettledOrderStatuses;
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
     * The company's completed invoice orders that still carry a positive outstanding balance,
     * as Order elements. This is the single source of truth for "what a company still owes",
     * consumed by the credit-balance sum, the account statement and dunning.
     *
     * Candidate orders are narrowed in SQL (linked to this company, completed, on account via the
     * b2b_order_company.isInvoice snapshot — never the live gatewayId, which archiving a gateway
     * nulls). The balance itself is read from each Order element: commerce_orders.totalPaid is a
     * stale snapshot for out-of-band invoice captures, whereas Order::getOutstandingBalance()
     * derives the true figure live. Cancelled/refunded orders are settled — their receivable is
     * gone — and are dropped via the shared SettledOrderStatuses scope. A paid or overpaid order
     * (balance <= 0) creates no debt and is dropped here so it can never offset another unpaid
     * order.
     *
     * @return array<int, Order>
     */
    public function getOutstandingOrders(int $companyId): array
    {
        $query = (new Query())
            ->select('orders.id')
            // Defensive DISTINCT: the join is one-to-one on orderId today, but a duplicate id would
            // double-count an order's balance -- guard against it rather than trust the join.
            ->distinct()
            ->from(['orders' => Table::ORDERS])
            ->innerJoin(['oc' => '{{%b2b_order_company}}'], '[[oc.orderId]] = [[orders.id]]')
            ->where([
                'oc.companyId' => $companyId,
                'oc.isInvoice' => true,
                'orders.isCompleted' => true,
            ]);

        SettledOrderStatuses::excludeFrom($query, 'orders');

        $orderIds = $query->column();

        $orders = Commerce::getInstance()->getOrders();
        $outstanding = [];

        foreach ($orderIds as $orderId) {
            $order = $orders->getOrderById((int) $orderId);

            if ($order === null) {
                continue;
            }

            // An overpaid order has a negative outstanding balance; a paid order has zero. Neither
            // is debt, so skip both.
            if ($order->getOutstandingBalance() <= 0) {
                continue;
            }

            $outstanding[] = $order;
        }

        return $outstanding;
    }

    /**
     * The company's total unpaid balance across its completed invoice-gateway orders.
     */
    public function getOutstandingBalance(int $companyId): float
    {
        $outstanding = 0.0;

        foreach ($this->getOutstandingOrders($companyId) as $order) {
            $outstanding += $order->getOutstandingBalance();
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

        // The fixed-scale decimal comparison (shared with the spending-budget gate) keeps a charge
        // that lands exactly on the limit allowed while catching real overruns — see Money::withinLimit.
        return Money::withinLimit($this->getOutstandingBalance($companyId) + $amount, $company->creditLimit);
    }
}
