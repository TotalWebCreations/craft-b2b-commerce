<?php

namespace totalwebcreations\b2bcommerce\gql\types\objects;

use craft\gql\GqlEntityRegistry;
use GraphQL\Type\Definition\ObjectType;
use GraphQL\Type\Definition\Type;

/**
 * The company's credit position: what it owes, its limit and the room left. Resolved from the array
 * the CreditBalance service returns; `creditLimit` and `available` are null when no limit is set.
 */
class CreditSummary
{
    public static function getName(): string
    {
        return 'B2bCreditSummary';
    }

    public static function getType(): ObjectType
    {
        return GqlEntityRegistry::getOrCreate(self::getName(), fn() => new ObjectType([
            'name' => self::getName(),
            'description' => 'The current user’s company credit position.',
            'fields' => [
                'outstanding' => [
                    'type' => Type::float(),
                    'description' => 'The total unpaid balance across the company’s invoice orders.',
                ],
                'creditLimit' => [
                    'type' => Type::float(),
                    'description' => 'The company’s credit limit, or null when none is set.',
                ],
                'available' => [
                    'type' => Type::float(),
                    'description' => 'The room left under the credit limit (never below zero), or null when no limit is set.',
                ],
            ],
        ]));
    }
}
