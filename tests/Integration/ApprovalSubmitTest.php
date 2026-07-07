<?php

use craft\commerce\elements\Order;
use craft\commerce\Plugin as Commerce;
use craft\db\Query;
use craft\elements\User;
use craft\web\Response as WebResponse;
use totalwebcreations\b2bcommerce\controllers\ApprovalsController;
use totalwebcreations\b2bcommerce\elements\Approval;
use totalwebcreations\b2bcommerce\enums\ApprovalStatus;
use totalwebcreations\b2bcommerce\enums\CompanyRole;
use totalwebcreations\b2bcommerce\enums\QuoteStatus;
use totalwebcreations\b2bcommerce\Plugin;
use yii\base\InvalidArgumentException;

// approvalMember() lives in NeedsApprovalTest.php; quoteMember(), createTestUser(),
// createTestVariant(), bareQuoteOrder(), asSiteRequest(), mailCount(), storedLineItemQty(),
// insertQuoteRow() are loaded globally by the suite.

/**
 * Exposes the shared feature gate the approvals action runs first, without the full web
 * request/response plumbing a real action dispatch would need. asFailure() is Craft's own
 * well-covered helper, so it is stubbed to a bare response carrying the message;
 * requireFeature()'s real logic runs unchanged — it is the exact early check in actionSubmit.
 */
class ApprovalsFeatureGateProbe extends ApprovalsController
{
    public function gate(string $settingName): ?WebResponse
    {
        return $this->requireFeature($settingName);
    }

    public function asFailure(?string $message = null, array $data = [], array $routeParams = []): ?WebResponse
    {
        $response = new WebResponse();
        $response->data = ['message' => $message];

        return $response;
    }
}

/**
 * Builds a tracked cart order carrying a single line item priced at $total, owned by the given
 * customer (a single line item, qty 1, no tax or shipping in the test environment, so the order
 * total equals $total). The customer is what the completion backstop reads.
 */
function approvalCart(User $customer, float $total): Order
{
    $order = new Order();
    $order->number = md5(uniqid((string) mt_rand(), true));
    $order->setCustomer($customer);

    if (!craftApp()->getElements()->saveElement($order)) {
        throw new RuntimeException('Could not save approval cart: ' . implode(', ', $order->getFirstErrors()));
    }

    trackElement($order);

    $variant = createTestVariant('APRSUB-' . substr(uniqid(), -6), $total);
    Plugin::getInstance()->quickOrder->addResolvedPurchasable($order, $variant->id, 1, $variant->sku);
    craftApp()->getElements()->saveElement($order);

    return $order;
}

/**
 * Creates a tracked Approval element for the given order, bypassing submitForApproval so a test can
 * pin an exact status, threshold snapshot, resolver and reason. Saving the element writes the
 * b2b_approvals row through afterSave. Mirrors insertQuoteRow.
 */
function insertApprovalRow(
    int $orderId,
    int $companyId,
    string $status,
    ?int $requestedById = null,
    ?float $threshold = null,
    ?int $resolvedById = null,
    ?string $reason = null,
): void {
    $approval = new Approval();
    $approval->orderId = $orderId;
    $approval->companyId = $companyId;
    $approval->approvalStatus = $status;
    $approval->requestedById = $requestedById;
    $approval->thresholdAmount = $threshold;
    $approval->resolvedById = $resolvedById;
    $approval->reason = $reason;

    if (!craftApp()->getElements()->saveElement($approval)) {
        throw new RuntimeException('Could not save approval element: ' . implode(', ', $approval->getFirstErrors()));
    }

    trackElement($approval);
}

/**
 * Reads the approval row for the given order straight from the table.
 *
 * @return array<string, mixed>|null
 */
function approvalRow(int $orderId): ?array
{
    return (new Query())
        ->from('{{%b2b_approvals}}')
        ->where(['orderId' => $orderId])
        ->one() ?: null;
}

/**
 * Runs the completion backstop directly under a faked storefront request (the only origin it acts
 * on) and reports whether it refused (threw). Mirrors CreditEnforcementTest's enforceAsSiteRequest:
 * a real full completion cannot be driven under a faked site request in this console harness.
 */
function refuseApprovalAsSiteRequest(Order $order): bool
{
    $refused = false;

    asSiteRequest(function () use ($order, &$refused) {
        try {
            Plugin::getInstance()->approvals->enforceApprovalBeforeCompletion($order);
        } catch (Throwable) {
            $refused = true;
        }
    });

    return $refused;
}

it('submits an order for approval: records a pending row with the threshold snapshot, mails both approvers and detaches the cart', function () {
    [$user, $company] = approvalMember(CompanyRole::Purchaser, 500.0);

    $approver = createTestUser('appr_approver_' . uniqid() . '@example.test');
    Plugin::getInstance()->companyMembers->addUserToCompany($approver->id, $company->id, CompanyRole::Approver);
    $admin = createTestUser('appr_admin_' . uniqid() . '@example.test');
    Plugin::getInstance()->companyMembers->addUserToCompany($admin->id, $company->id, CompanyRole::Admin);

    $cart = approvalCart($user, 600.0);
    $originalId = $cart->id;
    $mailBefore = mailCount();

    Plugin::getInstance()->approvals->submitForApproval($cart, $user);

    $row = approvalRow($originalId);
    $survivor = Order::find()->id($originalId)->status(null)->one();

    // The purchaser who submits is never an approver, so exactly the two approver/admin members
    // are mailed; the detached order survives as a non-completed order (forgetCart, not delete).
    expect($row)->not->toBeNull()
        ->and($row['status'])->toBe(ApprovalStatus::Pending->value)
        ->and((int) $row['companyId'])->toBe($company->id)
        ->and((int) $row['requestedById'])->toBe($user->id)
        ->and((float) $row['thresholdAmount'])->toBe(500.0)
        ->and(mailCount() - $mailBefore)->toBe(2)
        ->and($survivor)->not->toBeNull()
        ->and($survivor->isCompleted)->toBeFalse();
});

it('refuses to submit an empty cart for approval', function () {
    [$user] = approvalMember(CompanyRole::Purchaser, 500.0);

    $cart = new Order();
    $cart->number = md5(uniqid((string) mt_rand(), true));
    craftApp()->getElements()->saveElement($cart);
    trackElement($cart);

    expect(fn () => Plugin::getInstance()->approvals->submitForApproval($cart, $user))
        ->toThrow(InvalidArgumentException::class, 'Your cart is empty.');
});

it('refuses to submit an order that does not require approval', function () {
    // A purchaser under the threshold is not gated, so an explicit submit is confusing and refused.
    [$user] = approvalMember(CompanyRole::Purchaser, 500.0);
    $cart = approvalCart($user, 400.0);

    expect(fn () => Plugin::getInstance()->approvals->submitForApproval($cart, $user))
        ->toThrow(InvalidArgumentException::class, 'This order does not require approval.');
});

it('refuses to submit an order that already has an approval row', function () {
    [$user, $company] = approvalMember(CompanyRole::Purchaser, 500.0);
    $cart = approvalCart($user, 600.0);
    insertApprovalRow($cart->id, $company->id, ApprovalStatus::Pending->value, $user->id, 500.0);

    expect(fn () => Plugin::getInstance()->approvals->submitForApproval($cart, $user))
        ->toThrow(InvalidArgumentException::class, 'This order is already awaiting approval.');
});

it('refuses to submit an order that is part of an open quote', function () {
    [$user, $company] = approvalMember(CompanyRole::Purchaser, 500.0);
    $cart = approvalCart($user, 600.0);
    insertQuoteRow($cart->id, QuoteStatus::Requested->value, $company->id, $user->id);

    expect(fn () => Plugin::getInstance()->approvals->submitForApproval($cart, $user))
        ->toThrow(InvalidArgumentException::class, 'This cart is part of a quote.');
});

it('backstop refuses a gated purchaser order with no approval row', function () {
    [$user] = approvalMember(CompanyRole::Purchaser, 500.0);
    $cart = approvalCart($user, 600.0);

    expect(refuseApprovalAsSiteRequest($cart))->toBeTrue()
        ->and($cart->getErrors('customerId'))
        ->toBe(['This order requires approval before it can be placed.']);
});

it('backstop refuses a gated purchaser order that is still pending approval', function () {
    [$user, $company] = approvalMember(CompanyRole::Purchaser, 500.0);
    $cart = approvalCart($user, 600.0);
    insertApprovalRow($cart->id, $company->id, ApprovalStatus::Pending->value, $user->id, 500.0);

    expect(refuseApprovalAsSiteRequest($cart))->toBeTrue();
});

it('backstop passes a gated purchaser order once its approval is approved', function () {
    [$user, $company] = approvalMember(CompanyRole::Purchaser, 500.0);
    $cart = approvalCart($user, 600.0);
    insertApprovalRow($cart->id, $company->id, ApprovalStatus::Approved->value, $user->id, 500.0);

    expect(refuseApprovalAsSiteRequest($cart))->toBeFalse();
});

it('backstop never gates an admin, even at the same amount', function () {
    // No paid exemption and no role exemption trickery: an admin simply never triggers needsApproval,
    // so the backstop stands down regardless of amount.
    [$user] = approvalMember(CompanyRole::Admin, 500.0);
    $cart = approvalCart($user, 600.0);

    expect(refuseApprovalAsSiteRequest($cart))->toBeFalse();
});

it('completes an accepted-quote order for a gated purchaser once its approval is approved (both guards satisfied)', function () {
    // The quote-interplay case: an accepted quote whose purchaser is over the threshold must ALSO
    // carry an approved approval. With the quote accepted AND the approval approved, both completion
    // guards stand down and the order completes.
    [$user, $company] = approvalMember(CompanyRole::Purchaser, 500.0);
    $cart = approvalCart($user, 600.0);
    insertQuoteRow($cart->id, QuoteStatus::Accepted->value, $company->id, $user->id);
    insertApprovalRow($cart->id, $company->id, ApprovalStatus::Approved->value, $user->id, 500.0);

    $quoteVetoRefused = false;
    asSiteRequest(function () use ($cart, &$quoteVetoRefused) {
        try {
            Plugin::getInstance()->quotes->enforceAcceptedBeforeCompletion($cart);
        } catch (Throwable) {
            $quoteVetoRefused = true;
        }
    });

    expect($quoteVetoRefused)->toBeFalse()
        ->and(refuseApprovalAsSiteRequest($cart))->toBeFalse();

    // And the order actually completes (console path; a full site-request completion cannot be
    // driven in this harness — see CreditEnforcementTest).
    $reloaded = Order::find()->id($cart->id)->status(null)->one();

    expect($reloaded->markAsComplete())->toBeTrue()
        ->and($reloaded->isCompleted)->toBeTrue();
});

it('vetoes a line-item mutation on a pending-approval cart on a site request', function () {
    [$user, $company] = approvalMember(CompanyRole::Purchaser, 500.0);
    $cart = approvalCart($user, 600.0);
    insertApprovalRow($cart->id, $company->id, ApprovalStatus::Pending->value, $user->id, 500.0);

    $reloaded = Order::find()->id($cart->id)->status(null)->one();
    $lineItem = $reloaded->getLineItems()[0];
    $lineItemId = $lineItem->id;
    $storedQty = (int) $lineItem->qty;
    $lineItem->qty = $storedQty + 3;
    $reloaded->setLineItems([$lineItem]);

    $saved = null;

    asSiteRequest(function () use ($reloaded, &$saved) {
        $saved = craftApp()->getElements()->saveElement($reloaded);
    });

    expect($saved)->toBeFalse()
        ->and($reloaded->getFirstError('lineItems'))->toBe('This cart is awaiting approval and cannot be modified.')
        ->and(storedLineItemQty($lineItemId))->toBe($storedQty);
});

it('stands down the approval line-item freeze for the completion save on a storefront request', function () {
    [$user, $company] = approvalMember(CompanyRole::Purchaser, 500.0);
    $cart = approvalCart($user, 600.0);
    insertApprovalRow($cart->id, $company->id, ApprovalStatus::Approved->value, $user->id, 500.0);

    // Regression twin of the accepted-quote stand-down test (QuoteAcceptanceTest): during
    // approve()'s direct on-account placement over HTTP, markAsComplete() flips isCompleted
    // BEFORE saving, LineItem::getOptionsSignature() becomes salted with the line-item id, and
    // the approval freeze used to veto its own completion save as a phantom options edit (the
    // payment already authorized, the order stuck incomplete). The exact completion-save state
    // is reproduced (in-memory isCompleted flip + BEFORE_SAVE on a site request); the veto must
    // stand down.
    $reloaded = Order::find()->id($cart->id)->status(null)->one();
    $reloaded->isCompleted = true;

    $event = new \craft\events\ModelEvent();

    asSiteRequest(function () use ($reloaded, $event) {
        $reloaded->trigger(Order::EVENT_BEFORE_SAVE, $event);
    });

    expect($event->isValid)->toBeTrue()
        ->and($reloaded->getErrors('lineItems'))->toBe([]);
});

it('excludes approval orders from the inactive-cart purge query', function () {
    [$user, $company] = approvalMember(CompanyRole::Purchaser, 500.0);
    $pendingApprovalOrder = approvalCart($user, 600.0);
    insertApprovalRow($pendingApprovalOrder->id, $company->id, ApprovalStatus::Pending->value, $user->id, 500.0);

    $plainCart = bareQuoteOrder();

    $query = (new Query())
        ->select(['orders.id'])
        ->where(['not', ['isCompleted' => true]])
        ->from(['orders' => \craft\commerce\db\Table::ORDERS]);

    $event = new \craft\commerce\events\CartPurgeEvent(['inactiveCartsQuery' => $query]);
    Commerce::getInstance()->getCarts()->trigger(
        \craft\commerce\services\Carts::EVENT_BEFORE_PURGE_INACTIVE_CARTS,
        $event
    );

    $ids = array_map('intval', $event->inactiveCartsQuery->column());

    expect($ids)->not->toContain($pendingApprovalOrder->id)
        ->and($ids)->toContain($plainCart->id);
});

it('submits an accepted-quote order for approval via the real submit path, then approves and completes it', function () {
    // C1 mainline: a purchaser accepts an over-threshold quote, then submits THAT accepted-quote
    // order for approval through submitForApproval itself (not a direct row insert). The submit
    // guard must let an accepted quote through — it refuses only open quotes — so the deadlock
    // (accepted-quote freeze vs. the approval backstop demanding an approved row) is resolved.
    [$purchaser, $company] = approvalMember(CompanyRole::Purchaser, 500.0);
    $approver = createTestUser('appr_acceptedq_' . uniqid() . '@example.test');
    Plugin::getInstance()->companyMembers->addUserToCompany($approver->id, $company->id, CompanyRole::Approver);

    $cart = approvalCart($purchaser, 600.0);
    insertQuoteRow($cart->id, QuoteStatus::Accepted->value, $company->id, $purchaser->id);

    // Real path — must NOT be refused as "part of a quote".
    Plugin::getInstance()->approvals->submitForApproval($cart, $purchaser);

    expect(approvalRow($cart->id))->not->toBeNull()
        ->and(approvalRow($cart->id)['status'])->toBe(ApprovalStatus::Pending->value);

    // The approver approves (non-invoice gateway → row flips to approved, order left for resume).
    Plugin::getInstance()->approvals->approve($cart->id, $approver);

    expect(approvalRow($cart->id)['status'])->toBe(ApprovalStatus::Approved->value);

    // Both completion guards now stand down.
    $quoteVetoRefused = false;
    asSiteRequest(function () use ($cart, &$quoteVetoRefused) {
        try {
            Plugin::getInstance()->quotes->enforceAcceptedBeforeCompletion($cart);
        } catch (Throwable) {
            $quoteVetoRefused = true;
        }
    });

    expect($quoteVetoRefused)->toBeFalse()
        ->and(refuseApprovalAsSiteRequest($cart))->toBeFalse();

    $reloaded = Order::find()->id($cart->id)->status(null)->one();

    expect($reloaded->markAsComplete())->toBeTrue()
        ->and($reloaded->isCompleted)->toBeTrue();
});

it('tailors the submit refusal to the existing row status: declined and approved', function () {
    // A declined row is terminal (start a new cart); an approved row points to resume checkout.
    [$purchaser, $company] = approvalMember(CompanyRole::Purchaser, 500.0);

    $declinedCart = approvalCart($purchaser, 600.0);
    insertApprovalRow($declinedCart->id, $company->id, ApprovalStatus::Declined->value, $purchaser->id, 500.0);

    expect(fn () => Plugin::getInstance()->approvals->submitForApproval($declinedCart, $purchaser))
        ->toThrow(InvalidArgumentException::class, "This order's approval request was declined. Start a new cart to order again.");

    $approvedCart = approvalCart($purchaser, 600.0);
    insertApprovalRow($approvedCart->id, $company->id, ApprovalStatus::Approved->value, $purchaser->id, 500.0);

    expect(fn () => Plugin::getInstance()->approvals->submitForApproval($approvedCart, $purchaser))
        ->toThrow(InvalidArgumentException::class, 'This order has already been approved. Resume checkout to place it.');
});

it('disarms the completion backstop for a gated purchaser when the feature toggle is off', function () {
    [$purchaser] = approvalMember(CompanyRole::Purchaser, 500.0);
    $cart = approvalCart($purchaser, 600.0);

    $plugin = Plugin::getInstance();
    Craft::$app->getPlugins()->savePluginSettings($plugin, ['enableApprovals' => false]);

    try {
        // No approval row, gated purchaser, feature off → the backstop stands down entirely.
        expect(refuseApprovalAsSiteRequest($cart))->toBeFalse();
    } finally {
        Craft::$app->getPlugins()->savePluginSettings($plugin, ['enableApprovals' => true]);
    }
});

it('lets a buyer edit a pending-approval cart when the feature toggle is off', function () {
    [$purchaser, $company] = approvalMember(CompanyRole::Purchaser, 500.0);
    $cart = approvalCart($purchaser, 600.0);
    insertApprovalRow($cart->id, $company->id, ApprovalStatus::Pending->value, $purchaser->id, 500.0);

    $plugin = Plugin::getInstance();
    Craft::$app->getPlugins()->savePluginSettings($plugin, ['enableApprovals' => false]);

    try {
        $reloaded = Order::find()->id($cart->id)->status(null)->one();
        $lineItem = $reloaded->getLineItems()[0];
        $lineItem->qty = (int) $lineItem->qty + 3;
        $reloaded->setLineItems([$lineItem]);

        // Feature off → the mutation veto is disarmed. A full successful save cannot be driven under
        // a faked console site request (Commerce reaches getIsSecureConnection), so the save is
        // allowed to proceed into that pipeline and the resulting throw is swallowed; what matters is
        // that the BEFORE_SAVE guard did NOT veto — it left no lineItems error behind.
        $error = 'unset';
        asSiteRequest(function () use ($reloaded, &$error) {
            try {
                craftApp()->getElements()->saveElement($reloaded);
            } catch (Throwable) {
                // Proceeded past our guard into Commerce's own save; not the guard's doing.
            }

            $error = $reloaded->getFirstError('lineItems');
        });

        expect($error)->toBeNull();
    } finally {
        Craft::$app->getPlugins()->savePluginSettings($plugin, ['enableApprovals' => true]);
    }
});

it('vetoes a line-item addition on a resumed APPROVED cart (post-approval inflation)', function () {
    // C3: after approval the mutation veto must stay armed on the approved-but-not-completed order,
    // so a purchaser handed the order back via resumeCheckout cannot inflate it past what was signed
    // off before it completes.
    [$purchaser, $company] = approvalMember(CompanyRole::Purchaser, 500.0);
    $cart = approvalCart($purchaser, 600.0);
    insertApprovalRow($cart->id, $company->id, ApprovalStatus::Approved->value, $purchaser->id, 500.0);

    $reloaded = Order::find()->id($cart->id)->status(null)->one();
    $lineItem = $reloaded->getLineItems()[0];
    $lineItemId = $lineItem->id;
    $storedQty = (int) $lineItem->qty;
    $lineItem->qty = $storedQty + 5;
    $reloaded->setLineItems([$lineItem]);

    $saved = null;
    asSiteRequest(function () use ($reloaded, &$saved) {
        $saved = craftApp()->getElements()->saveElement($reloaded);
    });

    expect($saved)->toBeFalse()
        ->and($reloaded->getFirstError('lineItems'))->toBe('This cart is awaiting approval and cannot be modified.')
        ->and(storedLineItemQty($lineItemId))->toBe($storedQty);
});

it('still allows a non-line-item save on an approved cart', function () {
    // Address/gateway/email edits never change the line-item set, so the freeze leaves them free.
    [$purchaser, $company] = approvalMember(CompanyRole::Purchaser, 500.0);
    $cart = approvalCart($purchaser, 600.0);
    insertApprovalRow($cart->id, $company->id, ApprovalStatus::Approved->value, $purchaser->id, 500.0);

    $reloaded = Order::find()->id($cart->id)->status(null)->one();
    $reloaded->email = 'resume_' . uniqid() . '@example.test';

    // The line-item set is unchanged, so the freeze stands down and leaves no lineItems error (the
    // save itself cannot finish under a faked console site request — see the note above).
    $error = 'unset';
    asSiteRequest(function () use ($reloaded, &$error) {
        try {
            craftApp()->getElements()->saveElement($reloaded);
        } catch (Throwable) {
            // Proceeded past our guard into Commerce's own save; not the guard's doing.
        }

        $error = $reloaded->getFirstError('lineItems');
    });

    expect($error)->toBeNull();
});

it('short-circuits the approvals feature gate when the toggle is off', function () {
    $probe = new ApprovalsFeatureGateProbe('approvals', Plugin::getInstance());

    expect($probe->gate('enableApprovals'))->toBeNull();

    $plugin = Plugin::getInstance();
    Craft::$app->getPlugins()->savePluginSettings($plugin, ['enableApprovals' => false]);

    try {
        $response = $probe->gate('enableApprovals');

        expect($response)->not->toBeNull()
            ->and($response->data['message'])->toBe('This feature is not enabled.');
    } finally {
        Craft::$app->getPlugins()->savePluginSettings($plugin, ['enableApprovals' => true]);
    }
});
