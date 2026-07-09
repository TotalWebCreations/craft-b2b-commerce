<?php

namespace totalwebcreations\b2bcommerce\modules\approvals\services;

use Craft;
use craft\commerce\elements\Order;
use craft\commerce\Plugin as Commerce;
use craft\db\Query;
use craft\elements\User;
use craft\helpers\Db;
use craft\helpers\UrlHelper;
use DateTime;
use DateTimeZone;
use totalwebcreations\b2bcommerce\elements\Approval;
use totalwebcreations\b2bcommerce\elements\Company;
use totalwebcreations\b2bcommerce\enums\ApprovalStatus;
use totalwebcreations\b2bcommerce\enums\CompanyRole;
use totalwebcreations\b2bcommerce\gateways\InvoiceGateway;
use totalwebcreations\b2bcommerce\helpers\Money;
use totalwebcreations\b2bcommerce\Plugin;
use yii\base\Component;
use yii\base\Exception;
use yii\base\InvalidArgumentException;

class Approvals extends Component
{
    /**
     * Whether the actor's order must be held for a company approver before it can be placed.
     *
     * Only a purchaser is ever gated: approvers and admins can place orders directly, and a
     * user with no company (or a company not approved to order) has no approval flow at all.
     * With a threshold of null the company runs no approval gate; a threshold of 0.0 gates
     * every order. Otherwise the order total is compared STRICTLY greater than the threshold,
     * so an order exactly at the threshold is placed directly and does not need approval.
     * Additionally arms on the lowest configured approval tier band (>=, compared money-safe via
     * {@see Money::withinLimit()} so a float order total never misses the boundary by a rounding
     * hair — the exact same comparison ApprovalTiers::requiredLevels() uses, so the two can never
     * disagree at a tier boundary), so tiers alone can drive the gate; a company with no tiers is
     * unaffected and keeps the exact single-threshold behaviour.
     */
    public function needsApproval(Order $order, User $actor): bool
    {
        $members = Plugin::getInstance()->companyMembers;
        $company = $members->getCompanyForUser($actor->id);

        if ($company === null) {
            return false;
        }

        if ($company->companyStatus !== Company::STATUS_APPROVED) {
            return false;
        }

        if ($members->getRoleForUser($actor->id, $company->id) !== CompanyRole::Purchaser) {
            return false;
        }

        $threshold = $company->approvalThreshold;
        $lowestTierMin = Plugin::getInstance()->approvalTiers->lowestMinAmount($company->id);

        // No single threshold AND no tiers means the company runs no approval gate at all.
        if ($threshold === null && $lowestTierMin === null) {
            return false;
        }

        // A zero threshold gates every order regardless of tiers (unchanged legacy shortcut).
        if ($threshold === 0.0) {
            return true;
        }

        $total = (float) $order->getTotalPrice();

        // Legacy gate: STRICTLY greater than the single threshold (an order exactly at the threshold
        // is placed directly). Preserved so a tier-less company behaves exactly as before.
        if ($threshold !== null && $total > $threshold) {
            return true;
        }

        // Tier gate: at/above the lowest configured band, compared money-safe (bccomp, scale 4) via
        // Money::withinLimit() — the identical comparison ApprovalTiers::requiredLevels() uses — so a
        // merchant can arm approval purely through tiers without also setting approvalThreshold, and
        // the two methods can never disagree at a boundary because of float drift.
        return $lowestTierMin !== null && Money::withinLimit($lowestTierMin, $total);
    }

    /**
     * Submits the actor's cart for company approval: records a pending approval row, notifies
     * every approver and admin of the company, then forgets the session cart so the order
     * survives untouched as the pending-approval order while the buyer keeps a fresh cart.
     * Mirrors Quotes::requestQuote, including the detach-and-survive hand-off.
     *
     * Guards, in order:
     *   - the cart is not empty;
     *   - the order actually needs approval for this actor — an explicit submit of an order that
     *     does not require approval is confusing, so it is refused rather than silently accepted;
     *   - the order has no approval row yet (the row's primary key is orderId, one per order); the
     *     refusal message is tailored to the existing row's status — pending is still awaiting a
     *     decision, declined is terminal (start a fresh cart), approved is ready to resume;
     *   - the order is not part of an OPEN quote (requested or sent) — while a quote is open its own
     *     flow governs completion, so the two flows are mutually exclusive there. An ACCEPTED quote
     *     is the deliberate exception: a purchaser who accepts an over-threshold quote MUST be able
     *     to submit that order for approval, otherwise the accepted quote (line-item-frozen) and the
     *     completion backstop (which demands an approved row) deadlock and the order can never be
     *     placed. So the guard refuses only open quotes, never an accepted one.
     *
     * The thresholdAmount stored is a snapshot of the company's approvalThreshold at submit time,
     * so a later threshold change never rewrites the reason this order was held.
     */
    public function submitForApproval(Order $cart, User $actor): void
    {
        if ($cart->getLineItems() === []) {
            throw new InvalidArgumentException(
                Craft::t('b2b-commerce', 'Your cart is empty.')
            );
        }

        if (!$this->needsApproval($cart, $actor)) {
            throw new InvalidArgumentException(
                Craft::t('b2b-commerce', 'This order does not require approval.')
            );
        }

        $existing = $this->approvalRow((int) $cart->id);

        if ($existing !== null) {
            throw new InvalidArgumentException($this->existingRowMessage($existing));
        }

        if (Plugin::getInstance()->quotes->orderHasOpenQuoteRow((int) $cart->id)) {
            throw new InvalidArgumentException(
                Craft::t('b2b-commerce', 'This cart is part of a quote.')
            );
        }

        $company = Plugin::getInstance()->companyMembers->getCompanyForUser($actor->id);

        // An approval is a Craft element: saving it creates the elements row and its afterSave upserts
        // the b2b_approvals columns. orderId remains the business key the enforcement guards read; the
        // element only adds identity around the row. This is an Approval element save, never an Order
        // save, so the order-scoped buyer-mutation veto (Order::EVENT_BEFORE_SAVE) does not apply. The
        // thresholdAmount stored is a snapshot of the company's approvalThreshold at submit time.
        $approval = new Approval();
        $approval->orderId = (int) $cart->id;
        $approval->companyId = $company->id;
        $approval->approvalStatus = ApprovalStatus::Pending->value;
        $approval->requestedById = $actor->id;
        $approval->thresholdAmount = $company->approvalThreshold;

        // The element save and the step-ladder inserts must land atomically: saveElement() commits its
        // own internal transaction, and createSteps() loops plain inserts with no transaction of its
        // own. Without this wrapper, a step insert failing mid-loop would leave a durably committed
        // Approval element + Pending row with an incomplete ladder, un-approvable and un-resubmittable
        // (the "already awaiting approval" guard above would block any retry). Wrapping both writes in
        // one transaction means ANY step insert throwing rolls back the approval element and row too,
        // leaving the submit cleanly re-triggerable. Craft's saveElement() begins its own transaction;
        // Yii nests it as a savepoint inside this outer one, so wrapping is safe. Side effects that must
        // never be undone by a DB rollback — the notification email and the session cart mutation — stay
        // OUTSIDE this transaction and only run once it has committed successfully.
        Craft::$app->getDb()->transaction(function () use ($approval, $cart): void {
            if (!Craft::$app->getElements()->saveElement($approval)) {
                throw new Exception(implode(' ', $approval->getFirstErrors()));
            }

            $this->createSteps($approval, $cart);
        });

        $this->notifyApprovers($company, $cart);

        Commerce::getInstance()->getCarts()->forgetCart();
    }

    /**
     * The pure approval-gate decision, shared by the plugin's two enforcement layers so neither
     * re-derives it: the payment-time gate (PaymentGate::paymentRefusalReason, which refuses the
     * charge up front) and the completion backstop (enforceApprovalBeforeCompletion, the net).
     * Returns true when the order must be held — its customer still needs approval (a purchaser
     * over the company threshold) and it carries no approved approval row. Carries NO request
     * scoping: each caller applies its own storefront/console guard. Disarmed entirely when the
     * enableApprovals toggle is off (see enforceApprovalBeforeCompletion for the full rationale).
     */
    public function mustHoldForApproval(Order $order): bool
    {
        if (!Plugin::getInstance()->getSettings()->enableApprovals) {
            return false;
        }

        $customer = $order->getCustomer();

        if ($customer === null) {
            return false;
        }

        if (!$this->needsApproval($order, $customer)) {
            return false;
        }

        return !$this->orderHasApprovedApproval((int) $order->id);
    }

    /**
     * Hard completion backstop for the approval gate, wired on EVENT_BEFORE_COMPLETE_ORDER.
     *
     * Refuses to place an order whose customer still needs approval (a purchaser over the
     * company's threshold) unless an approved approval row exists for the order. This is the
     * permission-to-order gate; the buyer-facing submit flow is the sanctioned way past it.
     *
     * This is the LATER of two coexisting layers. The payment-time gate (PaymentGate) refuses the
     * charge up front on EVENT_BEFORE_PROCESS_PAYMENT so a card is never charged; this backstop
     * stays armed as the net for the paths that never run a payment call — a zero-payment or free
     * order, an approver placing an approved invoice order directly, and any other markAsComplete()
     * that does not go through Payments::processPayment(). Both layers share mustHoldForApproval().
     *
     * Design decisions, and how the completion matrix resolves:
     *   - NO paid-order exemption (unlike the account-status backstop). Approval governs
     *     PERMISSION to order, not credit exposure; a captured payment does not grant a
     *     purchaser authority they never had. Only an approved row lets the order through.
     *   - Applies equally to accepted-quote orders. The threshold protects the COMPANY, not
     *     the sales channel: a quote a purchaser accepts is still the purchaser committing the
     *     company's money, so an accepted quote whose total clears the threshold must also carry
     *     an approved approval before it completes. This stacks cleanly with the quote-completion
     *     veto (Quotes::enforceAcceptedBeforeCompletion): that guard demands the quote be
     *     accepted, this one demands the approval be approved, and an order that is BOTH accepted
     *     and approved satisfies both and completes. The buyer path for such an order is to accept
     *     the quote and then submit the accepted-quote order for approval (submitForApproval, which
     *     refuses only OPEN quotes, never an accepted one); this message never strands them, it
     *     tells them plainly what is missing.
     *
     * Completion matrix for a purchaser whose order clears the threshold:
     *   no approval row      -> refused (submit for approval first)
     *   pending approval     -> refused (still awaiting a decision)
     *   approved approval    -> passes
     * An admin or approver, or any order under the threshold, never triggers this gate at all.
     *
     * Disarmed entirely when the enableApprovals feature toggle is off: with the whole approval
     * feature switched off there is no gate to enforce, so a gated purchaser completes as if no
     * threshold existed (the reconciler stays armed but is harmless — a still-pending row it meets
     * is simply auto-resolved on completion). This mirrors the controller-level feature gate.
     *
     * Storefront-scoped like the other completion guards; console and control-panel completions
     * are the deliberate merchant override (a purchaser's order is placed either by an approver
     * approving it, or by an admin completing it from the control panel).
     */
    public function enforceApprovalBeforeCompletion(Order $order): void
    {
        $request = Craft::$app->getRequest();

        // Storefront-only guard: never intervene in console or control-panel completions.
        if ($request->getIsConsoleRequest() || $request->getIsCpRequest()) {
            return;
        }

        // mustHoldForApproval folds in the enableApprovals toggle, the missing-customer short
        // circuit, the needsApproval check and the approved-row exemption — the exact decision the
        // payment-time gate reuses.
        if (!$this->mustHoldForApproval($order)) {
            return;
        }

        $message = Craft::t('b2b-commerce', 'This order requires approval before it can be placed.');

        // The error MUST sit on an order attribute before throwing so Commerce's
        // CartController::_returnCart() sees the persisted error, its $cart->validate($attributes,
        // false) fails on it, and the half-completed order is never re-saved as completed (see
        // OrderCompanyLink::enforcePurchasePolicy for the full rationale). EVENT_BEFORE_COMPLETE_ORDER
        // is not cancelable, so throwing is what aborts completion.
        $order->addError('customerId', $message);

        throw new Exception($message);
    }

    /**
     * Whether the order's line items must stay frozen against buyer mutation because of its
     * approval: a PENDING or APPROVED row on an order that is not yet completed. Mirrors
     * Quotes::orderHasLineItemFrozenQuote, and for the same reason it spans both live states:
     *   - pending freezes the exact snapshot the approver is deciding on, so a buyer cannot inflate
     *     the order while it awaits a decision;
     *   - approved keeps the freeze through resume-checkout, so a buyer who is handed the approved
     *     order back as their cart cannot then add line items and inflate it past the amount the
     *     approver signed off on before it completes. approve()'s own direct completion never trips
     *     this (markAsComplete does not change the line-item set), and resumeCheckout only re-adopts
     *     the order as the session cart without saving it, so neither the plugin's own paths fire it.
     * The completed-order exclusion mirrors the quote predicate: once an order completes the freeze
     * is spent, and a row that outlives completion (a threshold relaxed after submit, then
     * reconciled by reconcilePendingApproval on AFTER_COMPLETE) must never keep freezing a placed
     * order's line items.
     */
    public function orderHasLineItemFrozenApproval(int $orderId): bool
    {
        return (new Query())
            ->from(['a' => '{{%b2b_approvals}}'])
            ->innerJoin(['o' => '{{%commerce_orders}}'], '[[o.id]] = [[a.orderId]]')
            ->where([
                'a.orderId' => $orderId,
                'a.status' => [
                    ApprovalStatus::Pending->value,
                    ApprovalStatus::Approved->value,
                ],
            ])
            ->andWhere(['not', ['o.isCompleted' => true]])
            ->exists();
    }

    /**
     * Approves a pending approval request and hands the order onward, honouring the four-eyes
     * principle: an approver may never approve their own submission.
     *
     * Guard order is deliberate and oracle-free (see requireResolvableRow): a missing row and a
     * request that belongs to another company both read as 'not available', so a cross-company
     * probe on a guessed order id can never distinguish them, nor learn a row's status. Only an
     * authorised approver of the owning company ever sees the 'already resolved' terminal message.
     *
     * On approval the row flips to approved with resolvedById set, then one of two things happens:
     *   - pay on account within the company's credit room  -> the order is placed immediately on the
     *     requester's behalf (markAsComplete), because an approved invoice order needs no further
     *     buyer action; the requester is mailed that it has been placed. The completion runs the full
     *     site-request handler stack (the approval backstop passes on the just-approved row; the
     *     credit enforcer runs normally). Should completion be refused — the enforcer throws, or
     *     markAsComplete returns false — the approval is NOT rolled back (it stays approved, which is
     *     honest: the approver did approve) and the requester is mailed the resume variant instead,
     *     so they can retry checkout once the credit position allows it.
     *   - any other case (non-invoice gateway, or no credit room) -> the requester is mailed a
     *     resume-checkout instruction; they finish the order themselves via resumeCheckout().
     *
     * A tier-less approval (no step ladder) is resolved by one approver, exactly as before the
     * multi-level chain existed (legacyApprove). A laddered approval instead resolves its currently
     * open step and only flips the aggregate row once the last required step approves (ladderApprove).
     */
    public function approve(int $orderId, User $approver): void
    {
        $row = $this->requireResolvableRow($orderId, $approver);

        $steps = $this->stepsForApproval((int) $row['id']);

        if ($steps === []) {
            $this->legacyApprove($orderId, $approver, $row);

            return;
        }

        $this->ladderApprove($orderId, $approver, $row, $steps);
    }

    /**
     * The legacy single-approval flip: an approval with no step ladder is resolved by one approver,
     * exactly as before the multi-level chain existed. The status = pending guard in the WHERE plus
     * the affected-row check close the concurrent-resolution race.
     *
     * @param array<string, mixed> $row
     */
    private function legacyApprove(int $orderId, User $approver, array $row): void
    {
        $affected = Db::update('{{%b2b_approvals}}', [
            'status' => ApprovalStatus::Approved->value,
            'resolvedById' => $approver->id,
        ], ['orderId' => $orderId, 'status' => ApprovalStatus::Pending->value]);

        if ($affected === 0) {
            throw new InvalidArgumentException(
                Craft::t('b2b-commerce', 'This approval request has already been resolved.')
            );
        }

        $this->finalizeApproval($orderId, $approver, $row);
    }

    /**
     * Resolves the currently-open step of a laddered approval. Steps open strictly in level order
     * (openStep returns the lowest still-pending step), so approving here advances the ladder by one
     * rung. Four-eyes is preserved per step: requireResolvableRow already refused the submitter, and
     * approverAlreadyResolvedStep refuses an approver who has already cleared another rung of THIS
     * approval, so no single person can clear two distinct levels. When the last required step
     * approves, the aggregate b2b_approvals row flips to approved and the shared finalize path runs
     * (direct on-account placement or resume-checkout mail). While rungs remain, the aggregate stays
     * pending — so the completion backstop, payment gate and buyer-mutation freeze (all of which read
     * the aggregate status) keep holding the order until the whole chain is signed.
     *
     * @param array<string, mixed> $row
     * @param array<int, array<string, mixed>> $steps
     */
    private function ladderApprove(int $orderId, User $approver, array $row, array $steps): void
    {
        $approvalId = (int) $row['id'];
        $openStep = $this->openStep($steps);

        if ($openStep === null) {
            throw new InvalidArgumentException(
                Craft::t('b2b-commerce', 'This approval request has already been resolved.')
            );
        }

        if ($this->approverAlreadyResolvedStep($approvalId, (int) $approver->id)) {
            throw new InvalidArgumentException(
                Craft::t('b2b-commerce', 'You have already approved a step of this order.')
            );
        }

        if (!$this->approverEligibleForStep($approver, $openStep, (int) $row['companyId'], $row['requestedById'] !== null ? (int) $row['requestedById'] : null)) {
            throw new InvalidArgumentException(
                Craft::t('b2b-commerce', 'This approval request is not available.')
            );
        }

        // Concurrency guard: only flip a step that is still pending, so two approvers racing the same
        // open rung cannot both resolve it.
        $affected = Db::update('{{%b2b_approval_steps}}', [
            'status' => ApprovalStatus::Approved->value,
            'resolvedById' => $approver->id,
            'dateResolved' => Db::prepareDateForDb(new DateTime()),
        ], ['id' => (int) $openStep['id'], 'status' => ApprovalStatus::Pending->value]);

        if ($affected === 0) {
            throw new InvalidArgumentException(
                Craft::t('b2b-commerce', 'This approval request has already been resolved.')
            );
        }

        // More rungs to sign: leave the aggregate pending and notify the next eligible approvers.
        if ($this->pendingStepCount($approvalId) > 0) {
            $order = Order::find()->id($orderId)->status(null)->one();
            $company = Plugin::getInstance()->companyMembers->getCompanyById((int) $row['companyId']);

            if ($order !== null && $company !== null) {
                $this->notifyApprovers($company, $order);
            }

            return;
        }

        // Last rung signed: flip the aggregate approval and run the shared finalize path.
        Db::update('{{%b2b_approvals}}', [
            'status' => ApprovalStatus::Approved->value,
            'resolvedById' => $approver->id,
        ], ['orderId' => $orderId, 'status' => ApprovalStatus::Pending->value]);

        $this->finalizeApproval($orderId, $approver, $row);
    }

    /**
     * The shared post-approval hand-off, reused by the legacy flip and the last-rung ladder flip:
     * busts the element cache, then either places an on-account order directly (invoice gateway with
     * credit room) or mails the requester a resume-checkout instruction. Never rolls the approval back
     * on a failed direct placement.
     *
     * @param array<string, mixed> $row
     */
    private function finalizeApproval(int $orderId, User $approver, array $row): void
    {
        $this->reflectStatusOnElement();

        $order = Order::find()->id($orderId)->status(null)->one();

        if ($order === null) {
            Craft::warning("Approved approval {$orderId} has no order to place or notify", 'b2b-commerce');

            return;
        }

        $requester = $this->requester($row);

        if ($this->canCompleteDirectly($order, (int) $row['companyId']) && $this->completeDirectly($order)) {
            $this->notifyApproved($order, $requester, placed: true);

            return;
        }

        $this->notifyApproved($order, $requester, placed: false);
    }

    /**
     * The currently-open step of a laddered approval: the lowest-level step still pending. Because a
     * decline short-circuits the whole approval to declined, a still-pending aggregate row always has
     * exactly one open rung (all lower rungs approved), so returning the first pending step in level
     * order is the sequential gate.
     *
     * @param array<int, array<string, mixed>> $steps
     * @return array<string, mixed>|null
     */
    private function openStep(array $steps): ?array
    {
        foreach ($steps as $step) {
            if ($step['status'] === ApprovalStatus::Pending->value) {
                return $step;
            }
        }

        return null;
    }

    private function pendingStepCount(int $approvalId): int
    {
        return (int) (new Query())
            ->from('{{%b2b_approval_steps}}')
            ->where(['approvalId' => $approvalId, 'status' => ApprovalStatus::Pending->value])
            ->count();
    }

    /**
     * Whether the approver has already resolved any rung of this approval — the four-eyes-across-steps
     * guard, so one person can never single-handedly clear two distinct required levels.
     */
    private function approverAlreadyResolvedStep(int $approvalId, int $userId): bool
    {
        return (new Query())
            ->from('{{%b2b_approval_steps}}')
            ->where(['approvalId' => $approvalId, 'resolvedById' => $userId])
            ->exists();
    }

    /**
     * Whether the approver may resolve the given open step. The same-company + canApproveOrders and
     * not-the-submitter checks are already enforced by requireResolvableRow; this adds the
     * department-scope routing for a departmentScoped tier. In phase 18 the department lookup is
     * unavailable, so a departmentScoped step safely falls back to any company approver.
     *
     * @param array<string, mixed> $step
     */
    private function approverEligibleForStep(User $approver, array $step, int $companyId, ?int $requesterId): bool
    {
        $tier = Plugin::getInstance()->approvalTiers->getTier($companyId, (int) $step['level']);

        if ($tier === null || !(bool) $tier['departmentScoped']) {
            return true;
        }

        $eligibleIds = $this->departmentScopedApproverIds($companyId, $requesterId ?? 0);

        // Departments unavailable (phase 18) -> company-level fallback: any approver may sign.
        if ($eligibleIds === null) {
            return true;
        }

        return in_array((int) $approver->id, $eligibleIds, true);
    }

    /**
     * Phase 19 seam. Resolves the user ids eligible to approve a department-scoped step for the
     * requester's department, or null when department routing does not apply. Until the departments
     * feature ships (its table is absent), this returns null so a departmentScoped tier falls back to
     * any company approver — phase 18 never blocks on department routing. Phase 19 fills the body.
     *
     * @return array<int, int>|null
     */
    private function departmentScopedApproverIds(int $companyId, int $requesterId): ?array
    {
        if (!Craft::$app->getDb()->tableExists('{{%b2b_departments}}')) {
            return null;
        }

        // Phase 19 implements the real department-approver resolution here.
        return null;
    }

    /**
     * Declines a pending approval request, recording the reason and notifying the requester.
     * Shares the same oracle-free guard chain as approve(); additionally requires a non-empty
     * reason so the requester always learns why. The reason is validated only after authorisation,
     * so it never becomes a probe channel. For a laddered approval, the currently-open step is
     * marked declined too; a decline anywhere short-circuits the whole approval, so the remaining
     * rungs are never reached.
     */
    public function decline(int $orderId, User $approver, string $reason): void
    {
        $row = $this->requireResolvableRow($orderId, $approver);

        $reason = trim($reason);

        if ($reason === '') {
            throw new InvalidArgumentException(
                Craft::t('b2b-commerce', 'A reason is required to decline an order.')
            );
        }

        // Mark the currently-open step declined first; a decline anywhere short-circuits the whole
        // approval (the aggregate flip below), so higher rungs are simply never reached.
        $steps = $this->stepsForApproval((int) $row['id']);

        if ($steps !== []) {
            $openStep = $this->openStep($steps);

            if ($openStep !== null) {
                Db::update('{{%b2b_approval_steps}}', [
                    'status' => ApprovalStatus::Declined->value,
                    'resolvedById' => $approver->id,
                    'reason' => $reason,
                    'dateResolved' => Db::prepareDateForDb(new DateTime()),
                ], ['id' => (int) $openStep['id'], 'status' => ApprovalStatus::Pending->value]);
            }
        }

        // status = pending in the WHERE + the affected-row check close the concurrent-resolution
        // race, exactly as approve() does.
        $affected = Db::update('{{%b2b_approvals}}', [
            'status' => ApprovalStatus::Declined->value,
            'resolvedById' => $approver->id,
            'reason' => $reason,
        ], ['orderId' => $orderId, 'status' => ApprovalStatus::Pending->value]);

        if ($affected === 0) {
            throw new InvalidArgumentException(
                Craft::t('b2b-commerce', 'This approval request has already been resolved.')
            );
        }

        $this->reflectStatusOnElement();

        $order = Order::find()->id($orderId)->status(null)->one();

        if ($order === null) {
            Craft::warning("Declined approval {$orderId} has no order to notify", 'b2b-commerce');

            return;
        }

        $this->notifyDeclined($order, $this->requester($row), $reason);
    }

    /**
     * Re-adopts an approved order as the actor's active session cart so they can finish checkout,
     * exactly as Quotes::acceptByToken hands a quote order back to the session (forgetCart +
     * setSessionCartNumber). ONLY the requester may resume — it is their cart — so a colleague,
     * even a same-company approver, is refused with the same oracle-free 'not available' message a
     * stranger gets. The row must be approved and the order not yet completed; the buyer-mutation
     * save guard is already disarmed for this order (its row is no longer pending).
     */
    public function resumeCheckout(int $orderId, User $actor): Order
    {
        $row = $this->approvalRow($orderId);

        if ($row === null || $row['requestedById'] === null || (int) $row['requestedById'] !== $actor->id) {
            throw new InvalidArgumentException(
                Craft::t('b2b-commerce', 'This approval request is not available.')
            );
        }

        if (ApprovalStatus::from($row['status']) !== ApprovalStatus::Approved) {
            throw new InvalidArgumentException(
                Craft::t('b2b-commerce', 'This order has not been approved.')
            );
        }

        $order = Order::find()->id($orderId)->status(null)->one();

        if ($order === null) {
            throw new InvalidArgumentException(
                Craft::t('b2b-commerce', 'This approval request is not available.')
            );
        }

        if ($order->isCompleted) {
            throw new InvalidArgumentException(
                Craft::t('b2b-commerce', 'This order has already been completed.')
            );
        }

        $carts = Commerce::getInstance()->getCarts();
        $carts->forgetCart();
        $carts->setSessionCartNumber($order->number);

        return $order;
    }

    /**
     * Auto-resolves a still-pending approval row on an order that has just completed. Wired on
     * EVENT_AFTER_COMPLETE_ORDER, AFTER linkCompany, for the threshold-relaxed-after-submit
     * scenario: a purchaser submits an over-threshold order, the merchant then nulls or raises the
     * company threshold, live needsApproval drops to FALSE, so the completion backstop passes even
     * though the row is still pending. The other route here is a merchant override: an admin
     * completes a still-gated purchaser's pending order from the control panel or console (both
     * bypass the storefront backstop), so the order lands completed with its threshold still in
     * force. Neither approved-by-an-approver nor declined would be honest for either case, so the
     * row is flipped to approved with resolvedById = null and an auditable reason that names which
     * route it was — the live needsApproval check distinguishes them. Only a genuinely pending row
     * is touched (a row already resolved by approve/decline is left untouched); the freeze predicate
     * excludes completed orders, so this pending check is queried directly.
     */
    public function reconcilePendingApproval(Order $order): void
    {
        $orderId = (int) $order->id;

        $isPending = (new Query())
            ->from('{{%b2b_approvals}}')
            ->where(['orderId' => $orderId, 'status' => ApprovalStatus::Pending->value])
            ->exists();

        if (!$isPending) {
            return;
        }

        $customer = $order->getCustomer();
        $stillGated = $customer !== null && $this->needsApproval($order, $customer);

        $reason = $stillGated
            ? Craft::t('b2b-commerce', 'Auto-approved: completed via merchant override.')
            : Craft::t('b2b-commerce', 'Auto-approved: the order no longer required approval at completion.');

        Db::update('{{%b2b_approvals}}', [
            'status' => ApprovalStatus::Approved->value,
            'resolvedById' => null,
            'reason' => $reason,
        ], ['orderId' => $orderId]);

        // Resolve any still-pending steps of a laddered approval so none is left dangling on a
        // completed order. They inherit the aggregate's null resolver and the same auditable reason.
        $approvalId = (int) (new Query())
            ->select('id')
            ->from('{{%b2b_approvals}}')
            ->where(['orderId' => $orderId])
            ->scalar();

        Db::update('{{%b2b_approval_steps}}', [
            'status' => ApprovalStatus::Approved->value,
            'resolvedById' => null,
            'reason' => $reason,
            'dateResolved' => Db::prepareDateForDb(new DateTime()),
        ], ['approvalId' => $approvalId, 'status' => ApprovalStatus::Pending->value]);

        $this->reflectStatusOnElement();
    }

    /**
     * Keeps the Approval element index consistent after a direct status write. The status column
     * drives the element query's status sources and colored dots and is read live from the join on
     * every index load, so no resave or search re-index is needed; this only busts any cached element
     * queries so a change is reflected at once. Mirrors Quotes::reflectStatusOnElement.
     */
    private function reflectStatusOnElement(): void
    {
        Craft::$app->getElements()->invalidateCachesForElementType(Approval::class);
    }

    /**
     * Every approval for the control-panel monitoring page, newest first, optionally filtered to a
     * single status. Built without an N+1: one query loads the rows, then the referenced orders,
     * companies, requesters and resolvers are each batch-loaded once and stitched onto the rows.
     * Mirrors Quotes::getQuotesForCp. The control panel is read-only monitoring — approval decisions
     * belong to the customer's own approvers — so this method never mutates, it only reports.
     *
     * @return array<int, array{
     *     orderId: int,
     *     status: string,
     *     companyId: int,
     *     companyName: ?string,
     *     requesterName: ?string,
     *     resolverName: ?string,
     *     thresholdAmount: ?float,
     *     reason: ?string,
     *     dateCreated: ?DateTime,
     *     order: ?Order
     * }>
     */
    public function getApprovalsForCp(?string $status = null): array
    {
        $query = (new Query())
            ->from('{{%b2b_approvals}}')
            ->orderBy(['dateCreated' => SORT_DESC]);

        if ($status !== null) {
            $query->where(['status' => $status]);
        }

        $rows = $query->all();

        if ($rows === []) {
            return [];
        }

        $orders = Order::find()
            ->id(array_column($rows, 'orderId'))
            ->status(null)
            ->indexBy('id')
            ->all();

        // Companies are non-localized elements hosted on the primary site only, so query with site('*').
        $companies = Company::find()
            ->id(array_unique(array_column($rows, 'companyId')))
            ->site('*')
            ->unique()
            ->status(null)
            ->indexBy('id')
            ->all();

        $userIds = array_values(array_filter(array_merge(
            array_column($rows, 'requestedById'),
            array_column($rows, 'resolvedById'),
        )));
        $users = $userIds !== []
            ? User::find()->id($userIds)->status(null)->indexBy('id')->all()
            : [];

        return array_map(function (array $row) use ($orders, $companies, $users): array {
            $requesterId = $row['requestedById'] !== null ? (int) $row['requestedById'] : null;
            $requester = $requesterId !== null ? ($users[$requesterId] ?? null) : null;

            $resolverId = $row['resolvedById'] !== null ? (int) $row['resolvedById'] : null;
            $resolver = $resolverId !== null ? ($users[$resolverId] ?? null) : null;

            return [
                'orderId' => (int) $row['orderId'],
                'status' => (string) $row['status'],
                'companyId' => (int) $row['companyId'],
                'companyName' => ($companies[(int) $row['companyId']] ?? null)?->title,
                'requesterName' => $requester !== null ? ($requester->fullName ?: $requester->email) : null,
                'resolverName' => $resolver !== null ? ($resolver->fullName ?: $resolver->email) : null,
                'thresholdAmount' => $row['thresholdAmount'] !== null ? (float) $row['thresholdAmount'] : null,
                'reason' => $row['reason'] !== null ? (string) $row['reason'] : null,
                'dateCreated' => $this->toUtcDateTime($row['dateCreated']),
                'order' => $orders[(int) $row['orderId']] ?? null,
            ];
        }, $rows);
    }

    /**
     * Every pending approval of the company for its approver queue, newest first, batch-loaded with
     * no N+1: one row query, then the orders and requesters are each loaded once and stitched on.
     *
     * @return array<int, array{
     *     orderId: int,
     *     reference: ?string,
     *     total: ?float,
     *     currency: ?string,
     *     requesterName: ?string,
     *     dateCreated: ?DateTime
     * }>
     */
    public function getPendingForCompany(int $companyId): array
    {
        $rows = (new Query())
            ->from('{{%b2b_approvals}}')
            ->where(['companyId' => $companyId, 'status' => ApprovalStatus::Pending->value])
            ->orderBy(['dateCreated' => SORT_DESC])
            ->all();

        if ($rows === []) {
            return [];
        }

        $orders = Order::find()
            ->id(array_column($rows, 'orderId'))
            ->status(null)
            ->indexBy('id')
            ->all();

        $requesterIds = array_values(array_filter(array_column($rows, 'requestedById')));
        $requesters = $requesterIds !== []
            ? User::find()->id($requesterIds)->status(null)->indexBy('id')->all()
            : [];

        return array_map(function (array $row) use ($orders, $requesters): array {
            $order = $orders[(int) $row['orderId']] ?? null;
            $requesterId = $row['requestedById'] !== null ? (int) $row['requestedById'] : null;
            $requester = $requesterId !== null ? ($requesters[$requesterId] ?? null) : null;

            return [
                'orderId' => (int) $row['orderId'],
                'reference' => $order !== null ? ($order->reference ?: $order->getShortNumber()) : null,
                'total' => $order?->getTotalPrice(),
                'currency' => $order?->currency,
                'requesterName' => $requester !== null ? ($requester->fullName ?: $requester->email) : null,
                'dateCreated' => $this->toUtcDateTime($row['dateCreated']),
            ];
        }, $rows);
    }

    /**
     * Every approval request the given user raised, any status, newest first, for their own
     * overview. Carries the decision reason and enough order data to rebuild a resume action on an
     * approved request. Batch-loaded with no N+1.
     *
     * @return array<int, array{
     *     orderId: int,
     *     status: string,
     *     reference: ?string,
     *     total: ?float,
     *     currency: ?string,
     *     reason: ?string,
     *     dateCreated: ?DateTime
     * }>
     */
    public function getRequestsForRequester(int $userId): array
    {
        $rows = (new Query())
            ->from('{{%b2b_approvals}}')
            ->where(['requestedById' => $userId])
            ->orderBy(['dateCreated' => SORT_DESC])
            ->all();

        if ($rows === []) {
            return [];
        }

        $orders = Order::find()
            ->id(array_column($rows, 'orderId'))
            ->status(null)
            ->indexBy('id')
            ->all();

        return array_map(function (array $row) use ($orders): array {
            $order = $orders[(int) $row['orderId']] ?? null;

            return [
                'orderId' => (int) $row['orderId'],
                'status' => (string) $row['status'],
                'reference' => $order !== null ? ($order->reference ?: $order->getShortNumber()) : null,
                'total' => $order?->getTotalPrice(),
                'currency' => $order?->currency,
                'reason' => $row['reason'] !== null ? (string) $row['reason'] : null,
                'dateCreated' => $this->toUtcDateTime($row['dateCreated']),
            ];
        }, $rows);
    }

    /**
     * Excludes every order that carries an approval row from Commerce's inactive-cart purge
     * query, exactly as Quotes::excludeQuoteOrdersFromPurge does for quote orders. The purge
     * (Carts::purgeIncompleteCarts, on by default, 90 days) deletes non-completed orders and the
     * CASCADE FK would wipe their b2b_approvals rows — silently losing pending approvals and all
     * terminal approval history. Approvals are business records, so keep the whole set. The purge
     * query selects orders.id.
     */
    public function excludeApprovalOrdersFromPurge(Query $query): void
    {
        $query->andWhere([
            'not', [
                'orders.id' => (new Query())
                    ->select(['orderId'])
                    ->from('{{%b2b_approvals}}'),
            ],
        ]);
    }

    /**
     * Resolves and authorises an approval request for a resolution action (approve/decline),
     * running the oracle-free guard chain in order:
     *   1. the row must exist                  -> 'not available' (no oracle: same as wrong company);
     *   2. the actor must be an approver of the row's OWNING company (same-company + canApproveOrders)
     *                                          -> 'not available' (a cross-company probe, or a
     *                                             same-company purchaser without the role, learns
     *                                             nothing about the row, not even that it exists);
     *   3. the row must still be pending       -> 'already resolved' (only an authorised approver
     *                                             ever reaches this terminal message);
     *   4. the actor must not be the requester -> 'cannot approve your own order' (four-eyes: an
     *                                             approver may never rubber-stamp their own submit).
     *
     * @return array<string, mixed>
     */
    private function requireResolvableRow(int $orderId, User $approver): array
    {
        $row = $this->approvalRow($orderId);

        if ($row === null) {
            throw new InvalidArgumentException(
                Craft::t('b2b-commerce', 'This approval request is not available.')
            );
        }

        $members = Plugin::getInstance()->companyMembers;
        $company = $members->getCompanyForUser($approver->id);
        $role = $company !== null ? $members->getRoleForUser($approver->id, $company->id) : null;

        if ($company === null || (int) $row['companyId'] !== $company->id || $role === null || !$role->canApproveOrders()) {
            throw new InvalidArgumentException(
                Craft::t('b2b-commerce', 'This approval request is not available.')
            );
        }

        if (ApprovalStatus::from($row['status']) !== ApprovalStatus::Pending) {
            throw new InvalidArgumentException(
                Craft::t('b2b-commerce', 'This approval request has already been resolved.')
            );
        }

        if ($row['requestedById'] !== null && (int) $row['requestedById'] === $approver->id) {
            throw new InvalidArgumentException(
                Craft::t('b2b-commerce', 'You cannot approve your own order.')
            );
        }

        return $row;
    }

    /**
     * Whether an approved order should be placed immediately rather than handed back for the
     * requester to check out: it pays on account (an InvoiceGateway) and the company still has the
     * credit room to cover the order's outstanding balance. Any other case falls to the
     * resume-checkout mail.
     */
    private function canCompleteDirectly(Order $order, int $companyId): bool
    {
        if (!$order->getGateway() instanceof InvoiceGateway) {
            return false;
        }

        return Plugin::getInstance()->creditBalance->canCover($companyId, (float) $order->getOutstandingBalance());
    }

    /**
     * Places the order on the requester's behalf. Any refusal — the credit enforcer throwing on a
     * site request, or markAsComplete returning false — is swallowed to false so the caller can fall
     * back to the resume mail; the approval itself is never rolled back on a failed placement.
     */
    private function completeDirectly(Order $order): bool
    {
        try {
            return $order->markAsComplete();
        } catch (\Throwable $exception) {
            Craft::warning(
                "Direct completion of approved order {$order->id} was refused: {$exception->getMessage()}",
                'b2b-commerce'
            );

            return false;
        }
    }

    /**
     * Mails the requester that their order was approved. When placed is true the order is already
     * completed, so the mail says so and needs no call to action; otherwise it carries a
     * resume-checkout instruction (payment is still required). A missing requester is logged, not
     * fatal — the approval and any completion already stand.
     */
    private function notifyApproved(Order $order, ?User $requester, bool $placed): void
    {
        if ($requester === null) {
            Craft::warning("Approval {$order->id} has no requester to notify of approval", 'b2b-commerce');

            return;
        }

        $reference = $order->reference ?: $order->getShortNumber();

        $instructions = $placed
            ? Craft::t('b2b-commerce', 'It has been placed — no further action is needed.')
            : Craft::t('b2b-commerce', 'Payment is still required. Please sign in and complete checkout to place it: {url}', [
                'url' => UrlHelper::siteUrl('b2b/approvals'),
            ]);

        $sent = Craft::$app->getMailer()
            ->composeFromKey('b2b_approval_approved', [
                'order' => $order,
                'user' => $requester,
                'reference' => $reference,
                'instructions' => $instructions,
            ])
            ->setTo($requester)
            ->send();

        if (!$sent) {
            Craft::warning("Failed to send `b2b_approval_approved` email to {$requester->email}", 'b2b-commerce');
        }
    }

    /**
     * Mails the requester that their order was declined, with the reason. A missing requester is
     * logged, not fatal.
     */
    private function notifyDeclined(Order $order, ?User $requester, string $reason): void
    {
        if ($requester === null) {
            Craft::warning("Approval {$order->id} has no requester to notify of decline", 'b2b-commerce');

            return;
        }

        $reference = $order->reference ?: $order->getShortNumber();

        $sent = Craft::$app->getMailer()
            ->composeFromKey('b2b_approval_declined', [
                'order' => $order,
                'user' => $requester,
                'reference' => $reference,
                'reason' => $reason,
            ])
            ->setTo($requester)
            ->send();

        if (!$sent) {
            Craft::warning("Failed to send `b2b_approval_declined` email to {$requester->email}", 'b2b-commerce');
        }
    }

    /** @param array<string, mixed> $row */
    private function requester(array $row): ?User
    {
        if ($row['requestedById'] === null) {
            return null;
        }

        return Craft::$app->getUsers()->getUserById((int) $row['requestedById']);
    }

    /**
     * Creates one pending step row per required tier level for a freshly saved approval, keyed on the
     * approval's element id. The required levels are every tier whose minAmount is at or below the
     * order total. A tier-less company (or an amount below the lowest band) yields zero steps, which
     * the resolution path treats as the legacy single-approval behaviour.
     */
    private function createSteps(Approval $approval, Order $cart): void
    {
        $required = Plugin::getInstance()->approvalTiers->requiredLevels(
            (int) $approval->companyId,
            (float) $cart->getTotalPrice(),
        );

        foreach ($required as $tier) {
            Db::insert('{{%b2b_approval_steps}}', [
                'approvalId' => (int) $approval->id,
                'level' => (int) $tier['level'],
                'status' => ApprovalStatus::Pending->value,
            ]);
        }
    }

    /**
     * The step rows of an approval, ordered by level ascending. Empty for a legacy (tier-less)
     * approval.
     *
     * @return array<int, array<string, mixed>>
     */
    private function stepsForApproval(int $approvalId): array
    {
        return (new Query())
            ->from('{{%b2b_approval_steps}}')
            ->where(['approvalId' => $approvalId])
            ->orderBy(['level' => SORT_ASC])
            ->all();
    }

    /**
     * Reads the approval row for the given order, or null when there is none.
     *
     * @return array<string, mixed>|null
     */
    private function approvalRow(int $orderId): ?array
    {
        return (new Query())
            ->from('{{%b2b_approvals}}')
            ->where(['orderId' => $orderId])
            ->one() ?: null;
    }

    /**
     * Reads a stored UTC datetime column into a DateTime, or null when the column is empty.
     */
    private function toUtcDateTime(mixed $value): ?DateTime
    {
        if (empty($value)) {
            return null;
        }

        return new DateTime((string) $value, new DateTimeZone('UTC'));
    }

    /**
     * Whether the order carries any approval row at all, regardless of status. The row's primary
     * key is orderId, so at most one ever exists; used to refuse a second submit and, symmetrically,
     * to refuse a quote request on an order already in the approval flow (Quotes::requestQuote).
     */
    public function orderHasApproval(int $orderId): bool
    {
        return (new Query())
            ->from('{{%b2b_approvals}}')
            ->where(['orderId' => $orderId])
            ->exists();
    }

    /**
     * The refusal message for a submit against an order that already carries an approval row,
     * tailored to that row's status so the buyer learns what to do next: a pending row is still
     * awaiting a decision, a declined row is terminal (a new cart is the only way forward), and an
     * approved row is ready to be resumed to checkout.
     *
     * @param array<string, mixed> $row
     */
    private function existingRowMessage(array $row): string
    {
        $status = ApprovalStatus::from($row['status']);

        if ($status === ApprovalStatus::Declined) {
            return Craft::t('b2b-commerce', "This order's approval request was declined. Start a new cart to order again.");
        }

        if ($status === ApprovalStatus::Approved) {
            return Craft::t('b2b-commerce', 'This order has already been approved. Resume checkout to place it.');
        }

        return Craft::t('b2b-commerce', 'This order is already awaiting approval.');
    }

    /**
     * Whether the order carries an approved approval row — the sole state that lets the
     * completion backstop pass a gated order.
     */
    private function orderHasApprovedApproval(int $orderId): bool
    {
        return (new Query())
            ->from('{{%b2b_approvals}}')
            ->where([
                'orderId' => $orderId,
                'status' => ApprovalStatus::Approved->value,
            ])
            ->exists();
    }

    /**
     * Notifies every approver and admin of the company that an order awaits their decision.
     * Batched over the company's members (one query via getMemberUsers, no N+1), filtered to
     * the roles that may approve orders. A failed send is logged, not fatal, so one bad address
     * never blocks the others or the submit itself — mirrors the quote notifications.
     */
    private function notifyApprovers(Company $company, Order $order): void
    {
        $members = Plugin::getInstance()->companyMembers->getMemberUsers($company->id);

        foreach ($members as $member) {
            if (!$member['role']->canApproveOrders()) {
                continue;
            }

            $recipient = $member['user'];

            $sent = Craft::$app->getMailer()
                ->composeFromKey('b2b_approval_requested', [
                    'user' => $recipient,
                    'company' => $company,
                    'order' => $order,
                ])
                ->setTo($recipient)
                ->send();

            if (!$sent) {
                Craft::warning("Failed to send `b2b_approval_requested` email to {$recipient->email}", 'b2b-commerce');
            }
        }
    }
}
