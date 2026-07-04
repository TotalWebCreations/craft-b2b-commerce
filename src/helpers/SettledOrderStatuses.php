<?php

namespace totalwebcreations\b2bcommerce\helpers;

use craft\commerce\db\Table;
use craft\db\Query;
use totalwebcreations\b2bcommerce\Plugin;

/**
 * The set of Commerce order statuses whose orders are treated as settled, and the query scope that
 * drops them. Shared by the credit-balance and spending-budget sums so both agree on what counts.
 *
 * A cancelled or refunded order is settled: its receivable is gone and it no longer represents money
 * a company owes or a member has spent, so leaving it in either sum would be phantom debt/spend. The
 * handles are configurable via the excludedOrderStatusHandles setting.
 */
final class SettledOrderStatuses
{
    /** @return string[] */
    public static function handles(): array
    {
        return Plugin::getInstance()->getSettings()->excludedOrderStatusHandles;
    }

    /**
     * Excludes settled orders from the query. orderStatusId -> commerce_orderstatuses.id carries the
     * handle; a left join keeps orders that have no status set at all (a null handle), which must
     * still count. A no-op when no handles are configured. $orderAlias is the alias the query already
     * uses for the commerce_orders table.
     */
    public static function excludeFrom(Query $query, string $orderAlias): void
    {
        $handles = self::handles();

        if ($handles === []) {
            return;
        }

        $query
            ->leftJoin(['b2bSettledOs' => Table::ORDERSTATUSES], "[[b2bSettledOs.id]] = [[{$orderAlias}.orderStatusId]]")
            ->andWhere([
                'or',
                ['b2bSettledOs.handle' => null],
                ['not', ['b2bSettledOs.handle' => $handles]],
            ]);
    }
}
