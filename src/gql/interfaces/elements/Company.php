<?php

namespace totalwebcreations\b2bcommerce\gql\interfaces\elements;

use Craft;
use craft\gql\GqlEntityRegistry;
use craft\gql\interfaces\Element;
use GraphQL\Type\Definition\InterfaceType;
use GraphQL\Type\Definition\ResolveInfo;
use GraphQL\Type\Definition\Type;
use totalwebcreations\b2bcommerce\elements\Company as CompanyElement;
use totalwebcreations\b2bcommerce\gql\helpers\Gql as GqlHelper;
use totalwebcreations\b2bcommerce\gql\types\generators\CompanyType;
use totalwebcreations\b2bcommerce\Plugin;

/**
 * The GraphQL interface implemented by the Company element type. Mirrors how Craft's User element
 * exposes a single-type interface (there are no company sub-types, so one interface backs one
 * generated object type).
 *
 * Sensitive financial fields (see {@see self::FINANCIAL_FIELDS}) are gated per field. Each carries a
 * field-level resolver — {@see self::resolveFinancialField()} — that returns null unless the caller
 * is allowed to see them. This gating lives on the interface field definitions (which the generated
 * `Company` object type inherits), so it applies identically whether the field is reached through the
 * global `companies`/`company` element query or through `b2bContext.company`. Non-sensitive identity
 * fields (name, registrationNumber, status, id) stay readable under the plain `b2bCompanies.all`
 * scope. Mirrors how Craft's User interface attaches its own resolver to the `addresses` field.
 */
class Company extends Element
{
    /**
     * The sensitive per-company financial fields. Readable only when the caller owns the company (the
     * signed-in user's own company, always allowed and served by `b2bContext`) or the active schema
     * has the dedicated `b2bCompanies.financials` scope; otherwise they resolve to null.
     *
     * @var list<string>
     */
    public const FINANCIAL_FIELDS = [
        'taxId',
        'creditLimit',
        'paymentTermDays',
        'allowInvoicePayment',
        'approvalThreshold',
    ];

    public static function getTypeGenerator(): string
    {
        return CompanyType::class;
    }

    public static function getType(): Type
    {
        if ($type = GqlEntityRegistry::getEntity(self::getName())) {
            return $type;
        }

        $type = GqlEntityRegistry::createEntity(self::getName(), new InterfaceType([
            'name' => static::getName(),
            'fields' => self::class . '::getFieldDefinitions',
            'description' => 'This is the interface implemented by companies.',
            'resolveType' => fn(CompanyElement $value): string => $value->getGqlTypeName(),
        ]));

        CompanyType::generateTypes();

        return $type;
    }

    public static function getName(): string
    {
        return 'CompanyInterface';
    }

    public static function getFieldDefinitions(): array
    {
        return Craft::$app->getGql()->prepareFieldDefinitions(array_merge(parent::getFieldDefinitions(), [
            'name' => [
                'name' => 'name',
                'type' => Type::string(),
                'description' => 'The company’s name (its title).',
            ],
            'registrationNumber' => [
                'name' => 'registrationNumber',
                'type' => Type::string(),
                'description' => 'The company’s registration number.',
            ],
            'taxId' => [
                'name' => 'taxId',
                'type' => Type::string(),
                'description' => 'The company’s VAT / tax ID. Only readable for your own company or with the `b2bCompanies.financials` scope; otherwise null.',
                'resolve' => [self::class, 'resolveFinancialField'],
            ],
            'status' => [
                'name' => 'status',
                'type' => Type::string(),
                'description' => 'The company’s approval status (pending, approved or blocked).',
            ],
            'creditLimit' => [
                'name' => 'creditLimit',
                'type' => Type::float(),
                'description' => 'The company’s credit limit for pay-on-account orders. Only readable for your own company or with the `b2bCompanies.financials` scope; otherwise null.',
                'resolve' => [self::class, 'resolveFinancialField'],
            ],
            'paymentTermDays' => [
                'name' => 'paymentTermDays',
                'type' => Type::int(),
                'description' => 'The number of days the company has to settle an invoice. Only readable for your own company or with the `b2bCompanies.financials` scope; otherwise null.',
                'resolve' => [self::class, 'resolveFinancialField'],
            ],
            'allowInvoicePayment' => [
                'name' => 'allowInvoicePayment',
                'type' => Type::boolean(),
                'description' => 'Whether the company may pay on account (by invoice). Only readable for your own company or with the `b2bCompanies.financials` scope; otherwise null.',
                'resolve' => [self::class, 'resolveFinancialField'],
            ],
            'approvalThreshold' => [
                'name' => 'approvalThreshold',
                'type' => Type::float(),
                'description' => 'The order total above which a member’s order needs approval. Only readable for your own company or with the `b2bCompanies.financials` scope; otherwise null.',
                'resolve' => [self::class, 'resolveFinancialField'],
            ],
        ]), self::getName());
    }

    /**
     * Field-level resolver for the sensitive financial fields. Returns the underlying company
     * attribute only when the caller may read it (see {@see self::canReadFinancials()}); otherwise
     * null, so enabling `b2bCompanies.all` alone never leaks financials of companies the caller does
     * not own.
     */
    public static function resolveFinancialField(CompanyElement $company, array $arguments, mixed $context, ResolveInfo $resolveInfo): mixed
    {
        if (!self::canReadFinancials($company)) {
            return null;
        }

        return $company->{$resolveInfo->fieldName};
    }

    /**
     * Whether the current caller may read the given company's financial fields. Allowed when the
     * active schema has the dedicated `b2bCompanies.financials` scope, or when the company is the
     * signed-in user's own company (a caller reading their OWN financials is always permitted — the
     * same data `b2bContext` surfaces). A guest, or a user reading another company, gets null.
     */
    private static function canReadFinancials(CompanyElement $company): bool
    {
        if (GqlHelper::canReadCompanyFinancials()) {
            return true;
        }

        $user = Craft::$app->getUser()->getIdentity();

        if ($user === null) {
            return false;
        }

        $ownCompany = Plugin::getInstance()->companyMembers->getCompanyForUser($user->id);

        return $ownCompany !== null && $ownCompany->id === $company->id;
    }
}
