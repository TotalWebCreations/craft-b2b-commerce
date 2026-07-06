<?php

namespace totalwebcreations\b2bcommerce\widgets;

use Craft;
use craft\base\Widget;
use craft\commerce\Plugin as Commerce;
use totalwebcreations\b2bcommerce\Plugin;

/**
 * Dashboard widget mirroring the B2B overview figures on Craft's own dashboard. Both the
 * selectability and the data are gated by manageCompanies, so a user who cannot reach the B2B
 * section can neither add the widget nor see its numbers.
 */
class Overview extends Widget
{
    public static function displayName(): string
    {
        return Craft::t('b2b-commerce', 'B2B overview');
    }

    public static function icon(): ?string
    {
        return Plugin::getInstance()->getBasePath() . DIRECTORY_SEPARATOR . 'icon-mask.svg';
    }

    public static function isSelectable(): bool
    {
        return Craft::$app->getUser()->checkPermission('b2b-commerce:manageCompanies');
    }

    public function getTitle(): ?string
    {
        return Craft::t('b2b-commerce', 'B2B overview');
    }

    public function getBodyHtml(): ?string
    {
        if (!Craft::$app->getUser()->checkPermission('b2b-commerce:manageCompanies')) {
            return Craft::t('b2b-commerce', 'You don’t have permission to view the B2B overview.');
        }

        return Craft::$app->getView()->renderTemplate('b2b-commerce/dashboard/_widget', [
            'stats' => Plugin::getInstance()->overview->getStats(),
            'currency' => Commerce::getInstance()->getStores()->getPrimaryStore()?->getCurrency()?->getCode(),
        ]);
    }
}
