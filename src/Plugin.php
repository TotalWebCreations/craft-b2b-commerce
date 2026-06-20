<?php

namespace totalwebcreations\b2bcommerce;

use Craft;
use craft\base\Model;
use craft\base\Plugin as BasePlugin;
use craft\elements\User;
use craft\events\DefineBehaviorsEvent;
use craft\events\RegisterUrlRulesEvent;
use craft\events\RegisterUserPermissionsEvent;
use craft\services\UserPermissions;
use craft\web\UrlManager;
use totalwebcreations\b2bcommerce\behaviors\UserBehavior;
use totalwebcreations\b2bcommerce\models\Settings;
use totalwebcreations\b2bcommerce\modules\companies\services\CompanyMembers;
use yii\base\Event;

/**
 * @method static Plugin getInstance()
 * @method Settings getSettings()
 * @property-read CompanyMembers $companyMembers
 */
class Plugin extends BasePlugin
{
    public string $schemaVersion = '1.0.0';
    public bool $hasCpSettings = true;
    public bool $hasCpSection = true;

    public function init(): void
    {
        parent::init();

        if (Craft::$app->getRequest()->getIsConsoleRequest()) {
            $this->controllerNamespace = 'totalwebcreations\\b2bcommerce\\console\\controllers';
        }

        $this->setComponents([
            'companyMembers' => CompanyMembers::class,
        ]);

        Event::on(
            User::class,
            User::EVENT_DEFINE_BEHAVIORS,
            function(DefineBehaviorsEvent $event) {
                $event->behaviors['b2bUser'] = UserBehavior::class;
            }
        );

        Event::on(
            UrlManager::class,
            UrlManager::EVENT_REGISTER_CP_URL_RULES,
            function(RegisterUrlRulesEvent $event) {
                $event->rules['b2b'] = ['template' => 'b2b-commerce/companies/_index'];
                $event->rules['b2b/companies'] = ['template' => 'b2b-commerce/companies/_index'];
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
        ]);
    }
}
