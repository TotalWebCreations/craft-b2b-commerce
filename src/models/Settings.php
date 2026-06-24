<?php

namespace totalwebcreations\b2bcommerce\models;

use Craft;
use craft\base\Model;

class Settings extends Model
{
    /**
     * Real registration form fields the honeypot must never collide with.
     */
    private const RESERVED_FIELD_NAMES = [
        'companyName',
        'registrationNumber',
        'taxId',
        'firstName',
        'lastName',
        'email',
    ];

    public bool $enableCompanies = true;
    public bool $enableQuotes = true;
    public bool $enableApprovals = true;
    public bool $enableInvoicing = true;
    public bool $enableQuickOrder = true;
    public bool $hidePricesForGuests = false;
    public string $adminNotificationEmail = '';
    public string $honeypotFieldName = 'b2b_website';

    public function defineRules(): array
    {
        return [
            ['adminNotificationEmail', 'email'],
            ['honeypotFieldName', 'required'],
            ['honeypotFieldName', 'validateHoneypotFieldName'],
        ];
    }

    public function validateHoneypotFieldName(string $attribute): void
    {
        if (in_array($this->{$attribute}, self::RESERVED_FIELD_NAMES, true)) {
            $this->addError($attribute, Craft::t('b2b-commerce', 'The honeypot field name cannot match a real registration field.'));
        }
    }
}
