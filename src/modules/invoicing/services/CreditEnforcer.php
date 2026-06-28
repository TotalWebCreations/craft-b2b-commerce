<?php

namespace totalwebcreations\b2bcommerce\modules\invoicing\services;

use Craft;
use craft\commerce\elements\Order;
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
 * {@see InvoiceGateway::availableForUseWithOrder()} already gates the gateway's availability at
 * checkout using totalPrice as a conservative pre-payment estimate; this is the last line of
 * defence at completion, run under a per-company mutex so two orders completing at once cannot
 * each read a stale balance and both slip past the limit.
 */
class CreditEnforcer extends Component
{
    /**
     * Time (seconds) to wait for the per-company credit lock before refusing completion.
     */
    private const LOCK_TIMEOUT = 5;

    public function enforceCreditLimit(Order $order): void
    {
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
            if (Plugin::getInstance()->creditBalance->canCover($company->id, (float) $order->getOutstandingBalance())) {
                return;
            }

            $message = Craft::t('b2b-commerce', "This order exceeds your company's credit limit.");

            // See the coupling comment above: attribute error before throwing keeps the aborted
            // order from persisting as completed via the _returnCart short-circuit.
            $order->addError('customerId', $message);

            throw new Exception($message);
        } finally {
            $mutex->release($lockName);
        }
    }
}
