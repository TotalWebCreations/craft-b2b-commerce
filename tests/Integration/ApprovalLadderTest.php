<?php

use craft\commerce\elements\Order;
use totalwebcreations\b2bcommerce\enums\ApprovalStatus;
use totalwebcreations\b2bcommerce\enums\CompanyRole;
use totalwebcreations\b2bcommerce\Plugin;
use yii\base\InvalidArgumentException;

// approvalMember() lives in NeedsApprovalTest.php; approvalCart(), approvalRow(), insertTier(),
// stepRows(), approvalIdForOrder(), refuseApprovalAsSiteRequest() in ApprovalSubmitTest.php;
// createTestUser() in helpers.php — all loaded globally by the suite.

/**
 * A gated purchaser plus $count distinct approvers of the same company, with a $count-level ladder
 * (level 1 minAmount 0, then 500 apart). Returns [purchaser, approvers[], company].
 *
 * @return array{0: \craft\elements\User, 1: array<int, \craft\elements\User>, 2: \totalwebcreations\b2bcommerce\elements\Company}
 */
function ladderScenario(int $count): array
{
    [$purchaser, $company] = approvalMember(CompanyRole::Purchaser, 0.0);

    $approvers = [];
    for ($i = 1; $i <= $count; $i++) {
        insertTier($company->id, $i, ($i - 1) * 500.0);
        $approver = createTestUser("ladder_appr_{$i}_" . uniqid() . '@example.test');
        Plugin::getInstance()->companyMembers->addUserToCompany($approver->id, $company->id, CompanyRole::Approver);
        $approvers[] = $approver;
    }

    return [$purchaser, $approvers, $company];
}

it('requires every step approved in order before the aggregate approval flips (3-tier ladder)', function () {
    [$purchaser, $approvers, $company] = ladderScenario(3);
    $cart = approvalCart($purchaser, 2000.0); // clears levels 1 (0), 2 (500), 3 (1000)
    Plugin::getInstance()->approvals->submitForApproval($cart, $purchaser);
    $approvalId = approvalIdForOrder($cart->id);

    // Backstop still holds while the ladder is open.
    Plugin::getInstance()->approvals->approve($cart->id, $approvers[0]);
    expect(approvalRow($cart->id)['status'])->toBe(ApprovalStatus::Pending->value)
        ->and(refuseApprovalAsSiteRequest($cart))->toBeTrue();

    Plugin::getInstance()->approvals->approve($cart->id, $approvers[1]);
    expect(approvalRow($cart->id)['status'])->toBe(ApprovalStatus::Pending->value)
        ->and(refuseApprovalAsSiteRequest($cart))->toBeTrue();

    // Last step approves -> aggregate flips -> backstop passes.
    Plugin::getInstance()->approvals->approve($cart->id, $approvers[2]);

    $steps = stepRows($approvalId);
    expect(array_column($steps, 'status'))->toBe(array_fill(0, 3, ApprovalStatus::Approved->value))
        ->and(approvalRow($cart->id)['status'])->toBe(ApprovalStatus::Approved->value)
        ->and(refuseApprovalAsSiteRequest($cart))->toBeFalse();
});

it('resolves the lowest open step and never lets a higher step be signed first (sequential gating)', function () {
    [$purchaser, $approvers, $company] = ladderScenario(2);
    $cart = approvalCart($purchaser, 800.0);
    Plugin::getInstance()->approvals->submitForApproval($cart, $purchaser);
    $approvalId = approvalIdForOrder($cart->id);

    Plugin::getInstance()->approvals->approve($cart->id, $approvers[0]);

    $steps = stepRows($approvalId);
    // Level 1 approved, level 2 still pending — the ladder advanced in order.
    expect($steps[0]['status'])->toBe(ApprovalStatus::Approved->value)
        ->and($steps[1]['status'])->toBe(ApprovalStatus::Pending->value);
});

it('enforces four-eyes across steps: one approver cannot clear two distinct levels', function () {
    [$purchaser, $approvers, $company] = ladderScenario(2);
    $cart = approvalCart($purchaser, 800.0);
    Plugin::getInstance()->approvals->submitForApproval($cart, $purchaser);

    Plugin::getInstance()->approvals->approve($cart->id, $approvers[0]);

    // The same approver tries the second level — refused.
    expect(fn () => Plugin::getInstance()->approvals->approve($cart->id, $approvers[0]))
        ->toThrow(InvalidArgumentException::class, 'You have already approved a step of this order.');
});

it('still refuses the submitter approving any step of their own laddered order', function () {
    [$purchaser, $approvers, $company] = ladderScenario(2);
    $cart = approvalCart($purchaser, 800.0);
    Plugin::getInstance()->approvals->submitForApproval($cart, $purchaser);

    // requireResolvableRow's role gate is checked before the four-eyes check, and only an
    // approve-capable role (Admin/Approver) ever reaches that far — submitForApproval only ever
    // gates a Purchaser, so a submitter who is STILL a plain purchaser can never reach the specific
    // "cannot approve your own order" branch at all (they are refused earlier, generically, with
    // "not available" — see ApprovalResolveTest's "refuses a purchaser without the approve role").
    // To exercise the four-eyes-across-steps guard on the actual requester (not just role
    // ineligibility), promote the submitter to an approve-capable role first, exactly mirroring
    // ApprovalResolveTest's own four-eyes scenario (an admin who is both requester and approver).
    Plugin::getInstance()->companyMembers->changeRole($company, $purchaser->id, CompanyRole::Admin);

    expect(fn () => Plugin::getInstance()->approvals->approve($cart->id, $purchaser))
        ->toThrow(InvalidArgumentException::class, 'You cannot approve your own order.');
});

it('keeps the tier-less single-approval path working exactly as before (legacy)', function () {
    [$purchaser, $company] = approvalMember(CompanyRole::Purchaser, 500.0);
    $approver = createTestUser('legacy_appr_' . uniqid() . '@example.test');
    Plugin::getInstance()->companyMembers->addUserToCompany($approver->id, $company->id, CompanyRole::Approver);

    $cart = approvalCart($purchaser, 600.0);
    Plugin::getInstance()->approvals->submitForApproval($cart, $purchaser);

    // No tiers -> a single approve resolves the whole approval, as today.
    Plugin::getInstance()->approvals->approve($cart->id, $approver);

    expect(stepRows(approvalIdForOrder($cart->id)))->toBe([])
        ->and(approvalRow($cart->id)['status'])->toBe(ApprovalStatus::Approved->value)
        ->and((int) approvalRow($cart->id)['resolvedById'])->toBe($approver->id);
});

it('short-circuits the whole ladder to declined when a mid-chain step is declined', function () {
    [$purchaser, $approvers, $company] = ladderScenario(3);
    $cart = approvalCart($purchaser, 2000.0);
    Plugin::getInstance()->approvals->submitForApproval($cart, $purchaser);
    $approvalId = approvalIdForOrder($cart->id);

    // Level 1 approves, level 2 declines mid-chain.
    Plugin::getInstance()->approvals->approve($cart->id, $approvers[0]);
    Plugin::getInstance()->approvals->decline($cart->id, $approvers[1], 'Budget frozen this quarter');

    $steps = stepRows($approvalId);

    expect($steps[0]['status'])->toBe(ApprovalStatus::Approved->value)
        ->and($steps[1]['status'])->toBe(ApprovalStatus::Declined->value)
        ->and($steps[1]['reason'])->toBe('Budget frozen this quarter')
        ->and((int) $steps[1]['resolvedById'])->toBe($approvers[1]->id)
        // Level 3 was never reached — it stays pending, but the aggregate is declined (terminal).
        ->and($steps[2]['status'])->toBe(ApprovalStatus::Pending->value)
        ->and(approvalRow($cart->id)['status'])->toBe(ApprovalStatus::Declined->value)
        ->and(approvalRow($cart->id)['reason'])->toBe('Budget frozen this quarter');

    // The backstop keeps holding a declined order (never approved).
    expect(refuseApprovalAsSiteRequest($cart))->toBeTrue();
});

it('still requires a non-empty reason to decline a laddered step', function () {
    [$purchaser, $approvers, $company] = ladderScenario(2);
    $cart = approvalCart($purchaser, 800.0);
    Plugin::getInstance()->approvals->submitForApproval($cart, $purchaser);

    expect(fn () => Plugin::getInstance()->approvals->decline($cart->id, $approvers[0], '   '))
        ->toThrow(InvalidArgumentException::class, 'A reason is required to decline an order.');
});
