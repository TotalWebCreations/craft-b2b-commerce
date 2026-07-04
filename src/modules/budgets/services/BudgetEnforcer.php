<?php

namespace totalwebcreations\b2bcommerce\modules\budgets\services;

use Craft;
use craft\commerce\elements\Order;
use DateTime;
use Throwable;
use totalwebcreations\b2bcommerce\Plugin;
use yii\base\Component;
use yii\base\Exception;

/**
 * Hard spending-budget enforcement at order completion — the completion-time backstop that mirrors
 * {@see \totalwebcreations\b2bcommerce\modules\invoicing\services\CreditEnforcer}. It lives in its own
 * service (single-purpose, budgets module) alongside the payment-time gate in PaymentGate.
 *
 * Unlike the credit gate, a budget applies to EVERY gateway: it caps what a member spends, not what
 * their company owes on account. The amount checked is the order's full totalPrice — the whole order
 * value counts towards the member's spend once it completes, regardless of how it is paid.
 *
 * Storefront-only, like every other completion guard: console and control-panel completions are the
 * deliberate merchant-initiated override, so enforcement stands down there.
 *
 * Locking. Two orders by the SAME member completing concurrently could each read the same
 * pre-completion spend, both fit under the budget, and together overshoot it — the same TOCTOU race
 * the credit gate guards per company. It is cheap to reuse the pattern, so a per-(company, member)
 * lock (b2b-budget-{companyId}-{userId}) is taken BEFORE reading the spend and, on a passing check,
 * handed to {@see releaseBudgetLock()} on EVENT_AFTER_COMPLETE_ORDER (registered AFTER
 * OrderCompanyLink::linkCompany) so it spans BOTH balance-affecting writes — the completion save and
 * the b2b_order_company link row that makes the order count towards spend. The same fail-safe residual
 * leak as the credit lock applies: a lock held past its use (a failed completion save, or a throw in
 * an earlier after-handler) is released at request teardown, never fails open, and can only cause a
 * temporary, self-healing refusal.
 */
class BudgetEnforcer extends Component
{
    /**
     * Time (seconds) to wait for the per-member budget lock before refusing completion.
     */
    private const LOCK_TIMEOUT = 5;

    /**
     * Lock names held for an in-flight completion, keyed by order id. Recorded only after a passing
     * check; the after-handler reads it to release the matching lock. Keying by order id makes a stray
     * second release a no-op.
     *
     * @var array<int, string>
     */
    private array $heldLocks = [];

    public function enforceBudget(Order $order): void
    {
        $request = Craft::$app->getRequest();

        // Storefront-only guard: CP/console completions are merchant-initiated overrides.
        if ($request->getIsConsoleRequest() || $request->getIsCpRequest()) {
            return;
        }

        $customer = $order->getCustomer();

        if ($customer === null) {
            return;
        }

        $company = Plugin::getInstance()->companyMembers->getCompanyForUser($customer->id);

        if ($company === null) {
            return;
        }

        // No budget row means unlimited: nothing to enforce, so never take a lock.
        if (Plugin::getInstance()->budgets->getBudget($company->id, $customer->id) === null) {
            return;
        }

        $mutex = Craft::$app->getMutex();
        $lockName = "b2b-budget-{$company->id}-{$customer->id}";

        // Serialise budget checks per member: without the lock two orders completing concurrently
        // could both read the same pre-completion spend and both fit, together overshooting. Failing
        // to acquire must refuse — never proceed unchecked.
        if (!$mutex->acquire($lockName, self::LOCK_TIMEOUT)) {
            $message = Craft::t('b2b-commerce', 'Could not verify your spending budget. Please try again.');

            // The error MUST sit on an order attribute before throwing so Commerce's
            // CartController::_returnCart() sees the persisted error and never re-saves the
            // half-completed order as completed (see CreditEnforcer for the full rationale).
            $order->addError('customerId', $message);

            throw new Exception($message);
        }

        try {
            // At EVENT_BEFORE_COMPLETE_ORDER this order is not yet completed or linked, so it is not
            // part of the member's spend. The amount to add is its full totalPrice.
            if (!Plugin::getInstance()->budgets->canAfford($company->id, $customer->id, (float) $order->getTotalPrice(), new DateTime('now'))) {
                $message = Craft::t('b2b-commerce', 'This order exceeds your spending budget.');

                $order->addError('customerId', $message);

                throw new Exception($message);
            }
        } catch (Throwable $throwable) {
            // Over budget or any unexpected failure: release now and refuse.
            $mutex->release($lockName);

            throw $throwable;
        }

        // Check passed. Do NOT release here: hand the lock to releaseBudgetLock() so it spans the
        // completion save and the b2b_order_company link row.
        $this->heldLocks[$order->id] = $lockName;
    }

    /**
     * Releases the per-member budget lock held for a just-completed order. Wired on
     * EVENT_AFTER_COMPLETE_ORDER and registered AFTER OrderCompanyLink::linkCompany so it runs once
     * the link row is written. A missing entry (nothing acquired, or already released) is a no-op.
     */
    public function releaseBudgetLock(Order $order): void
    {
        $lockName = $this->heldLocks[$order->id] ?? null;

        if ($lockName === null) {
            return;
        }

        unset($this->heldLocks[$order->id]);

        Craft::$app->getMutex()->release($lockName);
    }
}
