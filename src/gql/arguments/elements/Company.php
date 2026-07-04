<?php

namespace totalwebcreations\b2bcommerce\gql\arguments\elements;

use Craft;
use craft\base\GqlInlineFragmentFieldInterface;
use craft\gql\base\ElementArguments;
use craft\gql\types\QueryArgument;
use GraphQL\Type\Definition\Type;
use totalwebcreations\b2bcommerce\elements\Company as CompanyElement;

/**
 * Query arguments for the Company element type: the standard element arguments plus the company’s
 * own native attributes and any custom-field query arguments from its field layout.
 */
class Company extends ElementArguments
{
    public static function getArguments(): array
    {
        return array_merge(parent::getArguments(), self::getContentArguments(), [
            'registrationNumber' => [
                'name' => 'registrationNumber',
                'type' => Type::listOf(QueryArgument::getType()),
                'description' => 'Narrows the query results based on the company registration number.',
            ],
            'taxId' => [
                'name' => 'taxId',
                'type' => Type::listOf(QueryArgument::getType()),
                'description' => 'Narrows the query results based on the company VAT / tax ID.',
            ],
            'companyStatus' => [
                'name' => 'companyStatus',
                'type' => Type::listOf(Type::string()),
                'description' => 'Narrows the query results based on the company approval status.',
            ],
        ]);
    }

    public static function getContentArguments(): array
    {
        $contentArguments = [];

        $contentFields = Craft::$app->getFields()->getLayoutByType(CompanyElement::class)->getCustomFields();

        foreach ($contentFields as $contentField) {
            if (!$contentField instanceof GqlInlineFragmentFieldInterface) {
                $contentArguments[$contentField->handle] = $contentField->getContentGqlQueryArgumentType();
            }
        }

        return array_merge(parent::getContentArguments(), $contentArguments);
    }
}
