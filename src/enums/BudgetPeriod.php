<?php

namespace totalwebcreations\b2bcommerce\enums;

use DateTimeImmutable;
use DateTimeInterface;

/**
 * The reset cadence of a member spending budget.
 *
 * A budget is a per-member cap on how much may be spent within one period. The period determines
 * the window over which spend is counted and, therefore, when a member's spend "resets" to zero:
 *
 *   - Monthly / Quarterly / Yearly: spend is counted from the start of the current calendar period
 *     (measured in the site timezone) and resets when the next period begins.
 *   - None: the budget is NOT time-bounded. It still caps, but spend is counted all-time and never
 *     resets — a lifetime ceiling. None is distinct from "no budget at all": a member with no budget
 *     row is unlimited (see Budgets::canAfford), whereas a None-period budget is a hard lifetime cap.
 *
 * The enum is Craft-free and pure: the caller passes in a $now whose timezone already reflects where
 * the store lives, so period boundaries are computed in that timezone without this enum reaching for
 * Craft::$app. This mirrors how QuotesCpController measures a whole-day validity in the site timezone.
 */
enum BudgetPeriod: string
{
    case None = 'none';
    case Monthly = 'monthly';
    case Quarterly = 'quarterly';
    case Yearly = 'yearly';

    /**
     * The first instant of the current period for the given moment, or null for {@see self::None}
     * (an all-time, never-resetting cap). The result inherits $now's timezone, so callers must hand
     * in a $now already expressed in the timezone the period should be measured against.
     */
    public function currentPeriodStart(DateTimeInterface $now): ?DateTimeImmutable
    {
        if ($this === self::None) {
            return null;
        }

        $moment = DateTimeImmutable::createFromInterface($now);
        $year = (int) $moment->format('Y');
        $month = (int) $moment->format('n');

        return match ($this) {
            self::Monthly => $moment->setDate($year, $month, 1)->setTime(0, 0, 0),
            self::Quarterly => $moment->setDate($year, intdiv($month - 1, 3) * 3 + 1, 1)->setTime(0, 0, 0),
            self::Yearly => $moment->setDate($year, 1, 1)->setTime(0, 0, 0),
            self::None => null,
        };
    }
}
