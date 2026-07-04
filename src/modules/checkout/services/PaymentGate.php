<?php

namespace totalwebcreations\b2bcommerce\modules\checkout\services;

use Craft;
use craft\commerce\elements\Order;
use totalwebcreations\b2bcommerce\gateways\InvoiceGateway;
use totalwebcreations\b2bcommerce\Plugin;
use yii\base\Component;

/**
 * Payment-time gate — the EARLIER of the plugin's two approval/credit enforcement layers.
 *
 * The plugin enforces the approval and credit gates in two coexisting layers:
 *   - PAYMENT-TIME (this service, wired on Payments::EVENT_BEFORE_PROCESS_PAYMENT): refuses the
 *     charge UP FRONT, before Commerce authorizes or captures anything, so a gated purchaser who
 *     pays by card is never charged. Without it Commerce captures first and only then runs
 *     completion, so a refusal at completion would strand a paid-but-incomplete order.
 *   - COMPLETION-TIME (Approvals::enforceApprovalBeforeCompletion + CreditEnforcer::enforceCreditLimit,
 *     wired on Order::EVENT_BEFORE_COMPLETE_ORDER): the defence-in-depth net that still catches the
 *     paths this event never touches — a zero-payment or free order that completes without a
 *     payment call, an approver placing an approved invoice order directly (Approvals::approve),
 *     and any other markAsComplete() that does not run through processPayment().
 *
 * The two layers deliberately share the SAME decision logic (Approvals::mustHoldForApproval,
 * CreditBalance::canCover) so a change to a gate is felt in both places; this service only re-times
 * the refusal to before the charge.
 */
class PaymentGate extends Component
{
    /**
     * The reason this order's payment must be refused, or null when it may proceed. A pure decision
     * over the order's OWN customer (resolved from the order, not the session, mirroring
     * CreditEnforcer) — it applies no request scoping, so the caller is responsible for the
     * storefront/console guard. Reuses the existing gates rather than re-deriving them:
     *   - approval: {@see Approvals::mustHoldForApproval()} — a gated purchaser whose order carries
     *     no approved approval row (also the completion backstop's exact predicate);
     *   - credit:   an InvoiceGateway (pay-on-account) order whose company cannot cover the
     *     outstanding balance. Belt-and-braces: InvoiceGateway::availableForUseWithOrder already
     *     blocks selecting the gateway without credit room, this guards the payment path too.
     *
     * Approval is checked first: it governs PERMISSION to order, so a purchaser who never got
     * approval is refused before the credit position is even considered.
     */
    public function paymentRefusalReason(Order $order): ?string
    {
        if (Plugin::getInstance()->approvals->mustHoldForApproval($order)) {
            return Craft::t('b2b-commerce', 'This order requires approval before it can be placed.');
        }

        if ($this->exceedsCreditLimit($order)) {
            return Craft::t('b2b-commerce', "This order exceeds your company's credit limit.");
        }

        return null;
    }

    /**
     * Whether this is a pay-on-account order whose company cannot cover the outstanding balance.
     * Only InvoiceGateway orders draw on company credit; everything else is paid up front and never
     * gated here. Mirrors CreditEnforcer: the company is resolved from the order's customer and the
     * amount to cover is the outstanding balance (totalPrice minus anything already paid). A missing
     * customer or company is left to the completion backstop's fail-closed handling rather than
     * refused with a credit message that would not fit.
     */
    private function exceedsCreditLimit(Order $order): bool
    {
        if (!$order->getGateway() instanceof InvoiceGateway) {
            return false;
        }

        $customer = $order->getCustomer();

        if ($customer === null) {
            return false;
        }

        $company = Plugin::getInstance()->companyMembers->getCompanyForUser($customer->id);

        if ($company === null) {
            return false;
        }

        return !Plugin::getInstance()->creditBalance->canCover($company->id, (float) $order->getOutstandingBalance());
    }
}
