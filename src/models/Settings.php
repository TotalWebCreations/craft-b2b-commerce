<?php

namespace totalwebcreations\b2bcommerce\models;

use Craft;
use craft\base\Model;

class Settings extends Model
{
    public const TAX_ID_POLICY_LENIENT = 'lenient';
    public const TAX_ID_POLICY_STRICT = 'strict';

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
    public bool $validateTaxIds = false;

    /**
     * Site template path used to render the quote PDF. Empty falls back to the bundled example
     * template (b2b/pdf/quote.twig). Copy the example into your own templates folder to restyle it.
     */
    public string $quotePdfTemplate = '';

    /**
     * Site template path used to render the order/invoice PDF. Empty falls back to the bundled
     * example template (b2b/pdf/invoice.twig).
     */
    public string $invoicePdfTemplate = '';

    /**
     * What to do when VIES is unreachable while a company VAT id is being validated:
     * lenient = accept the VAT id and log a warning, strict = refuse the save with a clean error.
     * A definitively invalid VAT id is refused under both policies.
     */
    public string $taxIdValidationPolicy = self::TAX_ID_POLICY_LENIENT;

    /**
     * Commerce order-status handles whose orders never count towards a company's outstanding
     * balance. A cancelled or refunded order is settled: its receivable is gone, so leaving it in
     * the sum would be phantom debt that eats into the company's real credit room.
     *
     * Stored as an array of handles. The settings screen edits it as a comma-separated text field
     * and {@see setExcludedOrderStatusHandles()} normalises that string back into this array.
     *
     * @var string[]
     */
    private array $excludedOrderStatusHandles = ['cancelled', 'refunded'];

    /**
     * Registers the virtual, getter/setter-backed excludedOrderStatusHandles attribute so Craft's
     * settings save reaches its normaliser and the settings template can read it back.
     */
    public function attributes(): array
    {
        return array_merge(parent::attributes(), ['excludedOrderStatusHandles']);
    }

    public function defineRules(): array
    {
        return [
            ['adminNotificationEmail', 'email'],
            ['honeypotFieldName', 'required'],
            ['honeypotFieldName', 'validateHoneypotFieldName'],
            ['excludedOrderStatusHandles', 'validateExcludedOrderStatusHandles'],
            ['taxIdValidationPolicy', 'in', 'range' => [self::TAX_ID_POLICY_LENIENT, self::TAX_ID_POLICY_STRICT]],
        ];
    }

    /** @return string[] */
    public function getExcludedOrderStatusHandles(): array
    {
        return $this->excludedOrderStatusHandles;
    }

    /**
     * Accepts either the normalised array or the raw comma-separated string the settings text field
     * posts, trimming blanks so a stray comma or trailing space never becomes an empty handle.
     *
     * @param string[]|string $value
     */
    public function setExcludedOrderStatusHandles(array|string $value): void
    {
        if (is_string($value)) {
            $value = explode(',', $value);
        }

        $this->excludedOrderStatusHandles = array_values(array_filter(array_map('trim', $value)));
    }

    public function validateExcludedOrderStatusHandles(string $attribute): void
    {
        foreach ($this->{$attribute} as $handle) {
            if (!is_string($handle) || $handle === '') {
                $this->addError($attribute, Craft::t('b2b-commerce', 'Order status handles must be non-empty strings.'));

                return;
            }
        }
    }

    public function validateHoneypotFieldName(string $attribute): void
    {
        if (in_array($this->{$attribute}, self::RESERVED_FIELD_NAMES, true)) {
            $this->addError($attribute, Craft::t('b2b-commerce', 'The honeypot field name cannot match a real registration field.'));
        }
    }
}
