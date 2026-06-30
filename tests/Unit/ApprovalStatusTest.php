<?php

use totalwebcreations\b2bcommerce\enums\ApprovalStatus;

it('maps every case to its lowercase string value', function () {
    expect(ApprovalStatus::Pending->value)->toBe('pending')
        ->and(ApprovalStatus::Approved->value)->toBe('approved')
        ->and(ApprovalStatus::Declined->value)->toBe('declined');
});

it('allows pending to move to approved or declined', function () {
    expect(ApprovalStatus::Pending->canTransitionTo(ApprovalStatus::Approved))->toBeTrue()
        ->and(ApprovalStatus::Pending->canTransitionTo(ApprovalStatus::Declined))->toBeTrue();
});

it('treats approved and declined as terminal', function () {
    $terminals = [ApprovalStatus::Approved, ApprovalStatus::Declined];

    foreach ($terminals as $terminal) {
        foreach (ApprovalStatus::cases() as $target) {
            expect($terminal->canTransitionTo($target))->toBeFalse();
        }
    }
});

it('never transitions to itself', function () {
    foreach (ApprovalStatus::cases() as $status) {
        expect($status->canTransitionTo($status))->toBeFalse();
    }
});
