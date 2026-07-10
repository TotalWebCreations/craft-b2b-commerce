<?php

namespace totalwebcreations\b2bcommerce\gql\mutations;

use craft\gql\base\Mutation;
use GraphQL\Type\Definition\Type;
use totalwebcreations\b2bcommerce\gql\helpers\Gql as GqlHelper;
use totalwebcreations\b2bcommerce\gql\resolvers\mutations\Approval as ApprovalResolver;

/**
 * Approval write mutations (submit / approve a step / decline a step). Registered only when the
 * active schema has the opt-in `b2bContext.write` component. Each field is a thin wrapper over the
 * Approvals service — no business logic here.
 */
class Approval extends Mutation
{
    public static function getMutations(): array
    {
        if (!GqlHelper::canWriteB2bContext()) {
            return [];
        }

        $resolver = new ApprovalResolver();

        return [
            'submitForApproval' => [
                'name' => 'submitForApproval',
                'description' => 'Submit the current cart for approval. Calls Approvals::submitForApproval.',
                'type' => Type::boolean(),
                'args' => [],
                'resolve' => [$resolver, 'submitForApproval'],
            ],
            'approveOrder' => [
                'name' => 'approveOrder',
                'description' => 'Approve the currently-open step of an order’s approval. Calls Approvals::approve.',
                'type' => Type::boolean(),
                'args' => [
                    'orderId' => [
                        'name' => 'orderId',
                        'type' => Type::nonNull(Type::int()),
                        'description' => 'The ID of the order awaiting approval.',
                    ],
                ],
                'resolve' => [$resolver, 'approveOrder'],
            ],
            'declineOrder' => [
                'name' => 'declineOrder',
                'description' => 'Decline the currently-open step of an order’s approval. Calls Approvals::decline.',
                'type' => Type::boolean(),
                'args' => [
                    'orderId' => [
                        'name' => 'orderId',
                        'type' => Type::nonNull(Type::int()),
                        'description' => 'The ID of the order awaiting approval.',
                    ],
                    'reason' => [
                        'name' => 'reason',
                        'type' => Type::string(),
                        'description' => 'Optional decline reason.',
                    ],
                ],
                'resolve' => [$resolver, 'declineOrder'],
            ],
        ];
    }
}
