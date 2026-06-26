<?php

namespace totalwebcreations\b2bcommerce\controllers\concerns;

use Craft;
use totalwebcreations\b2bcommerce\Plugin;
use yii\web\Response;

/**
 * Gates a controller action behind a plugin feature toggle. Returns a clean
 * failure response when the feature is disabled, or null when it is enabled so
 * the caller can proceed. Keeps the enforcement point for each feature in one
 * place across the controllers that expose it.
 */
trait RequiresFeature
{
    protected function requireFeature(string $settingName): ?Response
    {
        if (Plugin::getInstance()->getSettings()->{$settingName}) {
            return null;
        }

        return $this->asFailure(
            Craft::t('b2b-commerce', 'This feature is not enabled.')
        );
    }
}
