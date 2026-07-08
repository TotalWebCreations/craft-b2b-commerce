<?php

use totalwebcreations\b2bcommerce\Plugin;
use yii\base\InvalidArgumentException;

// createTestCompany() is loaded globally by the suite (tests/Integration/helpers.php).

it('sets, reads and orders tiers by level', function () {
    $company = createTestCompany('approved', 'Tier Co');
    $tiers = Plugin::getInstance()->approvalTiers;

    $tiers->setTier($company->id, 2, 5000.0, 'approver', false);
    $tiers->setTier($company->id, 1, 0.0, 'approver', false);
    $tiers->setTier($company->id, 3, 20000.0, 'approver', true);

    $rows = $tiers->getTiers($company->id);

    expect($rows)->toHaveCount(3)
        ->and((int) $rows[0]['level'])->toBe(1)
        ->and((int) $rows[1]['level'])->toBe(2)
        ->and((int) $rows[2]['level'])->toBe(3)
        ->and((bool) $rows[2]['departmentScoped'])->toBeTrue()
        ->and($tiers->lowestMinAmount($company->id))->toBe(0.0)
        ->and($tiers->hasTiers($company->id))->toBeTrue();
});

it('upserts a tier on the same level rather than duplicating it', function () {
    $company = createTestCompany('approved', 'Tier Co');
    $tiers = Plugin::getInstance()->approvalTiers;

    $tiers->setTier($company->id, 1, 1000.0, 'approver', false);
    $tiers->setTier($company->id, 1, 2500.0, 'approver', true);

    $rows = $tiers->getTiers($company->id);

    expect($rows)->toHaveCount(1)
        ->and((float) $rows[0]['minAmount'])->toBe(2500.0)
        ->and((bool) $rows[0]['departmentScoped'])->toBeTrue();
});

it('returns only the tier levels required for a given amount, ordered', function () {
    $company = createTestCompany('approved', 'Tier Co');
    $tiers = Plugin::getInstance()->approvalTiers;

    $tiers->setTier($company->id, 1, 0.0, 'approver', false);
    $tiers->setTier($company->id, 2, 5000.0, 'approver', false);
    $tiers->setTier($company->id, 3, 20000.0, 'approver', false);

    $required = $tiers->requiredLevels($company->id, 8000.0);

    expect($required)->toHaveCount(2)
        ->and((int) $required[0]['level'])->toBe(1)
        ->and((int) $required[1]['level'])->toBe(2);

    // An amount exactly at a band's minAmount includes that band (>=).
    expect($tiers->requiredLevels($company->id, 20000.0))->toHaveCount(3);
});

it('reports no tiers, null lowest amount and empty required levels for a tier-less company', function () {
    $company = createTestCompany('approved', 'Tier Co');
    $tiers = Plugin::getInstance()->approvalTiers;

    expect($tiers->hasTiers($company->id))->toBeFalse()
        ->and($tiers->lowestMinAmount($company->id))->toBeNull()
        ->and($tiers->requiredLevels($company->id, 999999.0))->toBe([]);
});

it('deletes a tier and refuses an invalid level or negative amount', function () {
    $company = createTestCompany('approved', 'Tier Co');
    $tiers = Plugin::getInstance()->approvalTiers;

    $tiers->setTier($company->id, 1, 100.0, 'approver', false);
    $tiers->deleteTier($company->id, 1);

    expect($tiers->hasTiers($company->id))->toBeFalse();

    expect(fn () => $tiers->setTier($company->id, 0, 100.0, 'approver', false))
        ->toThrow(InvalidArgumentException::class, 'Invalid tier level.');

    expect(fn () => $tiers->setTier($company->id, 1, -5.0, 'approver', false))
        ->toThrow(InvalidArgumentException::class, 'A tier amount cannot be negative.');
});
