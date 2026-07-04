<?php

namespace totalwebcreations\b2bcommerce\gql\types\objects;

use craft\gql\GqlEntityRegistry;
use GraphQL\Type\Definition\ObjectType;
use GraphQL\Type\Definition\Type;

/**
 * A saved order list (quick-order list) belonging to the current user's company. Resolved from the
 * array the OrderLists service returns.
 */
class OrderList
{
    public static function getName(): string
    {
        return 'B2bOrderList';
    }

    public static function getType(): ObjectType
    {
        return GqlEntityRegistry::getOrCreate(self::getName(), fn() => new ObjectType([
            'name' => self::getName(),
            'description' => 'A saved order list belonging to the current user’s company.',
            'fields' => [
                'id' => [
                    'type' => Type::int(),
                    'description' => 'The order list ID.',
                ],
                'name' => [
                    'type' => Type::string(),
                    'description' => 'The order list name.',
                ],
                'createdByUserId' => [
                    'type' => Type::int(),
                    'description' => 'The ID of the user who created the list, if known.',
                ],
                'itemCount' => [
                    'type' => Type::int(),
                    'description' => 'The number of items on the list.',
                ],
            ],
        ]));
    }
}
