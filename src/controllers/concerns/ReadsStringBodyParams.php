<?php

namespace totalwebcreations\b2bcommerce\controllers\concerns;

use Craft;

/**
 * Reads body params as strings without ever casting a non-string (e.g. an
 * array a bot posts as `field[]=x`) straight to string, which would raise an
 * "Array to string conversion" error and surface as a 500. A non-string value
 * collapses to the default instead, so downstream validation can reject it
 * cleanly as a normal empty/invalid input.
 */
trait ReadsStringBodyParams
{
    protected function stringBodyParam(string $name, string $default = ''): string
    {
        $value = Craft::$app->getRequest()->getBodyParam($name, $default);

        return is_string($value) ? $value : $default;
    }

    /**
     * Like stringBodyParam, but requires the param to be present: a missing param
     * raises the usual 400 (BadRequestHttpException). A present-but-non-string
     * value collapses to '' so it fails downstream validation rather than 500-ing.
     */
    protected function requiredStringBodyParam(string $name): string
    {
        $value = Craft::$app->getRequest()->getRequiredBodyParam($name);

        return is_string($value) ? $value : '';
    }
}
