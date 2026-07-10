<?php

use totalwebcreations\b2bcommerce\enums\AgingBucket;

it('places a not-yet-due invoice in the current bucket', function () {
    expect(AgingBucket::forDaysPastDue(0))->toBe(AgingBucket::Current)
        ->and(AgingBucket::forDaysPastDue(-5))->toBe(AgingBucket::Current);
});

it('places 1 to 30 days past due in the 1-30 bucket', function () {
    expect(AgingBucket::forDaysPastDue(1))->toBe(AgingBucket::Days1To30)
        ->and(AgingBucket::forDaysPastDue(30))->toBe(AgingBucket::Days1To30);
});

it('places 31 to 60 days past due in the 31-60 bucket', function () {
    expect(AgingBucket::forDaysPastDue(31))->toBe(AgingBucket::Days31To60)
        ->and(AgingBucket::forDaysPastDue(60))->toBe(AgingBucket::Days31To60);
});

it('places 61 to 90 days past due in the 61-90 bucket', function () {
    expect(AgingBucket::forDaysPastDue(61))->toBe(AgingBucket::Days61To90)
        ->and(AgingBucket::forDaysPastDue(90))->toBe(AgingBucket::Days61To90);
});

it('places more than 90 days past due in the 90+ bucket', function () {
    expect(AgingBucket::forDaysPastDue(91))->toBe(AgingBucket::Days90Plus)
        ->and(AgingBucket::forDaysPastDue(365))->toBe(AgingBucket::Days90Plus);
});

it('counts whole days past due at day granularity ignoring the time of day', function () {
    $due = new DateTimeImmutable('2026-01-10 09:00:00');
    $asOf = new DateTimeImmutable('2026-01-20 23:30:00');

    expect(AgingBucket::daysPastDue($due, $asOf))->toBe(10);
});

it('treats a due-today invoice as not past due', function () {
    $due = new DateTimeImmutable('2026-01-20 08:00:00');
    $asOf = new DateTimeImmutable('2026-01-20 20:00:00');

    expect(AgingBucket::daysPastDue($due, $asOf))->toBe(0);
});

it('treats a null due date as not past due', function () {
    expect(AgingBucket::daysPastDue(null, new DateTimeImmutable('2026-01-20')))->toBe(0);
});
