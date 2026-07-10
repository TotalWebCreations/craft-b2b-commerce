<?php

namespace totalwebcreations\b2bcommerce\gql\mutations;

use craft\gql\base\Mutation;
use GraphQL\Type\Definition\Type;
use totalwebcreations\b2bcommerce\gql\helpers\Gql as GqlHelper;
use totalwebcreations\b2bcommerce\gql\resolvers\mutations\OrderList as OrderListResolver;

/**
 * Order-list write mutations (create / rename / add item). Registered only when the active schema has
 * the opt-in `b2bContext.write` component. Each field is a thin wrapper over the OrderLists service —
 * no business logic here.
 */
class OrderList extends Mutation
{
    public static function getMutations(): array
    {
        if (!GqlHelper::canWriteB2bContext()) {
            return [];
        }

        $resolver = new OrderListResolver();

        return [
            'createOrderList' => [
                'name' => 'createOrderList',
                'description' => 'Create a saved order list for the current company. Returns the new list ID. Calls OrderLists::createList.',
                'type' => Type::int(),
                'args' => [
                    'name' => [
                        'name' => 'name',
                        'type' => Type::nonNull(Type::string()),
                        'description' => 'The list name.',
                    ],
                ],
                'resolve' => [$resolver, 'createOrderList'],
            ],
            'renameOrderList' => [
                'name' => 'renameOrderList',
                'description' => 'Rename one of the current company’s order lists. Calls OrderLists::renameList.',
                'type' => Type::boolean(),
                'args' => [
                    'listId' => [
                        'name' => 'listId',
                        'type' => Type::nonNull(Type::int()),
                        'description' => 'The order list ID.',
                    ],
                    'name' => [
                        'name' => 'name',
                        'type' => Type::nonNull(Type::string()),
                        'description' => 'The new list name.',
                    ],
                ],
                'resolve' => [$resolver, 'renameOrderList'],
            ],
            'addOrderListItem' => [
                'name' => 'addOrderListItem',
                'description' => 'Set the quantity of a purchasable on one of the current company’s order lists (0 removes it). Calls OrderLists::setItem.',
                'type' => Type::boolean(),
                'args' => [
                    'listId' => [
                        'name' => 'listId',
                        'type' => Type::nonNull(Type::int()),
                        'description' => 'The order list ID.',
                    ],
                    'purchasableId' => [
                        'name' => 'purchasableId',
                        'type' => Type::nonNull(Type::int()),
                        'description' => 'The purchasable (variant) ID.',
                    ],
                    'qty' => [
                        'name' => 'qty',
                        'type' => Type::nonNull(Type::int()),
                        'description' => 'The quantity to set (0 removes the item).',
                    ],
                ],
                'resolve' => [$resolver, 'addOrderListItem'],
            ],
        ];
    }
}
