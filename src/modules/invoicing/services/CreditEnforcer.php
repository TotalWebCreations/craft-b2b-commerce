<?php

namespace totalwebcreations\b2bcommerce\modules\invoicing\services;

use Craft;
use craft\commerce\elements\Order;
use Throwable;
use totalwebcreations\b2bcommerce\gateways\InvoiceGateway;
use totalwebcreations\b2bcommerce\Plugin;
use yii\base\Component;
use yii\base\Exception;

/**
 * Hard credit-limit enforcement at order completion for pay-on-account (invoice) orders.
 *
 * This lives in its own service rather than on OrderCompanyLink so that class stays lean and
 * single-purpose (linking orders to companies). It is wired on the SAME
 * Order::EVENT_BEFORE_COMPLETE_ORDER as OrderCompanyLink::enforcePurchasePolicy, registered
 * AFTER it, so the account-status backstop runs first and this credit check second. The two
 * handlers are independent: enforcePurchasePolicy's paid-order exemption does NOT bypass this
 * check -- an invoice order with a partial payment still gets credit-checked for the remainder.
 *
 * Storefront-only, like the account-status backstop: console and control-panel completions skip
 * enforcement. Completing an over-limit order from the CP order editor is a deliberate,
 * merchant-initiated business override; only the storefront checkout path is hard-enforced. This
 * also keeps the CP editor from throwing an uncaught 500 when an admin completes such an order.
 *
 * {@see InvoiceGateway::availableForUseWithOrder()} already gates the gateway's availability at
 * checkout using totalPrice as a conservative pre-payment estimate; this is the last line of
 * defence at completion.
 *
 * Locking -- exactly what the mutex covers and what it does not:
 *
 * enforceCreditLimit() runs on EVENT_BEFORE_COMPLETE_ORDER and acquires a per-company lock
 * (b2b-credit-{companyId}) BEFORE reading the balance. On insufficient credit or any throw it
 * releases immediately and refuses. On success it does NOT release: it hands the lock to
 * releaseCreditLock(), wired on EVENT_AFTER_COMPLETE_ORDER and registered AFTER
 * OrderCompanyLink::linkCompany, so the lock stays held across BOTH balance-affecting writes --
 * the completion save that flips isCompleted AND the b2b_order_company link row that makes the
 * order count towards the balance. Without spanning both writes, two concurrent invoice orders
 * could each read the same pre-completion balance, both fit "within" the limit, and together
 * overshoot it (a TOCTOU race).
 *
 * Residual, fail-safe leak: the lock can be held past its usefulness by two vectors -- if the
 * completion save itself fails after the BEFORE event, no AFTER event fires; or if
 * OrderCompanyLink::linkCompany throws inside the AFTER event, it aborts the chain before
 * releaseCreditLock (registered after it) runs. Either way the lock is never released in-process --
 * it is held until request teardown. Neither ever fails OPEN: MySQL GET_LOCK and file locks
 * auto-release when the connection or process ends,
 * and in the meantime a competing completion simply waits out the 5s acquire timeout and refuses
 * cleanly with the retry message. A stuck lock can only cause a temporary, self-healing refusal,
 * never an unchecked over-limit completion.
 */
class CreditEnforcer extends Component
{
    /**
     * Time (seconds) to wait for the per-company credit lock before refusing completion.
     */
    private const LOCK_TIMEOUT = 5;

    /**
     * Lock names held for an in-flight completion, keyed by order id. The BEFORE handler records
     * one here only after a successful acquire and a passing credit check; the AFTER handler reads
     * it to release the matching lock and clears the entry. Keying by order id makes a stray
     * second release a no-op (nothing left to release).
     *
     * @var array<int, string>
     */
    private array $heldLocks = [];

    public function enforceCreditLimit(Order $order): void
    {
        $request = Craft::$app->getRequest();

        // Storefront-only guard: CP/console completions are merchant-initiated overrides (see the
        // class docblock). Only the checkout path is hard-enforced.
        if ($request->getIsConsoleRequest() || $request->getIsCpRequest()) {
            return;
        }

        $gateway = $order->getGateway();

        // Only pay-on-account orders draw on a company's credit; everything else is paid up front.
        if (!$gateway instanceof InvoiceGateway) {
            return;
        }

        $customer = $order->getCustomer();

        if ($customer === null) {
            return;
        }

        $company = Plugin::getInstance()->companyMembers->getCompanyForUser($customer->id);

        // Gateway availability already restricts invoice payment to approved companies, so a
        // missing company should not happen here; let it pass rather than crash the completion.
        if ($company === null) {
            return;
        }

        $mutex = Craft::$app->getMutex();
        $lockName = "b2b-credit-{$company->id}";

        // Serialise credit checks per company: without the lock two invoice orders completing
        // concurrently could both read the same pre-completion balance and both fit "within" the
        // limit, together overshooting it. Failing to acquire must refuse -- never proceed
        // unchecked, that is exactly the race this guards against.
        if (!$mutex->acquire($lockName, self::LOCK_TIMEOUT)) {
            $message = Craft::t('b2b-commerce', 'Could not verify your company credit limit. Please try again.');

            // Same coupling as the account-status backstop: the error MUST sit on an order
            // attribute before throwing so Commerce's CartController::_returnCart() sees the
            // persisted error, its $cart->validate($attributes, false) fails on it, and the
            // half-completed order is never re-saved as completed.
            $order->addError('customerId', $message);

            throw new Exception($message);
        }

        try {
            // At EVENT_BEFORE_COMPLETE_ORDER the order is not yet completed, so it is not part of
            // the company's outstanding balance. The amount to cover is what will REMAIN owed after
            // any payment already captured -- getOutstandingBalance() (totalPrice - totalPaid), not
            // totalPrice. (Availability-time in InvoiceGateway uses totalPrice as the conservative
            // pre-payment estimate; here we know the real remainder.)
            if (!Plugin::getInstance()->creditBalance->canCover($company->id, (float) $order->getOutstandingBalance())) {
                $message = Craft::t('b2b-commerce', "This order exceeds your company's credit limit.");

                // See the coupling comment above: attribute error before throwing keeps the aborted
                // order from persisting as completed via the _returnCart short-circuit.
                $order->addError('customerId', $message);

                throw new Exception($message);
            }
        } catch (Throwable $throwable) {
            // Insufficient credit or any unexpected failure: release now and refuse. The lock is
            // only ever kept for a clean, about-to-complete order (see below).
            $mutex->release($lockName);

            throw $throwable;
        }

        // Credit check passed. Do NOT release here: hand the lock to releaseCreditLock() on
        // EVENT_AFTER_COMPLETE_ORDER so it spans the completion save and the b2b_order_company link
        // row. Track it by order id so the after-handler releases exactly this lock.
        $this->heldLocks[$order->id] = $lockName;
    }

    /**
     * Releases the per-company credit lock held for a just-completed order. Wired on
     * EVENT_AFTER_COMPLETE_ORDER and, critically, registered AFTER OrderCompanyLink::linkCompany so
     * it runs once the link row has been written (see Plugin::attachCommerceHandlers). A missing
     * entry (nothing acquired, or already released) is a no-op.
     */
    public function releaseCreditLock(Order $order): void
    {
        $lockName = $this->heldLocks[$order->id] ?? null;

        if ($lockName === null) {
            return;
        }

        unset($this->heldLocks[$order->id]);

        Craft::$app->getMutex()->release($lockName);
    }
}
