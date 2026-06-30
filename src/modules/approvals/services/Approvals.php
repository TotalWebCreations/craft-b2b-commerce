<?php

namespace totalwebcreations\b2bcommerce\modules\approvals\services;

use Craft;
use craft\commerce\elements\Order;
use craft\commerce\Plugin as Commerce;
use craft\db\Query;
use craft\elements\User;
use craft\helpers\Db;
use totalwebcreations\b2bcommerce\elements\Company;
use totalwebcreations\b2bcommerce\enums\ApprovalStatus;
use totalwebcreations\b2bcommerce\enums\CompanyRole;
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
     * are the deliberate merchant override.
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
     * Whether the order carries an approval request still awaiting a decision. A simple
     * indexed existence check on the pending status, mirroring the quote row guards.
     */
    public function orderHasPendingApproval(int $orderId): bool
    {
        return (new Query())
            ->from('{{%b2b_approvals}}')
            ->where([
                'orderId' => $orderId,
                'status' => ApprovalStatus::Pending->value,
            ])
            ->exists();
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
