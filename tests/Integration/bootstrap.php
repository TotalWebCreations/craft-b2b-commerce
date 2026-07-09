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

    $devPath = b2bTestSitePath();

    // Vendor code still carries PHP 8.4 implicit-nullable signatures whose
    // one-time compile-time deprecations would otherwise clutter Pest's output.
    // Chain in front of PHPUnit's own error handler to swallow those (and only
    // those), while every issue from our own code keeps flowing through.
    muteVendorDeprecations();

    // The dev bootstrap prints nothing and never calls exit(), but we buffer
    // defensively so a stray notice can never corrupt Pest's own output.
    ob_start();
    require $devPath . '/bootstrap.php';
    $app = require CRAFT_VENDOR_PATH . '/craftcms/cms/bootstrap/console.php';
    ob_end_clean();

    // The console user component lacks the web-only impersonation API that storefront code
    // (SalesReps + the completion-time linkCompany backstop) calls. Attach a test-only shim once,
    // universally, so every test — not just impersonation tests — has a null-by-default
    // impersonator. See attachImpersonationTestShim() in helpers.php for the full rationale.
    attachImpersonationTestShim($app->getUser());

    return $app;
}

/**
 * Reports whether the sibling dev site is present, so the integration suite
 * can skip itself cleanly on machines without it.
 */
function devSiteAvailable(): bool
{
    return file_exists(b2bTestSitePath() . '/bootstrap.php');
}

/**
 * Absolute path to the sibling Craft site the integration/HTTP suites run
 * against. Defaults to the dev site (../b2b-dev) but can be repointed at any
 * sibling install (e.g. the release-gate QA site) via the B2B_TEST_SITE_PATH
 * environment variable. A relative value is resolved against the plugin root.
 */
function b2bTestSitePath(): string
{
    $configured = getenv('B2B_TEST_SITE_PATH');

    if ($configured === false || $configured === '') {
        return dirname(__DIR__, 3) . '/b2b-dev';
    }

    if (str_starts_with($configured, '/')) {
        return rtrim($configured, '/');
    }

    return dirname(__DIR__, 2) . '/' . rtrim($configured, '/');
}

/**
 * Installs an error handler, once, that discards PHP deprecations originating
 * from vendor files and delegates everything else to whatever handler was
 * already in place (PHPUnit's, by the time the suite runs). PHPUnit forces its
 * own error_reporting level during a run, so muting deprecations there has no
 * effect; chaining a handler is the only reliable way to keep vendor noise out.
 */
function muteVendorDeprecations(): void
{
    static $installed = false;

    if ($installed) {
        return;
    }

    $installed = true;

    $previous = set_error_handler(
        static function (int $errno, string $errstr, string $errfile, int $errline) use (&$previous): bool {
            if ($errno === E_DEPRECATED && str_contains($errfile, '/vendor/')) {
                return true;
            }

            if ($previous === null) {
                return false;
            }

            return (bool) $previous($errno, $errstr, $errfile, $errline);
        }
    );
}
