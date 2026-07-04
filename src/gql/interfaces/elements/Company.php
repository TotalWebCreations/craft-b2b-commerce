<?php

namespace totalwebcreations\b2bcommerce\gql\interfaces\elements;

use Craft;
use craft\gql\GqlEntityRegistry;
use craft\gql\interfaces\Element;
use GraphQL\Type\Definition\InterfaceType;
use GraphQL\Type\Definition\Type;
use totalwebcreations\b2bcommerce\elements\Company as CompanyElement;
use totalwebcreations\b2bcommerce\gql\types\generators\CompanyType;

/**
 * The GraphQL interface implemented by the Company element type. Mirrors how Craft's User element
 * exposes a single-type interface (there are no company sub-types, so one interface backs one
 * generated object type).
 */
class Company extends Element
{
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
                'description' => 'The company’s VAT / tax ID.',
            ],
            'status' => [
                'name' => 'status',
                'type' => Type::string(),
                'description' => 'The company’s approval status (pending, approved or blocked).',
            ],
            'creditLimit' => [
                'name' => 'creditLimit',
                'type' => Type::float(),
                'description' => 'The company’s credit limit for pay-on-account orders.',
            ],
            'paymentTermDays' => [
                'name' => 'paymentTermDays',
                'type' => Type::int(),
                'description' => 'The number of days the company has to settle an invoice.',
            ],
            'allowInvoicePayment' => [
                'name' => 'allowInvoicePayment',
                'type' => Type::boolean(),
                'description' => 'Whether the company may pay on account (by invoice).',
            ],
            'approvalThreshold' => [
                'name' => 'approvalThreshold',
                'type' => Type::float(),
                'description' => 'The order total above which a member’s order needs approval.',
            ],
        ]), self::getName());
    }
}
