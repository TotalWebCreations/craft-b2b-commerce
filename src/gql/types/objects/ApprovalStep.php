<?php

namespace totalwebcreations\b2bcommerce\gql\types\objects;

use craft\gql\GqlEntityRegistry;
use craft\gql\types\DateTime;
use GraphQL\Type\Definition\ObjectType;
use GraphQL\Type\Definition\Type;

/**
 * One resolved-or-pending step in an approval's sequential ladder (phase 18). Resolved from the array
 * the Approvals service returns for the approval's order.
 */
class ApprovalStep
{
    public static function getName(): string
    {
        return 'B2bApprovalStep';
    }

    public static function getType(): ObjectType
    {
        return GqlEntityRegistry::getOrCreate(self::getName(), fn() => new ObjectType([
            'name' => self::getName(),
            'description' => 'One step in an approval’s sequential ladder.',
            'fields' => [
                'level' => [
                    'type' => Type::int(),
                    'description' => 'The step level (1 opens first; level N opens after level N−1 approves).',
                ],
                'status' => [
                    'type' => Type::string(),
                    'description' => 'The step status (pending, approved or declined).',
                ],
                'resolvedByName' => [
                    'type' => Type::string(),
                    'description' => 'The name of the member who resolved this step, if resolved.',
                ],
                'reason' => [
                    'type' => Type::string(),
                    'description' => 'The decline reason for this step, if declined.',
                ],
                'dateResolved' => [
                    'type' => DateTime::getType(),
                    'description' => 'When the step was resolved, if resolved.',
                ],
            ],
        ]));
    }
}
