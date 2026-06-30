<?php

namespace totalwebcreations\b2bcommerce;

use Craft;
use craft\base\Model;
use craft\base\Plugin as BasePlugin;
use craft\commerce\elements\Order;
use craft\commerce\events\AddLineItemEvent;
use craft\commerce\events\CartPurgeEvent;
use craft\commerce\services\Carts;
use craft\commerce\services\Gateways;
use craft\elements\User;
use craft\enums\CmsEdition;
use craft\events\DefineBehaviorsEvent;
use craft\events\ModelEvent;
use craft\events\RegisterComponentTypesEvent;
use craft\events\RegisterEmailMessagesEvent;
use craft\events\RegisterUrlRulesEvent;
use craft\events\RegisterUserPermissionsEvent;
use craft\services\Elements;
use craft\services\SystemMessages;
use craft\services\UserPermissions;
use craft\web\twig\variables\CraftVariable;
use craft\web\UrlManager;
use totalwebcreations\b2bcommerce\behaviors\OrderBehavior;
use totalwebcreations\b2bcommerce\behaviors\UserBehavior;
use totalwebcreations\b2bcommerce\elements\Company;
use totalwebcreations\b2bcommerce\gateways\InvoiceGateway;
use totalwebcreations\b2bcommerce\models\Settings;
use totalwebcreations\b2bcommerce\modules\approvals\services\Approvals;
use totalwebcreations\b2bcommerce\modules\companies\services\CompanyAddresses;
use totalwebcreations\b2bcommerce\modules\companies\services\CompanyApproval;
use totalwebcreations\b2bcommerce\modules\companies\services\CompanyMembers;
use totalwebcreations\b2bcommerce\modules\companies\services\OrderCompanyLink;
use totalwebcreations\b2bcommerce\modules\companies\services\Registration;
use totalwebcreations\b2bcommerce\modules\invoicing\services\CreditBalance;
use totalwebcreations\b2bcommerce\modules\invoicing\services\CreditEnforcer;
use totalwebcreations\b2bcommerce\modules\quickorder\services\OrderLists;
use totalwebcreations\b2bcommerce\modules\quickorder\services\QuickOrder;
use totalwebcreations\b2bcommerce\modules\quotes\services\Quotes;
use totalwebcreations\b2bcommerce\services\PriceVisibility;
use totalwebcreations\b2bcommerce\variables\B2bVariable;
use yii\base\Event;

/**
 * @method static Plugin getInstance()
 * @method Settings getSettings()
 * @property-read Approvals $approvals
 * @property-read CompanyAddresses $companyAddresses
 * @property-read CompanyApproval $companyApproval
 * @property-read CompanyMembers $companyMembers
 * @property-read CreditBalance $creditBalance
 * @property-read CreditEnforcer $creditEnforcer
 * @property-read OrderCompanyLink $orderCompanyLink
 * @property-read OrderLists $orderLists
 * @property-read PriceVisibility $priceVisibility
 * @property-read QuickOrder $quickOrder
 * @property-read Quotes $quotes
 * @property-read Registration $registration
 */
class Plugin extends BasePlugin
{
    public string $schemaVersion = '1.0.5';
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
        $this->attachCommerceHandlers();
        $this->attachSystemMessages();
    }

    private function registerComponents(): void
    {
        $this->setComponents([
            'approvals' => Approvals::class,
            'companyAddresses' => CompanyAddresses::class,
            'companyApproval' => CompanyApproval::class,
            'companyMembers' => CompanyMembers::class,
            'creditBalance' => CreditBalance::class,
            'creditEnforcer' => CreditEnforcer::class,
            'orderCompanyLink' => OrderCompanyLink::class,
            'orderLists' => OrderLists::class,
            'priceVisibility' => PriceVisibility::class,
            'quickOrder' => QuickOrder::class,
            'quotes' => Quotes::class,
            'registration' => Registration::class,
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
                $event->rules['b2b'] = ['template' => 'b2b-commerce/companies/_index'];
                $event->rules['b2b/companies'] = ['template' => 'b2b-commerce/companies/_index'];
                $event->rules['b2b/companies/<companyId:\d+>/members'] = 'b2b-commerce/companies-cp/members';
                $event->rules['b2b/companies/<companyId:\d+>/orders'] = 'b2b-commerce/companies-cp/orders';
                $event->rules['b2b/companies/<elementId:\d+>'] = 'elements/edit';
                $event->rules['b2b/quotes'] = 'b2b-commerce/quotes-cp/index';
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
                    ],
                ];
            }
        );
    }

    private function attachCommerceHandlers(): void
    {
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

        // Buyer-side immutability of guarded carts: a line-item-frozen quote OR an order awaiting
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
        // and shipping stay unrecomputed under the persisting freeze. A pending approval freezes the
        // exact snapshot the approver is deciding on, so a buyer cannot inflate the order after it is
        // submitted. The two predicates are mutually exclusive on one order (submitForApproval
        // refuses an order that is part of a quote), so the message is unambiguous. Scoped like the
        // add-guard (skips console and CP requests), so merchant CP edits stay free. Address, gateway
        // and completion saves never change the line-item set, so they pass freely.
        Event::on(
            Order::class,
            Order::EVENT_BEFORE_SAVE,
            function(ModelEvent $event) {
                if (!$event->sender instanceof Order) {
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
                $pendingApproval = $this->approvals->orderHasPendingApproval($orderId);

                if (!$frozenQuote && !$pendingApproval) {
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
        $item['subnav'] = [
            'companies' => ['label' => Craft::t('b2b-commerce', 'Companies'), 'url' => 'b2b/companies'],
            'quotes' => ['label' => Craft::t('b2b-commerce', 'Quotes'), 'url' => 'b2b/quotes'],
        ];

        return $item;
    }

    protected function createSettingsModel(): ?Model
    {
        return new Settings();
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
