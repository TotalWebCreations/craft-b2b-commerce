<?php

use craft\commerce\elements\Order;
use totalwebcreations\b2bcommerce\enums\ApprovalStatus;
use totalwebcreations\b2bcommerce\enums\CompanyRole;
use totalwebcreations\b2bcommerce\Plugin;
use yii\base\InvalidArgumentException;

// approvalMember() lives in NeedsApprovalTest.php; approvalCart(), insertApprovalRow(), approvalRow()
// in ApprovalSubmitTest.php; createTestUser(), mailSnapshot(), decodedMailSince(), mailCount()
// in helpers.php; creditTestInvoiceGateway() in CreditBalanceTest.php; orderCompanyRowExists() in
// OrderCompanyTest.php; orderCompletedInDb() in CreditEnforcementTest.php — all loaded globally.

/**
 * Builds an approvable scenario: an approved company gating purchasers above the threshold, the
 * purchaser (requester), and an approver of the same company. The company carries no invoice
 * settings, so an approval falls to the resume-checkout mail unless a test opts into invoicing.
 *
 * @return array{0: \craft\elements\User, 1: \craft\elements\User, 2: \totalwebcreations\b2bcommerce\elements\Company}
 */
function resolveScenario(): array
{
    [$purchaser, $company] = approvalMember(CompanyRole::Purchaser, 500.0);

    $approver = createTestUser('resolve_appr_' . uniqid() . '@example.test');
    Plugin::getInstance()->companyMembers->addUserToCompany($approver->id, $company->id, CompanyRole::Approver);

    return [$purchaser, $approver, $company];
}

it('approves a non-invoice order: flips the row, records the approver and mails a resume instruction', function () {
    [$purchaser, $approver, $company] = resolveScenario();
    $cart = approvalCart($purchaser, 600.0);
    insertApprovalRow($cart->id, $company->id, ApprovalStatus::Pending->value, $purchaser->id, 500.0);

    $snapshot = mailSnapshot();

    Plugin::getInstance()->approvals->approve($cart->id, $approver);

    $row = approvalRow($cart->id);
    $body = decodedMailSince($snapshot);

    // No invoice gateway on the order, so it is not placed directly: the row is approved, the
    // approver is recorded, the order stays a non-completed cart, and the requester is asked to
    // finish checkout themselves.
    expect($row['status'])->toBe(ApprovalStatus::Approved->value)
        ->and((int) $row['resolvedById'])->toBe($approver->id)
        ->and(orderCompletedInDb($cart->id))->toBeFalse()
        ->and($body)->toContain('Payment is still required');
});

it('approves an invoice order with credit room by placing it directly and mailing that it was placed', function () {
    [$purchaser, $approver, $company] = resolveScenario();
    $company->allowInvoicePayment = true;
    $company->creditLimit = 1000.0;
    craftApp()->getElements()->saveElement($company);

    $cart = approvalCart($purchaser, 600.0);
    $cart->gatewayId = creditTestInvoiceGateway()->id;
    craftApp()->getElements()->saveElement($cart);
    insertApprovalRow($cart->id, $company->id, ApprovalStatus::Pending->value, $purchaser->id, 500.0);

    $snapshot = mailSnapshot();

    Plugin::getInstance()->approvals->approve($cart->id, $approver);

    $row = approvalRow($cart->id);
    $body = decodedMailSince($snapshot);

    // Pay on account within the limit: the order is completed on the requester's behalf, the
    // company link (the receivable) is written, and the mail says it has been placed.
    expect($row['status'])->toBe(ApprovalStatus::Approved->value)
        ->and((int) $row['resolvedById'])->toBe($approver->id)
        ->and(orderCompletedInDb($cart->id))->toBeTrue()
        ->and(orderCompanyRowExists($cart->id))->toBeTrue()
        ->and($body)->toContain('It has been placed');
});

it('approves an invoice order without credit room but does not place it, mailing a resume instruction', function () {
    [$purchaser, $approver, $company] = resolveScenario();
    $company->allowInvoicePayment = true;
    $company->creditLimit = 50.0;
    craftApp()->getElements()->saveElement($company);

    $cart = approvalCart($purchaser, 600.0);
    $cart->gatewayId = creditTestInvoiceGateway()->id;
    craftApp()->getElements()->saveElement($cart);
    insertApprovalRow($cart->id, $company->id, ApprovalStatus::Pending->value, $purchaser->id, 500.0);

    $snapshot = mailSnapshot();

    Plugin::getInstance()->approvals->approve($cart->id, $approver);

    $row = approvalRow($cart->id);
    $body = decodedMailSince($snapshot);

    // The 600 order is over the 50 limit, so canCover refuses the direct completion: the approval
    // stands, the order is NOT completed, and the requester is asked to resume checkout.
    expect($row['status'])->toBe(ApprovalStatus::Approved->value)
        ->and(orderCompletedInDb($cart->id))->toBeFalse()
        ->and($body)->toContain('Payment is still required');
});

it('refuses to approve your own order (four-eyes)', function () {
    // An admin is both the requester and an approver; the four-eyes guard still refuses.
    [$admin, $company] = approvalMember(CompanyRole::Admin, 500.0);
    $cart = approvalCart($admin, 600.0);
    insertApprovalRow($cart->id, $company->id, ApprovalStatus::Pending->value, $admin->id, 500.0);

    expect(fn () => Plugin::getInstance()->approvals->approve($cart->id, $admin))
        ->toThrow(InvalidArgumentException::class, 'You cannot approve your own order.');
});

it('refuses a purchaser without the approve role with the oracle-free message', function () {
    [$purchaser, $company] = approvalMember(CompanyRole::Purchaser, 500.0);
    $cart = approvalCart($purchaser, 600.0);
    insertApprovalRow($cart->id, $company->id, ApprovalStatus::Pending->value, $purchaser->id, 500.0);

    expect(fn () => Plugin::getInstance()->approvals->approve($cart->id, $purchaser))
        ->toThrow(InvalidArgumentException::class, 'This approval request is not available.');
});

it('gives the same oracle-free message for a missing row and another company request', function () {
    [$purchaserA, $companyA] = approvalMember(CompanyRole::Purchaser, 500.0);
    $cartA = approvalCart($purchaserA, 600.0);
    insertApprovalRow($cartA->id, $companyA->id, ApprovalStatus::Pending->value, $purchaserA->id, 500.0);

    [$approverB] = approvalMember(CompanyRole::Approver, 500.0);

    $missingMessage = null;
    $wrongCompanyMessage = null;

    try {
        Plugin::getInstance()->approvals->approve(999999999, $approverB);
    } catch (InvalidArgumentException $exception) {
        $missingMessage = $exception->getMessage();
    }

    try {
        Plugin::getInstance()->approvals->approve($cartA->id, $approverB);
    } catch (InvalidArgumentException $exception) {
        $wrongCompanyMessage = $exception->getMessage();
    }

    expect($missingMessage)->toBe('This approval request is not available.')
        ->and($wrongCompanyMessage)->toBe($missingMessage);
});

it('refuses to resolve a request that has already been resolved', function () {
    [$purchaser, $approver, $company] = resolveScenario();
    $cart = approvalCart($purchaser, 600.0);
    insertApprovalRow($cart->id, $company->id, ApprovalStatus::Approved->value, $purchaser->id, 500.0);

    expect(fn () => Plugin::getInstance()->approvals->approve($cart->id, $approver))
        ->toThrow(InvalidArgumentException::class, 'This approval request has already been resolved.');
});

it('declines an order: requires a reason, records it and mails the requester', function () {
    [$purchaser, $approver, $company] = resolveScenario();
    $cart = approvalCart($purchaser, 600.0);
    insertApprovalRow($cart->id, $company->id, ApprovalStatus::Pending->value, $purchaser->id, 500.0);

    // An empty reason is refused before the row is touched.
    expect(fn () => Plugin::getInstance()->approvals->decline($cart->id, $approver, '  '))
        ->toThrow(InvalidArgumentException::class, 'A reason is required to decline an order.');

    $snapshot = mailSnapshot();

    Plugin::getInstance()->approvals->decline($cart->id, $approver, 'Over budget this quarter');

    $row = approvalRow($cart->id);
    $body = decodedMailSince($snapshot);

    expect($row['status'])->toBe(ApprovalStatus::Declined->value)
        ->and($row['reason'])->toBe('Over budget this quarter')
        ->and((int) $row['resolvedById'])->toBe($approver->id)
        ->and($body)->toContain('Over budget this quarter');
});

it('lets the requester resume an approved order but refuses a colleague', function () {
    [$purchaser, $approver, $company] = resolveScenario();
    $cart = approvalCart($purchaser, 600.0);
    insertApprovalRow($cart->id, $company->id, ApprovalStatus::Approved->value, $purchaser->id, 500.0);

    // A colleague — even a same-company approver — cannot resume someone else's cart.
    expect(fn () => Plugin::getInstance()->approvals->resumeCheckout($cart->id, $approver))
        ->toThrow(InvalidArgumentException::class, 'This approval request is not available.');

    // The requester gets their order back as the active cart (the durable guarantee; the cookie
    // hand-off itself needs a web request, as with quote acceptance).
    $returned = Plugin::getInstance()->approvals->resumeCheckout($cart->id, $purchaser);

    expect((int) $returned->id)->toBe($cart->id);
});

it('refuses to resume an order that has not been approved', function () {
    [$purchaser, $approver, $company] = resolveScenario();
    $cart = approvalCart($purchaser, 600.0);
    insertApprovalRow($cart->id, $company->id, ApprovalStatus::Pending->value, $purchaser->id, 500.0);

    expect(fn () => Plugin::getInstance()->approvals->resumeCheckout($cart->id, $purchaser))
        ->toThrow(InvalidArgumentException::class, 'This order has not been approved.');
});

it('refuses to resume an order that has already been completed', function () {
    [$purchaser, $approver, $company] = resolveScenario();
    $cart = approvalCart($purchaser, 600.0);
    insertApprovalRow($cart->id, $company->id, ApprovalStatus::Approved->value, $purchaser->id, 500.0);

    $reloaded = Order::find()->id($cart->id)->status(null)->one();
    $reloaded->markAsComplete();

    expect(fn () => Plugin::getInstance()->approvals->resumeCheckout($cart->id, $purchaser))
        ->toThrow(InvalidArgumentException::class, 'This order has already been completed.');
});

it('auto-approves a still-pending row when the order completes after the threshold was relaxed', function () {
    [$purchaser, $company] = approvalMember(CompanyRole::Purchaser, 500.0);
    $cart = approvalCart($purchaser, 600.0);
    insertApprovalRow($cart->id, $company->id, ApprovalStatus::Pending->value, $purchaser->id, 500.0);

    // The merchant relaxes the threshold after the submit, so live needsApproval drops to false and
    // the completion backstop no longer holds the order.
    $company->approvalThreshold = null;
    craftApp()->getElements()->saveElement($company);

    $reloaded = Order::find()->id($cart->id)->status(null)->one();

    expect($reloaded->markAsComplete())->toBeTrue();

    $row = approvalRow($cart->id);

    expect($row['status'])->toBe(ApprovalStatus::Approved->value)
        ->and($row['resolvedById'])->toBeNull()
        ->and($row['reason'])->toBe('Auto-approved: the order no longer required approval at completion.');
});

it('auto-approves a still-pending row via merchant override with the override reason', function () {
    [$purchaser, $company] = approvalMember(CompanyRole::Purchaser, 500.0);
    $cart = approvalCart($purchaser, 600.0);
    insertApprovalRow($cart->id, $company->id, ApprovalStatus::Pending->value, $purchaser->id, 500.0);

    // The threshold is NOT relaxed — the purchaser is still gated. A merchant completes the order
    // from the console/CP (a merchant override, which bypasses the storefront backstop), so the
    // reconciler records the override reason rather than the threshold-relaxed one.
    $reloaded = Order::find()->id($cart->id)->status(null)->one();

    expect($reloaded->markAsComplete())->toBeTrue();

    $row = approvalRow($cart->id);

    expect($row['status'])->toBe(ApprovalStatus::Approved->value)
        ->and($row['resolvedById'])->toBeNull()
        ->and($row['reason'])->toBe('Auto-approved: completed via merchant override.');
});
