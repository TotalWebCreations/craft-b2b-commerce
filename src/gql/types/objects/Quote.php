<?php

namespace totalwebcreations\b2bcommerce\gql\types\objects;

use craft\gql\GqlEntityRegistry;
use craft\gql\types\DateTime;
use GraphQL\Type\Definition\ObjectType;
use GraphQL\Type\Definition\Type;

/**
 * A quote belonging to the current user's company, read-only. Resolved from the array the Quotes
 * service returns for the company; the accept token is present only on a still-sent quote.
 */
class Quote
{
    public static function getName(): string
    {
        return 'B2bQuote';
    }

    public static function getType(): ObjectType
    {
        return GqlEntityRegistry::getOrCreate(self::getName(), fn() => new ObjectType([
            'name' => self::getName(),
            'description' => 'A quote belonging to the current user’s company.',
            'fields' => [
                'status' => [
                    'type' => Type::string(),
                    'description' => 'The quote status (requested, sent, accepted, declined or expired).',
                ],
                'validUntil' => [
                    'type' => DateTime::getType(),
                    'description' => 'The date the quote is valid until.',
                ],
                'dateCreated' => [
                    'type' => DateTime::getType(),
                    'description' => 'The date the quote was created.',
                ],
                'orderNumber' => [
                    'type' => Type::string(),
                    'description' => 'The number of the order the quote is for.',
                ],
                'reference' => [
                    'type' => Type::string(),
                    'description' => 'The order reference (or short number).',
                ],
                'total' => [
                    'type' => Type::float(),
                    'description' => 'The quote total.',
                ],
                'currency' => [
                    'type' => Type::string(),
                    'description' => 'The order currency.',
                ],
                'poNumber' => [
                    'type' => Type::string(),
                    'description' => 'The buyer purchase-order number on the quote’s order (phase 15), if set.',
                ],
                'acceptToken' => [
                    'type' => Type::string(),
                    'description' => 'The accept token, present only while the quote is still sent.',
                ],
            ],
        ]));
    }
}
