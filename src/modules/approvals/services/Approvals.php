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
use totalwebcreations\b2bcommerce\elements\Company;
use totalwebcreations\b2bcommerce\enums\ApprovalStatus;
use totalwebcreations\b2bcommerce\enums\CompanyRole;
use totalwebcreations\b2bcommerce\gateways\InvoiceGateway;
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

        if ($threshold === null) {
            return false;
        }

        if ($threshold === 0.0) {
            return true;
        }

        return (float) $order->getTotalPrice() > $threshold;
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
     *   - the order has no approval row yet (the row's primary key is orderId, one per order);
     *   - the order is not part of an open quote — the quote and approval flows are mutually
     *     exclusive on a single order (the quote flow already governs completion).
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

        if ($this->orderHasApproval((int) $cart->id)) {
            throw new InvalidArgumentException(
                Craft::t('b2b-commerce', 'This order is already awaiting approval.')
            );
        }

        if (Plugin::getInstance()->quotes->orderHasLineItemFrozenQuote((int) $cart->id)) {
            throw new InvalidArgumentException(
                Craft::t('b2b-commerce', 'This cart is part of a quote.')
            );
        }

        $company = Plugin::getInstance()->companyMembers->getCompanyForUser($actor->id);

        Db::insert('{{%b2b_approvals}}', [
            'orderId' => $cart->id,
            'companyId' => $company->id,
            'status' => ApprovalStatus::Pending->value,
            'requestedById' => $actor->id,
            'thresholdAmount' => $company->approvalThreshold,
        ]);

        $this->notifyApprovers($company, $cart);

        Commerce::getInstance()->getCarts()->forgetCart();
    }

    /**
     * Hard completion backstop for the approval gate, wired on EVENT_BEFORE_COMPLETE_ORDER.
     *
     * Refuses to place an order whose customer still needs approval (a purchaser over the
     * company's threshold) unless an approved approval row exists for the order. This is the
     * permission-to-order gate; the buyer-facing submit flow is the sanctioned way past it.
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
     *     and approved satisfies both and completes. The buyer path for such an order is to
     *     submit it for approval first (submitForApproval); this message never strands them, it
     *     tells them plainly what is missing.
     *
     * Completion matrix for a purchaser whose order clears the threshold:
     *   no approval row      -> refused (submit for approval first)
     *   pending approval     -> refused (still awaiting a decision)
     *   approved approval    -> passes
     * An admin or approver, or any order under the threshold, never triggers this gate at all.
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

        $customer = $order->getCustomer();

        if ($customer === null) {
            return;
        }

        if (!$this->needsApproval($order, $customer)) {
            return;
        }

        if ($this->orderHasApprovedApproval((int) $order->id)) {
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
     * Whether the order carries an approval request still awaiting a decision AND is not yet
     * completed — the exact predicate the buyer-mutation save guard needs. The completed-order
     * exclusion mirrors Quotes::orderHasLineItemFrozenQuote: once an order completes the freeze is
     * spent, and a pending row that outlives completion (a threshold relaxed after submit, then
     * reconciled by reconcilePendingApproval on AFTER_COMPLETE) must never keep freezing a placed
     * order's line items.
     */
    public function orderHasPendingApproval(int $orderId): bool
    {
        return (new Query())
            ->from(['a' => '{{%b2b_approvals}}'])
            ->innerJoin(['o' => '{{%commerce_orders}}'], '[[o.id]] = [[a.orderId]]')
            ->where([
                'a.orderId' => $orderId,
                'a.status' => ApprovalStatus::Pending->value,
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
     */
    public function approve(int $orderId, User $approver): void
    {
        $row = $this->requireResolvableRow($orderId, $approver);

        Db::update('{{%b2b_approvals}}', [
            'status' => ApprovalStatus::Approved->value,
            'resolvedById' => $approver->id,
        ], ['orderId' => $orderId]);

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
     * Declines a pending approval request, recording the reason and notifying the requester.
     * Shares the same oracle-free guard chain as approve(); additionally requires a non-empty
     * reason so the requester always learns why. The reason is validated only after authorisation,
     * so it never becomes a probe channel.
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

        Db::update('{{%b2b_approvals}}', [
            'status' => ApprovalStatus::Declined->value,
            'resolvedById' => $approver->id,
            'reason' => $reason,
        ], ['orderId' => $orderId]);

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
     * though the row is still pending. Neither approved-by-an-approver nor declined would be honest
     * here, so the row is flipped to approved with resolvedById = null and an auditable reason, so
     * the queue is left clean and the history records exactly why. Only a genuinely pending row is
     * touched (a row already resolved by approve/decline is left untouched); orderHasPendingApproval
     * excludes completed orders, so this is queried directly.
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

        Db::update('{{%b2b_approvals}}', [
            'status' => ApprovalStatus::Approved->value,
            'resolvedById' => null,
            'reason' => Craft::t('b2b-commerce', 'Auto-approved: the order no longer required approval at completion.'),
        ], ['orderId' => $orderId]);
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
     * key is orderId, so at most one ever exists; used to refuse a second submit.
     */
    private function orderHasApproval(int $orderId): bool
    {
        return (new Query())
            ->from('{{%b2b_approvals}}')
            ->where(['orderId' => $orderId])
            ->exists();
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
