<?php

namespace totalwebcreations\b2bcommerce\gql\types\objects;

use craft\gql\GqlEntityRegistry;
use GraphQL\Type\Definition\ObjectType;
use GraphQL\Type\Definition\Type;

/**
 * The authenticated member's own spending budget for their company. A plain aggregate (not an
 * element), so it is a bare object type resolved from the array the Budgets service returns.
 */
class MemberBudget
{
    public static function getName(): string
    {
        return 'B2bMemberBudget';
    }

    public static function getType(): ObjectType
    {
        return GqlEntityRegistry::getOrCreate(self::getName(), fn() => new ObjectType([
            'name' => self::getName(),
            'description' => 'The current user’s spending budget for their company.',
            'fields' => [
                'amount' => [
                    'type' => Type::float(),
                    'description' => 'The budget cap for the period.',
                ],
                'period' => [
                    'type' => Type::string(),
                    'description' => 'The budget period (none, monthly, quarterly or yearly).',
                ],
                'spent' => [
                    'type' => Type::float(),
                    'description' => 'The amount the member has spent in the current period.',
                ],
                'remaining' => [
                    'type' => Type::float(),
                    'description' => 'The room left under the budget, never below zero.',
                ],
            ],
        ]));
    }
}
