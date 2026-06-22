<?php

namespace totalwebcreations\b2bcommerce\models;

use craft\base\Model;

class Settings extends Model
{
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
        ];
    }
}
