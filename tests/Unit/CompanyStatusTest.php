<?php

use totalwebcreations\b2bcommerce\enums\CompanyStatus;

it('allows pending to be approved or blocked', function () {
    expect(CompanyStatus::Pending->canTransitionTo(CompanyStatus::Approved))->toBeTrue()
        ->and(CompanyStatus::Pending->canTransitionTo(CompanyStatus::Blocked))->toBeTrue();
});

it('allows approved to be blocked but not back to pending', function () {
    expect(CompanyStatus::Approved->canTransitionTo(CompanyStatus::Blocked))->toBeTrue()
        ->and(CompanyStatus::Approved->canTransitionTo(CompanyStatus::Pending))->toBeFalse();
});

it('allows blocked to be re-approved', function () {
    expect(CompanyStatus::Blocked->canTransitionTo(CompanyStatus::Approved))->toBeTrue();
});

it('never transitions to itself', function () {
    foreach (CompanyStatus::cases() as $status) {
        expect($status->canTransitionTo($status))->toBeFalse();
    }
});
