<?php

namespace totalwebcreations\b2bcommerce\gql\queries;

use craft\gql\base\Query;
use GraphQL\Type\Definition\Type;
use totalwebcreations\b2bcommerce\gql\arguments\elements\Company as CompanyArguments;
use totalwebcreations\b2bcommerce\gql\helpers\Gql as GqlHelper;
use totalwebcreations\b2bcommerce\gql\interfaces\elements\Company as CompanyInterface;
use totalwebcreations\b2bcommerce\gql\resolvers\elements\Company as CompanyResolver;

/**
 * The Company element queries. Registered only when the active schema has the `b2bCompanies.all`
 * component, so a schema that hasn't opted in cannot query companies at all — the fields aren't in
 * its schema definition. Mirrors how Commerce registers its product queries behind a scope check.
 */
class Company extends Query
{
    public static function getQueries(bool $checkToken = true): array
    {
        if ($checkToken && !GqlHelper::canQueryCompanies()) {
            return [];
        }

        return [
            'companies' => [
                'type' => Type::listOf(CompanyInterface::getType()),
                'args' => CompanyArguments::getArguments(),
                'resolve' => CompanyResolver::class . '::resolve',
                'description' => 'This query is used to query for companies.',
            ],
            'companyCount' => [
                'type' => Type::nonNull(Type::int()),
                'args' => CompanyArguments::getArguments(),
                'resolve' => CompanyResolver::class . '::resolveCount',
                'description' => 'This query is used to return the number of companies.',
            ],
            'company' => [
                'type' => CompanyInterface::getType(),
                'args' => CompanyArguments::getArguments(),
                'resolve' => CompanyResolver::class . '::resolveOne',
                'description' => 'This query is used to query for a single company.',
            ],
        ];
    }
}
