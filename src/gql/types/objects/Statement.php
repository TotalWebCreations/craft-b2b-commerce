<?php

namespace totalwebcreations\b2bcommerce\gql\types\objects;

use craft\gql\GqlEntityRegistry;
use GraphQL\Type\Definition\ObjectType;
use GraphQL\Type\Definition\Type;

/**
 * The current user's company statement: outstanding balance bucketed by aging plus the underlying
 * invoice lines (phase 22). Resolved from the array the Statements service returns; the bucket fields
 * read from the nested `buckets` array (keyed by AgingBucket enum values) via field resolvers.
 */
class Statement
{
    public static function getName(): string
    {
        return 'B2bStatement';
    }

    public static function getType(): ObjectType
    {
        return GqlEntityRegistry::getOrCreate(self::getName(), fn() => new ObjectType([
            'name' => self::getName(),
            'description' => 'The current user’s company statement with aging buckets.',
            'fields' => [
                'current' => [
                    'type' => Type::float(),
                    'description' => 'Balance not yet past due.',
                    'resolve' => static fn(array $statement): float => (float) ($statement['buckets']['current'] ?? 0.0),
                ],
                'due1To30' => [
                    'type' => Type::float(),
                    'description' => 'Balance 1–30 days past due.',
                    'resolve' => static fn(array $statement): float => (float) ($statement['buckets']['1-30'] ?? 0.0),
                ],
                'due31To60' => [
                    'type' => Type::float(),
                    'description' => 'Balance 31–60 days past due.',
                    'resolve' => static fn(array $statement): float => (float) ($statement['buckets']['31-60'] ?? 0.0),
                ],
                'due61To90' => [
                    'type' => Type::float(),
                    'description' => 'Balance 61–90 days past due.',
                    'resolve' => static fn(array $statement): float => (float) ($statement['buckets']['61-90'] ?? 0.0),
                ],
                'due90Plus' => [
                    'type' => Type::float(),
                    'description' => 'Balance more than 90 days past due.',
                    'resolve' => static fn(array $statement): float => (float) ($statement['buckets']['90+'] ?? 0.0),
                ],
                'lines' => [
                    'type' => Type::listOf(StatementLine::getType()),
                    'description' => 'The outstanding invoice lines behind the buckets.',
                    'resolve' => static fn(array $statement): array => $statement['lines'] ?? [],
                ],
            ],
        ]));
    }
}
