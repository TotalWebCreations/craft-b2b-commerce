<?php

namespace totalwebcreations\b2bcommerce\gql\types\objects;

use craft\gql\GqlEntityRegistry;
use GraphQL\Type\Definition\ObjectType;
use GraphQL\Type\Definition\Type;

/**
 * An amount-tiered approval band for the current user's company (phase 18): at or above `minAmount`,
 * the `level` step becomes required. Resolved from the array the ApprovalTiers service returns.
 */
class ApprovalTier
{
    public static function getName(): string
    {
        return 'B2bApprovalTier';
    }

    public static function getType(): ObjectType
    {
        return GqlEntityRegistry::getOrCreate(self::getName(), fn() => new ObjectType([
            'name' => self::getName(),
            'description' => 'An amount-tiered approval band belonging to the current user’s company.',
            'fields' => [
                'level' => [
                    'type' => Type::int(),
                    'description' => 'The step level in the sequential ladder (1 is signed first).',
                    'resolve' => static fn(array $tier): ?int => isset($tier['level']) ? (int) $tier['level'] : null,
                ],
                'minAmount' => [
                    'type' => Type::float(),
                    'description' => 'The order total at or above which this level becomes required.',
                    'resolve' => static fn(array $tier): ?float => isset($tier['minAmount']) ? (float) $tier['minAmount'] : null,
                ],
                'approverRole' => [
                    'type' => Type::string(),
                    'description' => 'The role eligible to sign this level.',
                ],
                'departmentScoped' => [
                    'type' => Type::boolean(),
                    'description' => 'Whether this level’s approvers are resolved within the requester’s department first.',
                    'resolve' => static fn(array $tier): bool => (bool) ($tier['departmentScoped'] ?? false),
                ],
            ],
        ]));
    }
}
