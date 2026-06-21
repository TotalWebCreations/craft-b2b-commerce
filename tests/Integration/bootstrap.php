<?php

use craft\console\Application;

/**
 * Boots the sibling b2b-dev Craft application once and memoizes it.
 *
 * The dev site lives next to the plugin at ../b2b-dev. Its bootstrap.php
 * defines the CRAFT_* path constants and loads the autoloader, after which
 * Craft's console bootstrap returns a ready console Application instance.
 */
function craftApp(): Application
{
    static $app = null;

    if ($app !== null) {
        return $app;
    }

    $devPath = dirname(__DIR__, 3) . '/b2b-dev';

    // The dev bootstrap prints nothing and never calls exit(), but we buffer
    // defensively so a stray notice can never corrupt Pest's own output.
    ob_start();
    require $devPath . '/bootstrap.php';
    $app = require CRAFT_VENDOR_PATH . '/craftcms/cms/bootstrap/console.php';
    ob_end_clean();

    return $app;
}

/**
 * Reports whether the sibling dev site is present, so the integration suite
 * can skip itself cleanly on machines without it.
 */
function devSiteAvailable(): bool
{
    return file_exists(dirname(__DIR__, 3) . '/b2b-dev/bootstrap.php');
}
