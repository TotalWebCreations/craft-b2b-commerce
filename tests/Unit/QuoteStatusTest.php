<?php

use totalwebcreations\b2bcommerce\enums\QuoteStatus;

it('allows requested to move to sent, declined or expired', function () {
    expect(QuoteStatus::Requested->canTransitionTo(QuoteStatus::Sent))->toBeTrue()
        ->and(QuoteStatus::Requested->canTransitionTo(QuoteStatus::Declined))->toBeTrue()
        ->and(QuoteStatus::Requested->canTransitionTo(QuoteStatus::Expired))->toBeTrue();
});

it('does not allow requested to move straight to accepted', function () {
    expect(QuoteStatus::Requested->canTransitionTo(QuoteStatus::Accepted))->toBeFalse();
});

it('allows sent to move to accepted, declined or expired', function () {
    expect(QuoteStatus::Sent->canTransitionTo(QuoteStatus::Accepted))->toBeTrue()
        ->and(QuoteStatus::Sent->canTransitionTo(QuoteStatus::Declined))->toBeTrue()
        ->and(QuoteStatus::Sent->canTransitionTo(QuoteStatus::Expired))->toBeTrue();
});

it('does not allow sent to move back to requested', function () {
    expect(QuoteStatus::Sent->canTransitionTo(QuoteStatus::Requested))->toBeFalse();
});

it('treats accepted, declined and expired as terminal', function () {
    $terminals = [QuoteStatus::Accepted, QuoteStatus::Declined, QuoteStatus::Expired];

    foreach ($terminals as $terminal) {
        foreach (QuoteStatus::cases() as $target) {
            expect($terminal->canTransitionTo($target))->toBeFalse();
        }
    }
});

it('never transitions to itself', function () {
    foreach (QuoteStatus::cases() as $status) {
        expect($status->canTransitionTo($status))->toBeFalse();
    }
});
