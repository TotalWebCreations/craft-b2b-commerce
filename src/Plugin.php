<?php

namespace totalwebcreations\b2bcommerce;

use Craft;
use craft\base\Model;
use craft\base\Plugin as BasePlugin;
use craft\commerce\elements\Order;
use craft\commerce\events\AddLineItemEvent;
use craft\commerce\services\Gateways;
use craft\elements\User;
use craft\enums\CmsEdition;
use craft\events\DefineBehaviorsEvent;
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
use totalwebcreations\b2bcommerce\modules\companies\services\CompanyAddresses;
use totalwebcreations\b2bcommerce\modules\companies\services\CompanyApproval;
use totalwebcreations\b2bcommerce\modules\companies\services\CompanyMembers;
use totalwebcreations\b2bcommerce\modules\companies\services\OrderCompanyLink;
use totalwebcreations\b2bcommerce\modules\companies\services\Registration;
use totalwebcreations\b2bcommerce\modules\invoicing\services\CreditBalance;
use totalwebcreations\b2bcommerce\modules\invoicing\services\CreditEnforcer;
use totalwebcreations\b2bcommerce\modules\quickorder\services\OrderLists;
use totalwebcreations\b2bcommerce\modules\quickorder\services\QuickOrder;
use totalwebcreations\b2bcommerce\services\PriceVisibility;
use totalwebcreations\b2bcommerce\variables\B2bVariable;
use yii\base\Event;

/**
 * @method static Plugin getInstance()
 * @method Settings getSettings()
 * @property-read CompanyAddresses $companyAddresses
 * @property-read CompanyApproval $companyApproval
 * @property-read CompanyMembers $companyMembers
 * @property-read CreditBalance $creditBalance
 * @property-read CreditEnforcer $creditEnforcer
 * @property-read OrderCompanyLink $orderCompanyLink
 * @property-read OrderLists $orderLists
 * @property-read PriceVisibility $priceVisibility
 * @property-read QuickOrder $quickOrder
 * @property-read Registration $registration
 */
class Plugin extends BasePlugin
{
    public string $schemaVersion = '1.0.4';
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
            'companyAddresses' => CompanyAddresses::class,
            'companyApproval' => CompanyApproval::class,
            'companyMembers' => CompanyMembers::class,
            'creditBalance' => CreditBalance::class,
            'creditEnforcer' => CreditEnforcer::class,
            'orderCompanyLink' => OrderCompanyLink::class,
            'orderLists' => OrderLists::class,
            'priceVisibility' => PriceVisibility::class,
            'quickOrder' => QuickOrder::class,
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

                if ($request->getIsConsoleRequest() || !$request->getIsSiteRequest()) {
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
