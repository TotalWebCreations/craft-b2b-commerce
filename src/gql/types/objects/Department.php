<?php

namespace totalwebcreations\b2bcommerce\gql\types\objects;

use craft\gql\GqlEntityRegistry;
use GraphQL\Type\Definition\ObjectType;
use GraphQL\Type\Definition\Type;

/**
 * A department of the current user's company: its own spend budget and approval routing (phase 19).
 * Resolved from the array the Departments service returns; scoped to the caller's company by the
 * B2bContext resolver, so this type can never surface another company's departments.
 */
class Department
{
    public static function getName(): string
    {
        return 'B2bDepartment';
    }

    public static function getType(): ObjectType
    {
        return GqlEntityRegistry::getOrCreate(self::getName(), fn() => new ObjectType([
            'name' => self::getName(),
            'description' => 'A department belonging to the current user’s company.',
            'fields' => [
                'id' => [
                    'type' => Type::int(),
                    'description' => 'The department ID.',
                ],
                'name' => [
                    'type' => Type::string(),
                    'description' => 'The department name.',
                ],
                'budgetAmount' => [
                    'type' => Type::float(),
                    'description' => 'The department’s spend cap for the period, or null when unlimited.',
                ],
                'budgetPeriod' => [
                    'type' => Type::string(),
                    'description' => 'The budget period (none, monthly, quarterly or yearly).',
                ],
                'approverUserId' => [
                    'type' => Type::int(),
                    'description' => 'The ID of the department’s designated approver, if any.',
                ],
            ],
        ]));
    }
}
