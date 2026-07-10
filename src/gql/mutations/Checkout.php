<?php

namespace totalwebcreations\b2bcommerce\gql\mutations;

use craft\gql\base\Mutation;
use GraphQL\Type\Definition\Type;
use totalwebcreations\b2bcommerce\gql\helpers\Gql as GqlHelper;
use totalwebcreations\b2bcommerce\gql\resolvers\mutations\Checkout as CheckoutResolver;

/**
 * Checkout write mutations (buyer PO number). Registered only when the active schema has the opt-in
 * `b2bContext.write` component; otherwise no field is added to the Mutation type. Each field is a thin
 * wrapper over the phase-15 checkout service — no business logic lives here.
 */
class Checkout extends Mutation
{
    public static function getMutations(): array
    {
        if (!GqlHelper::canWriteB2bContext()) {
            return [];
        }

        $resolver = new CheckoutResolver();

        return [
            'setPoNumber' => [
                'name' => 'setPoNumber',
                'description' => 'Set the buyer purchase-order number on the current cart. Calls the same checkout service as the storefront set-reference action.',
                'type' => Type::string(),
                'args' => [
                    'poNumber' => [
                        'name' => 'poNumber',
                        'type' => Type::nonNull(Type::string()),
                        'description' => 'The buyer purchase-order number.',
                    ],
                ],
                'resolve' => [$resolver, 'setPoNumber'],
            ],
        ];
    }
}
