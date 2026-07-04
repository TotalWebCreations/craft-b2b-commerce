<?php

namespace totalwebcreations\b2bcommerce\modules\budgets\services;

use Craft;
use craft\commerce\db\Table;
use craft\commerce\Plugin as Commerce;
use craft\db\Query;
use craft\helpers\Db;
use DateTimeImmutable;
use DateTimeInterface;
use DateTimeZone;
use totalwebcreations\b2bcommerce\elements\Company;
use totalwebcreations\b2bcommerce\enums\BudgetPeriod;
use totalwebcreations\b2bcommerce\helpers\Money;
use totalwebcreations\b2bcommerce\helpers\SettledOrderStatuses;
use totalwebcreations\b2bcommerce\Plugin;
use yii\base\Component;
use yii\base\InvalidArgumentException;

/**
 * Per-member spending budgets: a cap on how much a single member may spend for a company within a
 * period. This layers on top of the company credit limit (a company-wide cap on money owed) and the
 * approval gate (permission to order at all) — a member can be under budget while the company is over
 * credit, and vice versa, so both must pass independently.
 *
 * A budget is a single b2b_member_budgets row (one per member per company). ABSENCE of a row means
 * unlimited; a row with the {@see BudgetPeriod::None} period is a lifetime cap that never resets.
 *
 * The spend sum mirrors {@see \totalwebcreations\b2bcommerce\modules\invoicing\services\CreditBalance}:
 * candidates are narrowed in SQL (this member's completed orders for this company, in the current
 * period, minus settled statuses) and the amount is summed from the Order elements' getTotalPrice(),
 * so the figure tracks the live element rather than a snapshot column. Settled-status exclusion and
 * the fixed-scale comparison are shared with the credit gate (SettledOrderStatuses, Money) so both
 * gates agree on what counts and how "exactly at the limit" is judged.
 */
class Budgets extends Component
{
    /**
     * The raw budget row for a member, or null when none is set (unlimited).
     *
     * @return array<string, mixed>|null
     */
    public function getBudget(int $companyId, int $userId): ?array
    {
        return (new Query())
            ->from('{{%b2b_member_budgets}}')
            ->where(['companyId' => $companyId, 'userId' => $userId])
            ->one() ?: null;
    }

    /**
     * Sets (or replaces) a member's budget. Guards that the user is actually a member of the company,
     * so a budget can never be pinned to someone outside it.
     */
    public function setBudget(Company $company, int $userId, float $amount, BudgetPeriod $period): void
    {
        if (Plugin::getInstance()->companyMembers->getRoleForUser($userId, $company->id) === null) {
            throw new InvalidArgumentException(
                Craft::t('b2b-commerce', 'This user is not a member of this company.')
            );
        }

        Db::upsert('{{%b2b_member_budgets}}', [
            'companyId' => $company->id,
            'userId' => $userId,
            'amount' => $amount,
            'period' => $period->value,
        ]);
    }

    public function removeBudget(Company $company, int $userId): void
    {
        Db::delete('{{%b2b_member_budgets}}', [
            'companyId' => $company->id,
            'userId' => $userId,
        ]);
    }

    /**
     * How much the member has spent for this company in the current budget period — the sum of
     * totalPrice over their completed orders whose dateOrdered falls in the period, minus settled
     * statuses. The period comes from the member's own budget (all-time when there is no budget or its
     * period is {@see BudgetPeriod::None}). $now is normalised to the site timezone so period
     * boundaries are measured where the store lives.
     */
    public function getSpent(int $companyId, int $userId, DateTimeInterface $now): float
    {
        $budget = $this->getBudget($companyId, $userId);
        $period = $budget !== null ? BudgetPeriod::from((string) $budget['period']) : BudgetPeriod::None;
        $periodStart = $period->currentPeriodStart($this->toSiteTime($now));

        $query = (new Query())
            ->select('orders.id')
            // A defensive DISTINCT: b2b_order_company keys on orderId, so the inner join is one-to-one
            // and duplicates cannot occur today — but a duplicated candidate id would double-count an
            // order's price, so guard against it rather than trust the join.
            ->distinct()
            ->from(['orders' => Table::ORDERS])
            ->innerJoin(['oc' => '{{%b2b_order_company}}'], '[[oc.orderId]] = [[orders.id]]')
            ->where([
                'oc.companyId' => $companyId,
                'orders.customerId' => $userId,
                'orders.isCompleted' => true,
            ]);

        // A null period start means "all time" (None, or no budget): count every completed order.
        if ($periodStart !== null) {
            $query->andWhere(['>=', 'orders.dateOrdered', Db::prepareDateForDb($periodStart)]);
        }

        SettledOrderStatuses::excludeFrom($query, 'orders');

        $orderIds = $query->column();

        $orders = Commerce::getInstance()->getOrders();
        $spent = 0.0;

        foreach ($orderIds as $orderId) {
            $order = $orders->getOrderById((int) $orderId);

            if ($order === null) {
                continue;
            }

            $spent += $order->getTotalPrice();
        }

        return $spent;
    }

    /**
     * Whether a further charge of $amount keeps the member within their budget. A member with no
     * budget row is unlimited (always true); otherwise the projected spend (current spend + amount) is
     * compared against the budget as fixed-scale decimals, so a charge landing exactly on the budget
     * is allowed. Shares the comparison with the credit gate via {@see Money::withinLimit()}.
     */
    public function canAfford(int $companyId, int $userId, float $amount, DateTimeInterface $now): bool
    {
        $budget = $this->getBudget($companyId, $userId);

        if ($budget === null) {
            return true;
        }

        return Money::withinLimit(
            $this->getSpent($companyId, $userId, $now) + $amount,
            (float) $budget['amount']
        );
    }

    /**
     * Re-expresses $now in the site timezone so period boundaries are measured where the store lives,
     * mirroring QuotesCpController's whole-day handling. Keeping the timezone concern here leaves
     * {@see BudgetPeriod} pure and Craft-free.
     */
    private function toSiteTime(DateTimeInterface $now): DateTimeImmutable
    {
        return DateTimeImmutable::createFromInterface($now)
            ->setTimezone(new DateTimeZone(Craft::$app->getTimeZone()));
    }
}
