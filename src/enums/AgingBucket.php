<?php

namespace totalwebcreations\b2bcommerce\enums;

use Craft;
use DateTimeImmutable;
use DateTimeInterface;

/**
 * Aging buckets for an account statement. The bucket boundaries and the days-past-due
 * calculation are the money-critical core of the statement, kept Craft-free so they can be
 * unit-tested in isolation. `label()` is the only Craft-dependent member and is never called
 * from the pure paths.
 */
enum AgingBucket: string
{
    case Current = 'current';
    case Days1To30 = '1-30';
    case Days31To60 = '31-60';
    case Days61To90 = '61-90';
    case Days90Plus = '90+';

    public static function forDaysPastDue(int $daysPastDue): self
    {
        if ($daysPastDue <= 0) {
            return self::Current;
        }

        if ($daysPastDue <= 30) {
            return self::Days1To30;
        }

        if ($daysPastDue <= 60) {
            return self::Days31To60;
        }

        if ($daysPastDue <= 90) {
            return self::Days61To90;
        }

        return self::Days90Plus;
    }

    /**
     * Whole days the invoice is past due as of $asOf, compared at day granularity so the time of
     * day never nudges an order into the next bucket. Zero when there is no due date or the invoice
     * is not yet due, which lands it in the Current bucket. Both dates are compared in their own
     * timezone; callers pass same-timezone dates (b2bPaymentDueDate and now are both system time).
     */
    public static function daysPastDue(?DateTimeInterface $dueDate, DateTimeInterface $asOf): int
    {
        if ($dueDate === null) {
            return 0;
        }

        $due = DateTimeImmutable::createFromInterface($dueDate)->setTime(0, 0, 0);
        $now = DateTimeImmutable::createFromInterface($asOf)->setTime(0, 0, 0);

        if ($now <= $due) {
            return 0;
        }

        return (int) $due->diff($now)->days;
    }

    public function label(): string
    {
        return match ($this) {
            self::Current => Craft::t('b2b-commerce', 'Current'),
            self::Days1To30 => Craft::t('b2b-commerce', '1–30 days'),
            self::Days31To60 => Craft::t('b2b-commerce', '31–60 days'),
            self::Days61To90 => Craft::t('b2b-commerce', '61–90 days'),
            self::Days90Plus => Craft::t('b2b-commerce', '90+ days'),
        };
    }
}
