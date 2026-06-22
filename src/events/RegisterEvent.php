<?php

namespace totalwebcreations\b2bcommerce\events;

use craft\events\CancelableEvent;

/**
 * Fired before a B2B registration is processed. Set `$isValid` to `false`
 * to cancel the registration; the registration service then throws a
 * generic exception and creates nothing.
 */
class RegisterEvent extends CancelableEvent
{
    public string $companyName = '';
    public ?string $registrationNumber = null;
    public ?string $taxId = null;
    public string $firstName = '';
    public string $lastName = '';
    public string $email = '';
}
