<?php

use Craft;
use craft\commerce\elements\Order;
use totalwebcreations\b2bcommerce\enums\ApprovalStatus;
use totalwebcreations\b2bcommerce\enums\CompanyRole;
use totalwebcreations\b2bcommerce\modules\approvals\services\Approvals;
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

it('resolves dangling pending steps when a laddered order completes via merchant override', function () {
    [$purchaser, $approvers, $company] = ladderScenario(3);
    $cart = approvalCart($purchaser, 2000.0);
    Plugin::getInstance()->approvals->submitForApproval($cart, $purchaser);
    $approvalId = approvalIdForOrder($cart->id);

    // Only the first rung is signed; the merchant then completes from the console (override path,
    // which bypasses the storefront backstop).
    Plugin::getInstance()->approvals->approve($cart->id, $approvers[0]);

    $reloaded = Order::find()->id($cart->id)->status(null)->one();
    expect($reloaded->markAsComplete())->toBeTrue();

    $steps = stepRows($approvalId);

    // The aggregate is reconciled to approved and NO step is left pending.
    expect(approvalRow($cart->id)['status'])->toBe(ApprovalStatus::Approved->value)
        ->and(array_column($steps, 'status'))->toBe(array_fill(0, 3, ApprovalStatus::Approved->value));
});

/**
 * Regression for the last-rung atomicity fix: ladderApprove used to flip the last step-status row
 * and the aggregate b2b_approvals row as two separate, non-transactional writes. A crash (or any DB
 * failure) between them left every step approved but the aggregate durably stuck pending — failing
 * closed, but not self-healing.
 *
 * Forced deterministically via a real DB failure rather than a mock: a trigger on {{%b2b_approvals}}
 * makes the aggregate UPDATE for this specific order genuinely fail. If the two writes are wrapped
 * in one transaction, the preceding step-status UPDATE (already executed, same transaction) is
 * rolled back too, so the last step is left exactly where it started: pending. Before the fix, the
 * step write was already committed on its own and would still read approved.
 */
it('rolls back the last step-status flip together with the aggregate flip when the aggregate write fails', function () {
    [$purchaser, $approvers, $company] = ladderScenario(2);
    $cart = approvalCart($purchaser, 800.0);
    Plugin::getInstance()->approvals->submitForApproval($cart, $purchaser);
    $approvalId = approvalIdForOrder($cart->id);

    Plugin::getInstance()->approvals->approve($cart->id, $approvers[0]);

    $db = Craft::$app->getDb();
    $triggerName = 'b2b_test_force_aggregate_failure';

    $db->createCommand("
        CREATE TRIGGER {$triggerName}
        BEFORE UPDATE ON {{%b2b_approvals}}
        FOR EACH ROW
        BEGIN
            IF NEW.orderId = {$cart->id} THEN
                SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'forced failure for atomicity test';
            END IF;
        END
    ")->execute();

    try {
        $threw = false;

        try {
            Plugin::getInstance()->approvals->approve($cart->id, $approvers[1]);
        } catch (\Throwable) {
            $threw = true;
        }

        expect($threw)->toBeTrue();
    } finally {
        $db->createCommand("DROP TRIGGER IF EXISTS {$triggerName}")->execute();
    }

    $steps = stepRows($approvalId);

    // Neither write landed: the last step is still pending — rolled back together with the
    // aggregate — rather than left committed as approved on its own.
    expect($steps[1]['status'])->toBe(ApprovalStatus::Pending->value)
        ->and(approvalRow($cart->id)['status'])->toBe(ApprovalStatus::Pending->value);
});

/**
 * Regression for the decline-atomicity fix: decline() used to flip the open step to Declined and
 * the aggregate b2b_approvals row to Declined as two separate, non-transactional writes. A crash
 * (or any DB failure) between them left the step durably Declined while the aggregate was still
 * durably Pending — a state every other guard in this class assumes impossible (see openStep(),
 * which treats "no pending step" and "pending aggregate" as mutually exclusive) — and one a
 * concurrent/crash-resumed approve() could exploit to walk the remaining rungs and flip the
 * aggregate to Approved, placing a declined order.
 *
 * Forced deterministically via the same technique as the last-rung atomicity test above: a trigger
 * makes the aggregate UPDATE for this specific order genuinely fail. With both writes now wrapped in
 * one transaction, the already-executed step UPDATE is rolled back too, leaving the step exactly
 * where it started: pending — cleanly retriable, not left as a durably declined orphan.
 */
it('rolls back the step-status flip together with the aggregate flip when a decline\'s aggregate write fails', function () {
    [$purchaser, $approvers, $company] = ladderScenario(2);
    $cart = approvalCart($purchaser, 800.0);
    Plugin::getInstance()->approvals->submitForApproval($cart, $purchaser);
    $approvalId = approvalIdForOrder($cart->id);

    $db = Craft::$app->getDb();
    $triggerName = 'b2b_test_force_decline_aggregate_failure';

    $db->createCommand("
        CREATE TRIGGER {$triggerName}
        BEFORE UPDATE ON {{%b2b_approvals}}
        FOR EACH ROW
        BEGIN
            IF NEW.orderId = {$cart->id} THEN
                SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'forced failure for decline atomicity test';
            END IF;
        END
    ")->execute();

    try {
        $threw = false;

        try {
            Plugin::getInstance()->approvals->decline($cart->id, $approvers[0], 'Forced failure test');
        } catch (\Throwable) {
            $threw = true;
        }

        expect($threw)->toBeTrue();
    } finally {
        $db->createCommand("DROP TRIGGER IF EXISTS {$triggerName}")->execute();
    }

    $steps = stepRows($approvalId);

    // Neither write landed: the open step is still pending — rolled back together with the
    // aggregate — rather than left committed as declined on its own.
    expect($steps[0]['status'])->toBe(ApprovalStatus::Pending->value)
        ->and(approvalRow($cart->id)['status'])->toBe(ApprovalStatus::Pending->value);
});

/**
 * Regression for the last-rung fix that closes the actual placement hole: ladderApprove's last-rung
 * transaction used to check only the STEP update's affected-rows and completely ignore the
 * AGGREGATE update's. The aggregate UPDATE carries its own `status = pending` guard, so once the
 * aggregate is no longer pending (here, because a different approver has legitimately declined a
 * lower rung — which, per openStep(), never touches a higher, still-pending rung), that UPDATE
 * silently matches zero rows. The pre-fix code never looked at that count: it trusted the STEP write
 * (which genuinely succeeds, because the higher rung really is still pending) and called
 * finalizeApproval regardless — completing an order whose ladder had actually been declined.
 *
 * requireResolvableRow's own status check already refuses a call made once the decline has fully
 * committed, so reproducing the race through the public approve() API alone would need genuine
 * thread-level concurrency: a racing approver whose requireResolvableRow() read would have landed a
 * moment before the decline committed, seeing the aggregate as still pending. This test invokes the
 * private ladderApprove() directly via reflection to stand in for that already-passed outer read —
 * every value it receives ($row, $steps) is read fresh, live, straight off the actual (declined)
 * database, exactly as a racing approver's own reads would have been at that instant — isolating the
 * assertion to ladderApprove's own last-rung transaction (what this fix hardens) without re-testing
 * the unrelated, unchanged requireResolvableRow guard.
 */
it('refuses to approve the last rung — and never places the order — when the aggregate has been concurrently declined', function () {
    [$purchaser, $approvers, $company] = ladderScenario(3);
    $cart = approvalCart($purchaser, 2000.0);
    Plugin::getInstance()->approvals->submitForApproval($cart, $purchaser);
    $approvalId = approvalIdForOrder($cart->id);

    // Rung 1 approves normally, then rung 2 is declined — both real, fully committed via the
    // (now-atomic) service methods. The aggregate is genuinely Declined; rung 3 — never reached by
    // the decline's short-circuit — is still genuinely pending.
    Plugin::getInstance()->approvals->approve($cart->id, $approvers[0]);
    Plugin::getInstance()->approvals->decline($cart->id, $approvers[1], 'Budget frozen this quarter');

    expect(approvalRow($cart->id)['status'])->toBe(ApprovalStatus::Declined->value);

    // A third, uninvolved approver races in for rung 3 — the genuinely still-pending, now-last rung.
    $row = approvalRow($cart->id);
    $steps = stepRows($approvalId);

    $method = new ReflectionMethod(Approvals::class, 'ladderApprove');
    $method->setAccessible(true);

    $threw = false;

    try {
        $method->invoke(Plugin::getInstance()->approvals, $cart->id, $approvers[2], $row, $steps);
    } catch (\Throwable) {
        $threw = true;
    }

    expect($threw)->toBeTrue();

    $stepsAfter = stepRows($approvalId);

    // Refused cleanly: the aggregate stays Declined, rung 3 never flips to approved (rolled back
    // together with the refusal), and — critically — the order was never completed/placed.
    expect(approvalRow($cart->id)['status'])->toBe(ApprovalStatus::Declined->value)
        ->and($stepsAfter[2]['status'])->toBe(ApprovalStatus::Pending->value)
        ->and(Order::find()->id($cart->id)->status(null)->one()->isCompleted)->toBeFalse();
});
