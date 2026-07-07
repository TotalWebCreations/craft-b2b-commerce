<?php

namespace totalwebcreations\b2bcommerce;

use Craft;
use craft\base\Model;
use craft\base\Plugin as BasePlugin;
use craft\commerce\elements\Order;
use craft\commerce\errors\PaymentException;
use craft\commerce\events\AddLineItemEvent;
use craft\commerce\events\CartPurgeEvent;
use craft\commerce\events\ProcessPaymentEvent;
use craft\commerce\services\Carts;
use craft\commerce\services\Gateways;
use craft\commerce\services\Payments;
use craft\elements\User;
use craft\enums\CmsEdition;
use craft\helpers\Html;
use craft\events\DefineBehaviorsEvent;
use craft\events\DefineFieldLayoutFieldsEvent;
use craft\events\ModelEvent;
use craft\events\RegisterComponentTypesEvent;
use craft\events\RegisterEmailMessagesEvent;
use craft\events\RegisterGqlQueriesEvent;
use craft\events\RegisterGqlSchemaComponentsEvent;
use craft\events\RegisterGqlTypesEvent;
use craft\events\RegisterUrlRulesEvent;
use craft\events\RegisterUserPermissionsEvent;
use craft\models\FieldLayout;
use craft\services\Dashboard;
use craft\services\Elements;
use craft\services\Gql;
use craft\services\SystemMessages;
use craft\services\UserPermissions;
use craft\web\twig\variables\CraftVariable;
use craft\web\UrlManager;
use totalwebcreations\b2bcommerce\behaviors\OrderBehavior;
use totalwebcreations\b2bcommerce\behaviors\UserBehavior;
use totalwebcreations\b2bcommerce\elements\Company;
use totalwebcreations\b2bcommerce\fieldlayoutelements\AllowInvoicePaymentField;
use totalwebcreations\b2bcommerce\fieldlayoutelements\ApprovalThresholdField;
use totalwebcreations\b2bcommerce\fieldlayoutelements\CompanyTitleField;
use totalwebcreations\b2bcommerce\fieldlayoutelements\CreditLimitField;
use totalwebcreations\b2bcommerce\fieldlayoutelements\CustomerGroupField;
use totalwebcreations\b2bcommerce\fieldlayoutelements\PaymentTermDaysField;
use totalwebcreations\b2bcommerce\fieldlayoutelements\RegistrationNumberField;
use totalwebcreations\b2bcommerce\fieldlayoutelements\TaxIdField;
use totalwebcreations\b2bcommerce\gateways\InvoiceGateway;
use totalwebcreations\b2bcommerce\gql\interfaces\elements\Company as GqlCompanyInterface;
use totalwebcreations\b2bcommerce\gql\queries\B2bContext as GqlB2bContextQueries;
use totalwebcreations\b2bcommerce\gql\queries\Company as GqlCompanyQueries;
use totalwebcreations\b2bcommerce\models\Settings;
use totalwebcreations\b2bcommerce\modules\approvals\services\Approvals;
use totalwebcreations\b2bcommerce\modules\companies\services\CompanyAddresses;
use totalwebcreations\b2bcommerce\modules\companies\services\CompanyApproval;
use totalwebcreations\b2bcommerce\modules\companies\services\CompanyMembers;
use totalwebcreations\b2bcommerce\modules\companies\services\OrderCompanyLink;
use totalwebcreations\b2bcommerce\modules\companies\services\Registration;
use totalwebcreations\b2bcommerce\modules\companies\services\TaxIdValidation;
use totalwebcreations\b2bcommerce\modules\pricing\services\CustomerGroupSync;
use totalwebcreations\b2bcommerce\modules\budgets\services\BudgetEnforcer;
use totalwebcreations\b2bcommerce\modules\budgets\services\Budgets;
use totalwebcreations\b2bcommerce\modules\checkout\services\PaymentGate;
use totalwebcreations\b2bcommerce\modules\dashboard\services\Overview;
use totalwebcreations\b2bcommerce\modules\invoicing\services\CreditBalance;
use totalwebcreations\b2bcommerce\modules\invoicing\services\CreditEnforcer;
use totalwebcreations\b2bcommerce\modules\quickorder\services\OrderLists;
use totalwebcreations\b2bcommerce\modules\quickorder\services\QuickOrder;
use totalwebcreations\b2bcommerce\modules\quotes\services\Quotes;
use totalwebcreations\b2bcommerce\services\PriceVisibility;
use totalwebcreations\b2bcommerce\variables\B2bVariable;
use totalwebcreations\b2bcommerce\widgets\Overview as OverviewWidget;
use yii\base\Event;

/**
 * @method static Plugin getInstance()
 * @method Settings getSettings()
 * @property-read Approvals $approvals
 * @property-read BudgetEnforcer $budgetEnforcer
 * @property-read Budgets $budgets
 * @property-read CompanyAddresses $companyAddresses
 * @property-read CompanyApproval $companyApproval
 * @property-read CompanyMembers $companyMembers
 * @property-read CustomerGroupSync $customerGroupSync
 * @property-read CreditBalance $creditBalance
 * @property-read CreditEnforcer $creditEnforcer
 * @property-read OrderCompanyLink $orderCompanyLink
 * @property-read OrderLists $orderLists
 * @property-read Overview $overview
 * @property-read PaymentGate $paymentGate
 * @property-read PriceVisibility $priceVisibility
 * @property-read QuickOrder $quickOrder
 * @property-read Quotes $quotes
 * @property-read Registration $registration
 * @property-read TaxIdValidation $taxIdValidation
 */
class Plugin extends BasePlugin
{
    public string $schemaVersion = '1.0.7';
    public bool $hasCpSettings = true;
    public bool $hasCpSection = true;

    public function init(): void
    {
        parent::init();

        if (Craft::$app->getRequest()->getIsConsoleRequest()) {
            $this->controllerNamespace = 'totalwebcreations\\b2bcommerce\\console\\controllers';
        }

        $this->registerComponents();
        $this->registerGateways();
        $this->attachCpHandlers();
        $this->registerNativeFields();
        $this->attachCommerceHandlers();
        $this->attachSystemMessages();
        $this->registerGraphql();
    }

    /**
     * Registers the read-only GraphQL schema: the Company element type/queries and the top-level
     * b2bContext query. Both are gated behind their own schema components so a merchant opts in per
     * schema in the control panel. The b2bContext query always scopes to the authenticated user's own
     * company, so enabling it can never let one company read another's data.
     */
    private function registerGraphql(): void
    {
        Event::on(
            Gql::class,
            Gql::EVENT_REGISTER_GQL_TYPES,
            static function(RegisterGqlTypesEvent $event): void {
                $event->types[] = GqlCompanyInterface::class;
            }
        );

        Event::on(
            Gql::class,
            Gql::EVENT_REGISTER_GQL_QUERIES,
            static function(RegisterGqlQueriesEvent $event): void {
                $event->queries = array_merge(
                    $event->queries,
                    GqlCompanyQueries::getQueries(),
                    GqlB2bContextQueries::getQueries(),
                );
            }
        );

        Event::on(
            Gql::class,
            Gql::EVENT_REGISTER_GQL_SCHEMA_COMPONENTS,
            static function(RegisterGqlSchemaComponentsEvent $event): void {
                $label = Craft::t('b2b-commerce', 'B2B Commerce');

                $event->queries[$label]['b2bCompanies.all:read'] = [
                    'label' => Craft::t('b2b-commerce', 'View companies'),
                ];
                $event->queries[$label]['b2bCompanies.financials:read'] = [
                    'label' => Craft::t('b2b-commerce', 'View company financial fields'),
                ];
                $event->queries[$label]['b2bContext.self:read'] = [
                    'label' => Craft::t('b2b-commerce', 'View the current user’s B2B context'),
                ];
            }
        );
    }

    /**
     * Registers the core Company fields as native field-layout elements, so they always render in
     * the main content area of the Company edit screen while merchants can still append their own
     * custom fields through the field-layout designer. Marking them mandatory means they are
     * prepended to the layout even when no custom layout has been configured.
     */
    private function registerNativeFields(): void
    {
        Event::on(
            FieldLayout::class,
            FieldLayout::EVENT_DEFINE_NATIVE_FIELDS,
            function(DefineFieldLayoutFieldsEvent $event) {
                if ($event->sender->type !== Company::class) {
                    return;
                }

                $event->fields[] = CompanyTitleField::class;
                $event->fields[] = RegistrationNumberField::class;
                $event->fields[] = TaxIdField::class;
                $event->fields[] = CreditLimitField::class;
                $event->fields[] = PaymentTermDaysField::class;
                $event->fields[] = AllowInvoicePaymentField::class;
                $event->fields[] = ApprovalThresholdField::class;
                $event->fields[] = CustomerGroupField::class;
            }
        );
    }

    private function registerComponents(): void
    {
        $this->setComponents([
            'approvals' => Approvals::class,
            'budgetEnforcer' => BudgetEnforcer::class,
            'budgets' => Budgets::class,
            'companyAddresses' => CompanyAddresses::class,
            'companyApproval' => CompanyApproval::class,
            'companyMembers' => CompanyMembers::class,
            'customerGroupSync' => CustomerGroupSync::class,
            'creditBalance' => CreditBalance::class,
            'creditEnforcer' => CreditEnforcer::class,
            'orderCompanyLink' => OrderCompanyLink::class,
            'orderLists' => OrderLists::class,
            'overview' => Overview::class,
            'paymentGate' => PaymentGate::class,
            'priceVisibility' => PriceVisibility::class,
            'quickOrder' => QuickOrder::class,
            'quotes' => Quotes::class,
            'registration' => Registration::class,
            'taxIdValidation' => TaxIdValidation::class,
        ]);

        Event::on(
            CraftVariable::class,
            CraftVariable::EVENT_INIT,
            function(Event $event) {
                $event->sender->set('b2b', B2bVariable::class);
            }
        );

        Event::on(
            User::class,
            User::EVENT_DEFINE_BEHAVIORS,
            function(DefineBehaviorsEvent $event) {
                $event->behaviors['b2bUser'] = UserBehavior::class;
            }
        );

        Event::on(
            Order::class,
            Order::EVENT_DEFINE_BEHAVIORS,
            function(DefineBehaviorsEvent $event) {
                $event->behaviors['b2bOrder'] = OrderBehavior::class;
            }
        );
    }

    private function registerGateways(): void
    {
        Event::on(
            Gateways::class,
            Gateways::EVENT_REGISTER_GATEWAY_TYPES,
            function(RegisterComponentTypesEvent $event) {
                $event->types[] = InvoiceGateway::class;
            }
        );
    }

    private function attachCpHandlers(): void
    {
        Event::on(
            Elements::class,
            Elements::EVENT_REGISTER_ELEMENT_TYPES,
            function(RegisterComponentTypesEvent $event) {
                $event->types[] = Company::class;
            }
        );

        Event::on(
            UrlManager::class,
            UrlManager::EVENT_REGISTER_CP_URL_RULES,
            function(RegisterUrlRulesEvent $event) {
                $event->rules['b2b'] = 'b2b-commerce/dashboard-cp/index';
                $event->rules['b2b/companies'] = ['template' => 'b2b-commerce/companies/_index'];
                $event->rules['b2b/companies/<companyId:\d+>/members'] = 'b2b-commerce/companies-cp/members';
                $event->rules['b2b/companies/<companyId:\d+>/orders'] = 'b2b-commerce/companies-cp/orders';
                $event->rules['b2b/companies/<elementId:\d+>'] = 'elements/edit';
                $event->rules['b2b/quotes'] = 'b2b-commerce/quotes-cp/index';
                $event->rules['b2b/approvals'] = 'b2b-commerce/approvals-cp/index';
            }
        );

        Event::on(
            Dashboard::class,
            Dashboard::EVENT_REGISTER_WIDGET_TYPES,
            function(RegisterComponentTypesEvent $event) {
                $event->types[] = OverviewWidget::class;
            }
        );

        Event::on(
            UserPermissions::class,
            UserPermissions::EVENT_REGISTER_PERMISSIONS,
            function(RegisterUserPermissionsEvent $event) {
                $event->permissions[] = [
                    'heading' => Craft::t('b2b-commerce', 'B2B Commerce'),
                    'permissions' => [
                        'b2b-commerce:manageCompanies' => [
                            'label' => Craft::t('b2b-commerce', 'Manage companies'),
                        ],
                        'b2b-commerce:manageQuotes' => [
                            'label' => Craft::t('b2b-commerce', 'Manage quotes'),
                        ],
                        'b2b-commerce:manageApprovals' => [
                            'label' => Craft::t('b2b-commerce', 'Manage approvals'),
                        ],
                    ],
                ];
            }
        );
    }

    private function attachCommerceHandlers(): void
    {
        // Payment-time refusal — the EARLIER of the two approval/credit enforcement layers.
        // EVENT_BEFORE_PROCESS_PAYMENT fires before Commerce creates the transaction or asks the
        // gateway to authorize/capture (Payments::processPayment triggers it before its try block),
        // so refusing here means a gated purchaser paying by card is NEVER charged — the fix for the
        // paid-but-incomplete order that a completion-only gate would leave behind. The
        // EVENT_BEFORE_COMPLETE_ORDER backstops below (enforceApprovalBeforeCompletion,
        // enforceCreditLimit) stay armed as the defence-in-depth net for the paths this event never
        // reaches: zero-payment / free orders, an approver placing an approved invoice order
        // directly, and any other markAsComplete() that does not run through processPayment().
        //
        // A PaymentException thrown here propagates straight out of processPayment (the trigger is
        // before its own try/catch) and is caught by Commerce's PaymentsController::actionPay, which
        // surfaces its message as a clean storefront failure — no 500, and no transaction was ever
        // created. Storefront-scoped like every other guard: console and CP payments are the
        // merchant override.
        Event::on(
            Payments::class,
            Payments::EVENT_BEFORE_PROCESS_PAYMENT,
            function(ProcessPaymentEvent $event) {
                $request = Craft::$app->getRequest();

                if ($request->getIsConsoleRequest() || $request->getIsCpRequest()) {
                    return;
                }

                $reason = $this->paymentGate->paymentRefusalReason($event->order);

                if ($reason === null) {
                    return;
                }

                throw new PaymentException($reason);
            }
        );

        Event::on(
            Order::class,
            Order::EVENT_BEFORE_ADD_LINE_ITEM,
            function(AddLineItemEvent $event) {
                $request = Craft::$app->getRequest();

                if ($request->getIsConsoleRequest() || $request->getIsCpRequest()) {
                    return;
                }

                if ($event->sender instanceof Order && $this->quotes->orderHasLineItemFrozenQuote($event->sender->id)) {
                    $message = Craft::t('b2b-commerce', 'This cart is part of a quote and cannot be modified.');

                    $event->isValid = false;
                    $event->lineItem->addError('purchasableId', $message);
                    $event->sender->addError('purchasableId', $message);

                    return;
                }

                $canPurchase = $this->priceVisibility->canPurchase(
                    Craft::$app->getUser()->getIdentity()
                );

                if ($canPurchase) {
                    return;
                }

                $message = Craft::t('b2b-commerce', 'You need an approved business account to order.');

                $event->isValid = false;
                $event->lineItem->addError('purchasableId', $message);

                if ($event->sender instanceof Order) {
                    $event->sender->addError('purchasableId', $message);
                }
            }
        );

        // Buyer-side immutability of guarded carts: a line-item-frozen quote OR a line-item-frozen
        // approval. Under the sent-quote price freeze (recalculationMode = none) the charged total
        // still moves with quantity and a frozen absolute discount can be driven negative, and line
        // removal is otherwise unguarded — so the add-line-item guard above (new items only) is not
        // enough. This vetoes the order save whenever such an order's cart diverges from what is
        // stored: additions and removals change the id-set, quantity edits move the qty, and in-place
        // option edits change the optionsSignature — all four are blocked (line-item notes are not
        // compared and stay editable). It stands down for the plugin's own saves
        // (isGuardedSaveAllowed). A quote stays frozen through the whole requested → sent → accepted
        // window until the order completes (orderHasLineItemFrozenQuote): an accepted quote is the
        // negotiated deal, so post-accept additions cannot ride in at resolve-time prices while tax
        // and shipping stay unrecomputed under the persisting freeze. An approval freezes the exact
        // snapshot both while it is PENDING (so a buyer cannot inflate the order the approver is
        // deciding on) AND once it is APPROVED (so a buyer who is handed the approved order back via
        // resumeCheckout cannot then add line items and complete past the amount that was signed off)
        // — orderHasLineItemFrozenApproval spans both live states. The two predicates are NOT
        // strictly exclusive: an accepted quote submitted for approval carries both a (frozen)
        // accepted-quote row and an approval row, so both freeze together — the quote message wins,
        // which is accurate since the order genuinely is part of a quote. The approval freeze is
        // itself disarmed when the enableApprovals toggle is off, so a pending cart left over from
        // when the feature was on becomes editable again. Scoped like the add-guard (skips console
        // and CP requests), so merchant CP edits stay free. Address, gateway and completion saves
        // never change the line-item set, so they pass freely.
        Event::on(
            Order::class,
            Order::EVENT_BEFORE_SAVE,
            function(ModelEvent $event) {
                if (!$event->sender instanceof Order) {
                    return;
                }

                // A completing (or completed) order is past the freeze: completion never changes
                // the line-item set, and isCompleted is not buyer-settable through the cart
                // endpoints. Standing down here is also REQUIRED for correctness during the
                // completion save itself: markAsComplete() flips isCompleted before saving, which
                // salts LineItem::getOptionsSignature() with the line-item id while the stored row
                // still carries the unsalted cart signature — lineItemsDifferFromStored would read
                // that as a phantom options edit and veto the storefront completion of every
                // accepted quote or approved order (authorized payment, order stuck incomplete).
                if ($event->sender->isCompleted) {
                    return;
                }

                $request = Craft::$app->getRequest();

                if ($request->getIsConsoleRequest() || $request->getIsCpRequest()) {
                    return;
                }

                $quotes = $this->quotes;

                if ($quotes->isGuardedSaveAllowed()) {
                    return;
                }

                $order = $event->sender;
                $orderId = $order->id;

                if ($orderId === null) {
                    return;
                }

                $frozenQuote = $quotes->orderHasLineItemFrozenQuote($orderId);
                $frozenApproval = $this->getSettings()->enableApprovals
                    && $this->approvals->orderHasLineItemFrozenApproval($orderId);

                if (!$frozenQuote && !$frozenApproval) {
                    return;
                }

                if (!$quotes->lineItemsDifferFromStored($order)) {
                    return;
                }

                $message = $frozenQuote
                    ? Craft::t('b2b-commerce', 'This cart is part of a quote and cannot be modified.')
                    : Craft::t('b2b-commerce', 'This cart is awaiting approval and cannot be modified.');

                $event->isValid = false;
                $order->addError('lineItems', $message);
            }
        );

        // Checkout VAT-id passthrough: before every storefront cart save, fill the order's
        // shipping/billing address organizationTaxId with the customer's company VAT id when the
        // customer left it empty. Order::EVENT_BEFORE_SAVE is deliberately the hook: Commerce
        // recalculates tax in Order::afterSave() BEFORE persisting the in-memory address elements,
        // so a pre-save mutation is both seen by the tax adjuster (removeVatIncluded reverse
        // charge) on this very save and persisted by Commerce itself — no extra saveElement(), no
        // save loop. Site requests only (guarded inside the service).
        Event::on(
            Order::class,
            Order::EVENT_BEFORE_SAVE,
            function(ModelEvent $event) {
                if (!$event->sender instanceof Order) {
                    return;
                }

                $this->taxIdValidation->applyCompanyTaxIdToOrderAddresses($event->sender);
            }
        );

        // Completion veto: a quote order reactivated as a cart (commerce/cart/load-cart by number)
        // must not be completed unless its quote was accepted. Registered before the other completion
        // guards so an unaccepted quote is rejected up front. Storefront-scoped inside the service.
        Event::on(
            Order::class,
            Order::EVENT_BEFORE_COMPLETE_ORDER,
            function(Event $event) {
                if (!$event->sender instanceof Order) {
                    return;
                }

                $this->quotes->enforceAcceptedBeforeCompletion($event->sender);
            }
        );

        // Approval completion backstop. Registered here — after the quote-completion veto but BEFORE
        // the account-status and credit backstops — deliberately: it is a permission-to-order gate,
        // so a purchaser who never got approval is refused up front, before enforceCreditLimit takes
        // its per-company credit lock (a refusal after that lock is acquired would leak it until
        // request teardown). It stacks with the quote veto: an accepted quote whose purchaser is over
        // the threshold must ALSO carry an approved approval, and an order that is both accepted and
        // approved satisfies both guards and completes. See Approvals::enforceApprovalBeforeCompletion
        // for the full matrix and the paid-order / quote-interplay rationale.
        Event::on(
            Order::class,
            Order::EVENT_BEFORE_COMPLETE_ORDER,
            function(Event $event) {
                if (!$event->sender instanceof Order) {
                    return;
                }

                $this->approvals->enforceApprovalBeforeCompletion($event->sender);
            }
        );

        Event::on(
            Order::class,
            Order::EVENT_BEFORE_COMPLETE_ORDER,
            function(Event $event) {
                if (!$event->sender instanceof Order) {
                    return;
                }

                $this->orderCompanyLink->enforcePurchasePolicy($event->sender);
            }
        );

        // Spending-budget completion backstop. Registered after the approval and account-status
        // guards but BEFORE the credit-limit check, mirroring the payment-time gate's order
        // (approval, budget, credit): a member's personal spend cap is a permission-shaped gate that
        // is judged before the company credit position. It applies on any gateway — a budget caps
        // spend, not what is owed — and takes its own per-member lock, so it precedes the credit lock
        // to keep the common refusal path clean. See BudgetEnforcer for the fail-safe lock rationale.
        Event::on(
            Order::class,
            Order::EVENT_BEFORE_COMPLETE_ORDER,
            function(Event $event) {
                if (!$event->sender instanceof Order) {
                    return;
                }

                $this->budgetEnforcer->enforceBudget($event->sender);
            }
        );

        // Registered AFTER enforcePurchasePolicy so the account-status backstop runs first and the
        // hard credit-limit check second. The two are independent: the backstop's paid-order
        // exemption does not skip this check (a partially paid invoice order is still checked for
        // its remaining balance).
        Event::on(
            Order::class,
            Order::EVENT_BEFORE_COMPLETE_ORDER,
            function(Event $event) {
                if (!$event->sender instanceof Order) {
                    return;
                }

                $this->creditEnforcer->enforceCreditLimit($event->sender);
            }
        );

        Event::on(
            Order::class,
            Order::EVENT_AFTER_COMPLETE_ORDER,
            function(Event $event) {
                if (!$event->sender instanceof Order) {
                    return;
                }

                $this->orderCompanyLink->linkCompany($event->sender);
            }
        );

        // CRITICAL ORDERING: this release MUST stay registered AFTER linkCompany above.
        // yii\base\Event::trigger fires class-level handlers in registration order (Event::on
        // appends; the handlers are array_merge'd in registration order in Event::trigger), so
        // releasing here runs only once linkCompany's AFTER_COMPLETE write has landed. The credit
        // lock taken in EVENT_BEFORE_COMPLETE_ORDER must span BOTH the completion save AND the
        // b2b_order_company link row, otherwise a concurrent invoice order could read a stale
        // balance in between and both slip past the limit. Do not reorder these two Event::on calls.
        Event::on(
            Order::class,
            Order::EVENT_AFTER_COMPLETE_ORDER,
            function(Event $event) {
                if (!$event->sender instanceof Order) {
                    return;
                }

                $this->creditEnforcer->releaseCreditLock($event->sender);
            }
        );

        // Release the per-member budget lock. Like the credit-lock release, this MUST stay registered
        // AFTER linkCompany above so the lock spans BOTH the completion save and the b2b_order_company
        // link row that makes the order count towards the member's spend. See BudgetEnforcer.
        Event::on(
            Order::class,
            Order::EVENT_AFTER_COMPLETE_ORDER,
            function(Event $event) {
                if (!$event->sender instanceof Order) {
                    return;
                }

                $this->budgetEnforcer->releaseBudgetLock($event->sender);
            }
        );

        // Stale-pending reconciliation. Registered AFTER linkCompany (and the credit-lock release):
        // if a purchaser's over-threshold order is submitted for approval and the merchant then nulls
        // or raises the company threshold, live needsApproval drops to false, so the completion
        // backstop passes even though the approval row is still pending. This flips such a still-pending
        // row to approved with resolvedById = null and an auditable reason, so the approver queue is
        // left clean and the history stays honest. See Approvals::reconcilePendingApproval.
        Event::on(
            Order::class,
            Order::EVENT_AFTER_COMPLETE_ORDER,
            function(Event $event) {
                if (!$event->sender instanceof Order) {
                    return;
                }

                $this->approvals->reconcilePendingApproval($event->sender);
            }
        );

        // Purge protection: Commerce's purgeIncompleteCarts (Carts::purgeIncompleteCarts, on by
        // default, 90 days) deletes non-completed orders and the CASCADE FK would wipe their
        // b2b_quotes and b2b_approvals rows with them — silently losing sent quotes with long
        // validity, pending approvals, and ALL terminal quote/approval history. Exclude every order
        // that carries a quote row OR an approval row from the purge query so those business records
        // survive.
        Event::on(
            Carts::class,
            Carts::EVENT_BEFORE_PURGE_INACTIVE_CARTS,
            function(CartPurgeEvent $event) {
                $this->quotes->excludeQuoteOrdersFromPurge($event->inactiveCartsQuery);
                $this->approvals->excludeApprovalOrdersFromPurge($event->inactiveCartsQuery);
            }
        );
    }

    private function attachSystemMessages(): void
    {
        Event::on(
            SystemMessages::class,
            SystemMessages::EVENT_REGISTER_MESSAGES,
            function(RegisterEmailMessagesEvent $event) {
                $event->messages[] = [
                    'key' => 'b2b_company_approved',
                    'heading' => Craft::t('b2b-commerce', 'B2B: company approved'),
                    'subject' => Craft::t('b2b-commerce', 'Your business account has been approved'),
                    'body' => Craft::t('b2b-commerce', "Hi {{user.friendlyName}},\n\n" .
                        "Good news — your business account for {{company.title}} has been approved. " .
                        "You can now sign in and order at business conditions.\n\n" .
                        "{{siteUrl}}"),
                ];

                $event->messages[] = [
                    'key' => 'b2b_member_added',
                    'heading' => Craft::t('b2b-commerce', 'B2B: added to a company'),
                    'subject' => Craft::t('b2b-commerce', 'You have been added to a business account'),
                    'body' => Craft::t('b2b-commerce', "Hi {{user.friendlyName}},\n\n" .
                        "You have been added to the business account for {{company.title}}. " .
                        "You can now sign in and order at business conditions.\n\n" .
                        "{{siteUrl}}"),
                ];

                $event->messages[] = [
                    'key' => 'b2b_quote_sent',
                    'heading' => Craft::t('b2b-commerce', 'B2B: quote sent'),
                    'subject' => Craft::t('b2b-commerce', 'Your quote is ready'),
                    'body' => Craft::t('b2b-commerce', "Hi {{user.friendlyName}},\n\n" .
                        "Your quote is ready. You can accept it or decline it using the links below:\n\n" .
                        "Accept: {{acceptUrl}}\n" .
                        "Decline: {{declineUrl}}"),
                ];

                $event->messages[] = [
                    'key' => 'b2b_approval_requested',
                    'heading' => Craft::t('b2b-commerce', 'B2B: order approval requested'),
                    'subject' => Craft::t('b2b-commerce', 'An order is awaiting your approval'),
                    'body' => Craft::t('b2b-commerce', "Hi {{user.friendlyName}},\n\n" .
                        "A colleague at {{company.title}} has submitted an order that needs your approval " .
                        "before it can be placed. Please review it and approve or decline it.\n\n" .
                        "{{siteUrl}}"),
                ];

                $event->messages[] = [
                    'key' => 'b2b_approval_approved',
                    'heading' => Craft::t('b2b-commerce', 'B2B: order approved'),
                    'subject' => Craft::t('b2b-commerce', 'Your order has been approved'),
                    'body' => Craft::t('b2b-commerce', "Hi {{user.friendlyName}},\n\n" .
                        "Your order {{reference}} has been approved.\n\n" .
                        "{{instructions}}"),
                ];

                $event->messages[] = [
                    'key' => 'b2b_approval_declined',
                    'heading' => Craft::t('b2b-commerce', 'B2B: order declined'),
                    'subject' => Craft::t('b2b-commerce', 'Your order was declined'),
                    'body' => Craft::t('b2b-commerce', "Hi {{user.friendlyName}},\n\n" .
                        "Your order {{reference}} was declined.\n\n" .
                        "Reason: {{reason}}"),
                ];

                $event->messages[] = [
                    'key' => 'b2b_quote_declined',
                    'heading' => Craft::t('b2b-commerce', 'B2B: quote declined'),
                    'subject' => Craft::t('b2b-commerce', 'Your quote request was declined'),
                    'body' => Craft::t('b2b-commerce', "Hi {{user.friendlyName}},\n\n" .
                        "Unfortunately your quote request was declined.\n\n" .
                        "Reason: {{reason}}"),
                ];
            }
        );
    }

    public function getCpNavItem(): ?array
    {
        $item = parent::getCpNavItem();
        $item['label'] = Craft::t('b2b-commerce', 'B2B');
        $item['url'] = 'b2b';

        // Badge the section (and the Companies subnav it is reviewed under) with the number of
        // companies still awaiting review. Gated by the section's own permission so the count is
        // never queried for a user who cannot see the nav item anyway, and left off entirely when
        // the queue is empty so no zero badge shows.
        $pendingRegistrations = Craft::$app->getUser()->checkPermission('b2b-commerce:manageCompanies')
            ? $this->overview->getPendingRegistrationsCount()
            : 0;

        $companiesSubnav = ['label' => Craft::t('b2b-commerce', 'Companies'), 'url' => 'b2b/companies'];

        if ($pendingRegistrations > 0) {
            $item['badgeCount'] = $pendingRegistrations;
            $companiesSubnav['badgeCount'] = $pendingRegistrations;
        }

        $subnav = [
            'overview' => ['label' => Craft::t('b2b-commerce', 'Overview'), 'url' => 'b2b'],
            'companies' => $companiesSubnav,
            'quotes' => ['label' => Craft::t('b2b-commerce', 'Quotes'), 'url' => 'b2b/quotes'],
            'approvals' => ['label' => Craft::t('b2b-commerce', 'Approvals'), 'url' => 'b2b/approvals'],
        ];

        // Deep-link to the plugin's own settings, but only for someone who can actually open it:
        // Craft's plugin-settings screen is admin-only and disabled entirely when admin changes are
        // locked, so mirror that gate here rather than surface a link that would 403.
        $currentUser = Craft::$app->getUser()->getIdentity();

        if ($currentUser?->admin && Craft::$app->getConfig()->getGeneral()->allowAdminChanges) {
            $subnav['settings'] = [
                'label' => Craft::t('b2b-commerce', 'Settings'),
                'url' => "settings/plugins/{$this->id}",
            ];
        }

        $item['subnav'] = $subnav;

        return $item;
    }

    protected function createSettingsModel(): ?Model
    {
        return new Settings();
    }

    /**
     * Renders the plugin settings inside a custom control-panel layout that carries a left sidebar
     * of section links (mirroring Commerce's settings), instead of Craft's default single-column
     * plugin settings page. The field markup is still produced by {@see settingsHtml()} and namespaced
     * under `settings` exactly as Craft's own settingsResponse() does — so the save action, the
     * read-only (locked admin changes) mode and the company field-layout save are all unchanged; only
     * the surrounding chrome differs.
     */
    public function getSettingsResponse(): mixed
    {
        $readOnly = !Craft::$app->getConfig()->getGeneral()->allowAdminChanges;

        $settingsHtml = Craft::$app->getView()->namespaceInputs(function() use ($readOnly): string {
            if ($readOnly) {
                return (string) Html::disableInputs(fn(): string => (string) $this->settingsHtml());
            }

            return (string) $this->settingsHtml();
        }, 'settings');

        return Craft::$app->controller->renderTemplate('b2b-commerce/settings/_layout', [
            'plugin' => $this,
            'settingsHtml' => $settingsHtml,
            'readOnly' => $readOnly,
        ]);
    }

    protected function settingsHtml(): ?string
    {
        return Craft::$app->getView()->renderTemplate('b2b-commerce/_settings', [
            'settings' => $this->getSettings(),
            'belowPro' => Craft::$app->edition->value < CmsEdition::Pro->value,
            'companyFieldLayout' => Craft::$app->getFields()->getLayoutByType(Company::class),
        ]);
    }

    public function afterSaveSettings(): void
    {
        $this->saveCompanyFieldLayout();

        parent::afterSaveSettings();
    }

    private function saveCompanyFieldLayout(): void
    {
        $request = Craft::$app->getRequest();

        if ($request->getIsConsoleRequest()) {
            return;
        }

        if ($request->getBodyParam('settings.fieldLayout') === null) {
            return;
        }

        $fieldLayout = Craft::$app->getFields()->assembleLayoutFromPost('settings');
        $fieldLayout->type = Company::class;

        if (Craft::$app->getFields()->saveLayout($fieldLayout)) {
            return;
        }

        Craft::error(
            'Could not save the company field layout: ' . implode(' ', $fieldLayout->getErrorSummary(true)),
            __METHOD__
        );

        Craft::$app->getSession()->setError(
            Craft::t('b2b-commerce', 'The company field layout could not be saved.')
        );
    }
}
