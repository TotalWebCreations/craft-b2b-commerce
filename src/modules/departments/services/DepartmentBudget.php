<?php

namespace totalwebcreations\b2bcommerce\modules\departments\services;

use Craft;
use craft\commerce\db\Table;
use craft\commerce\Plugin as Commerce;
use craft\db\Query;
use craft\helpers\Db;
use DateTimeImmutable;
use DateTimeInterface;
use DateTimeZone;
use totalwebcreations\b2bcommerce\enums\BudgetPeriod;
use totalwebcreations\b2bcommerce\helpers\Money;
use totalwebcreations\b2bcommerce\helpers\SettledOrderStatuses;
use totalwebcreations\b2bcommerce\Plugin;
use yii\base\Component;

/**
 * Aggregate department spending budgets: a cap on the combined spend of a department's CURRENT
 * members within the department's period. This layers on top of the per-member budget (Budgets) and
 * the company credit limit — all three are independent and must each pass.
 *
 * The spend sum mirrors {@see \totalwebcreations\b2bcommerce\modules\budgets\services\Budgets::getSpent}
 * but differs in two deliberate ways: it spans the SET of the department's members (not one member),
 * and it measures the DEPARTMENT'S own period (not a member's). Candidates are narrowed in SQL (the
 * members' completed orders for the company, in the department period, minus settled statuses) and
 * summed from the live Order elements' getTotalPrice(), so the figure tracks the elements rather than
 * a snapshot. Settled-status exclusion and the fixed-scale comparison are shared with the per-member
 * and credit gates (SettledOrderStatuses, Money) so every gate agrees on what counts.
 *
 * @phpstan-type DepartmentRow array{
 *     id: int|string,
 *     companyId: int|string,
 *     budgetAmount: string|null,
 *     budgetPeriod: string
 * }
 */
class DepartmentBudget extends Component
{
    /**
     * The department's combined member spend in its current period. Reads the member set live from
     * getMemberIds, so a mid-period reassignment shifts an order's spend to the member's present
     * department. Returns 0.0 for a department with no members.
     *
     * @param array<string, mixed> $department
     */
    public function getSpent(array $department, DateTimeInterface $now): float
    {
        $memberIds = Plugin::getInstance()->departments->getMemberIds((int) $department['id']);

        if ($memberIds === []) {
            return 0.0;
        }

        $period = BudgetPeriod::from((string) $department['budgetPeriod']);
        $periodStart = $period->currentPeriodStart($this->toSiteTime($now));

        $query = (new Query())
            ->select('orders.id')
            ->distinct()
            ->from(['orders' => Table::ORDERS])
            ->innerJoin(['oc' => '{{%b2b_order_company}}'], '[[oc.orderId]] = [[orders.id]]')
            ->where([
                'oc.companyId' => (int) $department['companyId'],
                'orders.customerId' => $memberIds,
                'orders.isCompleted' => true,
            ]);

        if ($periodStart !== null) {
            $query->andWhere(['>=', 'orders.dateOrdered', Db::prepareDateForDb($periodStart)]);
        }

        SettledOrderStatuses::excludeFrom($query, 'orders');

        $orders = Commerce::getInstance()->getOrders();
        $spent = 0.0;

        foreach ($query->column() as $orderId) {
            $order = $orders->getOrderById((int) $orderId);

            if ($order === null) {
                continue;
            }

            $spent += $order->getTotalPrice();
        }

        return $spent;
    }

    /**
     * Whether a further charge of $amount keeps the department within its budget. A null budgetAmount
     * is unlimited (always true); otherwise projected spend is compared as fixed-scale decimals so a
     * charge landing exactly on the budget is allowed.
     *
     * @param array<string, mixed> $department
     */
    public function canAfford(array $department, float $amount, DateTimeInterface $now): bool
    {
        if ($department['budgetAmount'] === null) {
            return true;
        }

        return Money::withinLimit(
            $this->getSpent($department, $now) + $amount,
            (float) $department['budgetAmount']
        );
    }

    private function toSiteTime(DateTimeInterface $now): DateTimeImmutable
    {
        return DateTimeImmutable::createFromInterface($now)
            ->setTimezone(new DateTimeZone(Craft::$app->getTimeZone()));
    }
}
