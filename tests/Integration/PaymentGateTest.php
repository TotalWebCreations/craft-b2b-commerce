<?php

use craft\commerce\elements\Order;
use craft\commerce\errors\PaymentException;
use craft\commerce\events\ProcessPaymentEvent;
use craft\commerce\models\payments\OffsitePaymentForm;
use craft\commerce\Plugin as Commerce;
use craft\commerce\services\Payments;
use craft\db\Query;
use craft\elements\User;
use totalwebcreations\b2bcommerce\enums\ApprovalStatus;
use totalwebcreations\b2bcommerce\enums\CompanyRole;
use totalwebcreations\b2bcommerce\Plugin;

// approvalMember() lives in NeedsApprovalTest.php; approvalCart(), insertApprovalRow() in
// ApprovalSubmitTest.php; enforcementCompany(), invoiceOrderForNewMember() in
// CreditEnforcementTest.php; creditTestManualGateway() in CreditBalanceTest.php; asSiteRequest()
// in helpers.php — all loaded globally by the suite.

/**
 * Message the approval gate refuses payment with. Identical to the completion backstop's, so the
 * two layers speak with one voice (and share the existing translation).
 */
const PAYMENT_APPROVAL_MESSAGE = 'This order requires approval before it can be placed.';

/**
 * Message the credit gate refuses payment with. Identical to CreditEnforcer's completion refusal.
 */
const PAYMENT_CREDIT_MESSAGE = "This order exceeds your company's credit limit.";

/**
 * Builds a tracked, incomplete order priced at $total for a purchaser who is gated above the given
 * threshold, paid via a non-invoice (Manual) gateway — the "pays by card" stand-in. With no
 * approval row it is exactly the order the payment gate must refuse before any charge.
 *
 * @return array{0: Order, 1: User}
 */
function gatedCardOrder(float $threshold, float $total): array
{
    [$purchaser] = approvalMember(CompanyRole::Purchaser, $threshold);
    $order = approvalCart($purchaser, $total);
    $order->gatewayId = creditTestManualGateway()->id;
    craftApp()->getElements()->saveElement($order);

    return [$order, $purchaser];
}

/**
 * Fires the wired EVENT_BEFORE_PROCESS_PAYMENT handler for the order exactly as
 * Payments::processPayment does, without driving a real capture. Isolates the wiring — its
 * request-scope exemption and the PaymentException it throws — from the gateway machinery.
 */
function firePaymentEvent(Order $order): void
{
    Commerce::getInstance()->getPayments()->trigger(
        Payments::EVENT_BEFORE_PROCESS_PAYMENT,
        new ProcessPaymentEvent([
            'order' => $order,
            'form' => new OffsitePaymentForm(),
        ])
    );
}

/**
 * Number of transaction rows recorded against the order, read straight from the table so a real
 * capture (or the absence of one) is proven at the database, not in memory.
 */
function transactionCount(int $orderId): int
{
    return (int) (new Query())
        ->from('{{%commerce_transactions}}')
        ->where(['orderId' => $orderId])
        ->count();
}

it('refuses payment for a gated purchaser with no approved approval', function () {
    [$order] = gatedCardOrder(500.0, 600.0);

    // The pure decision, asserted directly: the order needs approval and carries no approved row.
    expect(Plugin::getInstance()->paymentGate->paymentRefusalReason($order))
        ->toBe(PAYMENT_APPROVAL_MESSAGE);
});

it('allows payment once the order carries an approved approval', function () {
    [$order, $purchaser] = gatedCardOrder(500.0, 600.0);

    $company = Plugin::getInstance()->companyMembers->getCompanyForUser($purchaser->id);
    insertApprovalRow($order->id, $company->id, ApprovalStatus::Approved->value, $purchaser->id, 500.0);

    expect(Plugin::getInstance()->paymentGate->paymentRefusalReason($order))->toBeNull();
});

it('refuses payment on a pay-on-account order that exceeds the company credit limit', function () {
    $company = enforcementCompany(50.0);
    $order = invoiceOrderForNewMember($company, 100.0);

    expect(Plugin::getInstance()->paymentGate->paymentRefusalReason($order))
        ->toBe(PAYMENT_CREDIT_MESSAGE);
});

it('allows payment on a pay-on-account order within the company credit limit', function () {
    $company = enforcementCompany(500.0);
    $order = invoiceOrderForNewMember($company, 40.0);

    expect(Plugin::getInstance()->paymentGate->paymentRefusalReason($order))->toBeNull();
});

it('never refuses payment for an order with no customer', function () {
    // A bare guest cart on the manual gateway — no company, no gate applies.
    $order = new Order();
    $order->number = md5(uniqid((string) mt_rand(), true));
    craftApp()->getElements()->saveElement($order);
    trackElement($order);

    expect(Plugin::getInstance()->paymentGate->paymentRefusalReason($order))->toBeNull();
});

it('throws a PaymentException with the reason when the wired event fires on a site request', function () {
    [$order] = gatedCardOrder(500.0, 600.0);

    asSiteRequest(function () use ($order) {
        expect(fn () => firePaymentEvent($order))
            ->toThrow(PaymentException::class, PAYMENT_APPROVAL_MESSAGE);
    });
});

it('lets the wired event pass for an order that needs no refusal', function () {
    $company = enforcementCompany(500.0);
    $order = invoiceOrderForNewMember($company, 40.0);

    asSiteRequest(function () use ($order) {
        firePaymentEvent($order);
    });

    // No exception thrown — the event handler stood down. (Reaching here is the assertion.)
    expect(true)->toBeTrue();
});

it('does not fire the payment gate on a console or control-panel request (merchant override)', function () {
    // The default harness is a console request, so the wired handler exempts itself even though the
    // order plainly needs approval — the deliberate merchant override, mirroring the other guards.
    [$order] = gatedCardOrder(500.0, 600.0);

    firePaymentEvent($order);

    expect(true)->toBeTrue();
});

it('never charges a gated card order: processPayment throws before any transaction is created', function () {
    // End-to-end no-capture proof. EVENT_BEFORE_PROCESS_PAYMENT fires before processPayment creates
    // its transaction, so the refusal aborts the whole payment: the PaymentException propagates and
    // NO transaction row is ever written — the card is never charged.
    [$order] = gatedCardOrder(500.0, 600.0);

    expect(transactionCount($order->id))->toBe(0);

    asSiteRequest(function () use ($order) {
        $redirect = null;
        $transaction = null;

        expect(fn () => Commerce::getInstance()->getPayments()
            ->processPayment($order, new OffsitePaymentForm(), $redirect, $transaction))
            ->toThrow(PaymentException::class, PAYMENT_APPROVAL_MESSAGE);
    });

    expect(transactionCount($order->id))->toBe(0)
        ->and(orderCompletedInDb($order->id))->toBeFalse();
});
