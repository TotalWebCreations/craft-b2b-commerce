<?php

namespace totalwebcreations\b2bcommerce\gql\mutations;

use craft\gql\base\Mutation;
use GraphQL\Type\Definition\Type;
use totalwebcreations\b2bcommerce\gql\helpers\Gql as GqlHelper;
use totalwebcreations\b2bcommerce\gql\resolvers\mutations\Quote as QuoteResolver;

/**
 * Quote write mutations (request / accept / decline). Registered only when the active schema has the
 * opt-in `b2bContext.write` component. Each field is a thin wrapper over the Quotes service — no
 * business logic here.
 */
class Quote extends Mutation
{
    public static function getMutations(): array
    {
        if (!GqlHelper::canWriteB2bContext()) {
            return [];
        }

        $resolver = new QuoteResolver();

        return [
            'requestQuote' => [
                'name' => 'requestQuote',
                'description' => 'Request a quote for the current cart. Calls Quotes::requestQuote.',
                'type' => Type::boolean(),
                'args' => [
                    'notes' => [
                        'name' => 'notes',
                        'type' => Type::string(),
                        'description' => 'Optional notes for the merchant.',
                    ],
                ],
                'resolve' => [$resolver, 'requestQuote'],
            ],
            'acceptQuote' => [
                'name' => 'acceptQuote',
                'description' => 'Accept a sent quote by its token and adopt the frozen-price cart. Returns the cart number. Calls Quotes::acceptByToken.',
                'type' => Type::string(),
                'args' => [
                    'token' => [
                        'name' => 'token',
                        'type' => Type::nonNull(Type::string()),
                        'description' => 'The quote accept token.',
                    ],
                ],
                'resolve' => [$resolver, 'acceptQuote'],
            ],
            'declineQuote' => [
                'name' => 'declineQuote',
                'description' => 'Decline a sent quote by its token. Calls Quotes::declineByToken.',
                'type' => Type::boolean(),
                'args' => [
                    'token' => [
                        'name' => 'token',
                        'type' => Type::nonNull(Type::string()),
                        'description' => 'The quote accept/decline token.',
                    ],
                    'reason' => [
                        'name' => 'reason',
                        'type' => Type::string(),
                        'description' => 'Optional decline reason.',
                    ],
                ],
                'resolve' => [$resolver, 'declineQuote'],
            ],
        ];
    }
}
