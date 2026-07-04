<?php

use totalwebcreations\b2bcommerce\enums\BudgetPeriod;

/**
 * Builds a moment in a fixed timezone so period boundaries are asserted deterministically. The enum
 * measures boundaries in the timezone the passed-in $now carries (the service hands it a site-tz
 * moment), so the tests pin one here.
 */
function budgetMoment(string $datetime, string $tz = 'Europe/Amsterdam'): DateTimeImmutable
{
    return new DateTimeImmutable($datetime, new DateTimeZone($tz));
}

it('maps every case to its lowercase string value', function () {
    expect(BudgetPeriod::None->value)->toBe('none')
        ->and(BudgetPeriod::Monthly->value)->toBe('monthly')
        ->and(BudgetPeriod::Quarterly->value)->toBe('quarterly')
        ->and(BudgetPeriod::Yearly->value)->toBe('yearly');
});

it('has no period boundary for None (an all-time cap)', function () {
    expect(BudgetPeriod::None->currentPeriodStart(budgetMoment('2026-07-09 14:30:00')))->toBeNull();
});

it('starts the monthly period on the first of the current month at midnight', function () {
    $start = BudgetPeriod::Monthly->currentPeriodStart(budgetMoment('2026-07-09 14:30:45'));

    expect($start->format('Y-m-d H:i:s'))->toBe('2026-07-01 00:00:00');
});

it('starts the monthly period correctly on the first of the month', function () {
    $start = BudgetPeriod::Monthly->currentPeriodStart(budgetMoment('2026-07-01 00:00:00'));

    expect($start->format('Y-m-d H:i:s'))->toBe('2026-07-01 00:00:00');
});

it('starts the yearly period on January 1st at midnight', function () {
    $start = BudgetPeriod::Yearly->currentPeriodStart(budgetMoment('2026-07-09 14:30:45'));

    expect($start->format('Y-m-d H:i:s'))->toBe('2026-01-01 00:00:00');
});

it('starts the quarterly period on the first day of each quarter', function () {
    $cases = [
        '2026-01-15' => '2026-01-01', // Q1
        '2026-02-28' => '2026-01-01', // Q1
        '2026-03-31' => '2026-01-01', // Q1
        '2026-04-01' => '2026-04-01', // Q2
        '2026-05-20' => '2026-04-01', // Q2
        '2026-06-30' => '2026-04-01', // Q2
        '2026-07-09' => '2026-07-01', // Q3
        '2026-09-30' => '2026-07-01', // Q3
        '2026-10-01' => '2026-10-01', // Q4
        '2026-12-31' => '2026-10-01', // Q4
    ];

    foreach ($cases as $now => $expected) {
        $start = BudgetPeriod::Quarterly->currentPeriodStart(budgetMoment($now . ' 12:00:00'));

        expect($start->format('Y-m-d H:i:s'))->toBe($expected . ' 00:00:00');
    }
});

it('keeps the timezone of the passed-in moment', function () {
    $start = BudgetPeriod::Monthly->currentPeriodStart(budgetMoment('2026-07-09 14:30:00', 'America/New_York'));

    expect($start->getTimezone()->getName())->toBe('America/New_York')
        ->and($start->format('Y-m-d H:i:s'))->toBe('2026-07-01 00:00:00');
});
