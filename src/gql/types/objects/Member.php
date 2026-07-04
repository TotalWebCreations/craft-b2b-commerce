<?php

namespace totalwebcreations\b2bcommerce\gql\types\objects;

use craft\gql\GqlEntityRegistry;
use craft\gql\interfaces\elements\User as UserInterface;
use GraphQL\Type\Definition\ObjectType;
use GraphQL\Type\Definition\Type;

/**
 * A member of the current user's company: the Craft user plus their B2B role. `user` reuses Craft's
 * own User GraphQL interface, so id / email / name are resolved by core.
 */
class Member
{
    public static function getName(): string
    {
        return 'B2bMember';
    }

    public static function getType(): ObjectType
    {
        return GqlEntityRegistry::getOrCreate(self::getName(), fn() => new ObjectType([
            'name' => self::getName(),
            'description' => 'A member of the current user’s company.',
            'fields' => [
                'user' => [
                    'type' => UserInterface::getType(),
                    'description' => 'The member’s Craft user (id, email, name).',
                ],
                'role' => [
                    'type' => Type::string(),
                    'description' => 'The member’s role (admin, purchaser or approver).',
                ],
            ],
        ]));
    }
}
