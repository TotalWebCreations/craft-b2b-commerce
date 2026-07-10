<?php

namespace totalwebcreations\b2bcommerce\gql\types\objects;

use craft\gql\GqlEntityRegistry;
use craft\gql\types\DateTime;
use GraphQL\Type\Definition\ObjectType;
use GraphQL\Type\Definition\Type;

/**
 * One outstanding invoice line on the current user's company statement (phase 22). Resolved from the
 * array the Statements service returns; the field resolvers map the buyer-facing names onto that
 * array's own keys (number, balance, ...) so the service shape stays the single source of truth.
 */
class StatementLine
{
    public static function getName(): string
    {
        return 'B2bStatementLine';
    }

    public static function getType(): ObjectType
    {
        return GqlEntityRegistry::getOrCreate(self::getName(), fn() => new ObjectType([
            'name' => self::getName(),
            'description' => 'An outstanding invoice line on the company statement.',
            'fields' => [
                'orderNumber' => [
                    'type' => Type::string(),
                    'description' => 'The invoice order number.',
                    'resolve' => static fn(array $line): ?string => $line['number'] ?? null,
                ],
                'reference' => [
                    'type' => Type::string(),
                    'description' => 'The order reference (or short number).',
                ],
                'total' => [
                    'type' => Type::float(),
                    'description' => 'The invoice total.',
                    'resolve' => static fn(array $line): ?float => isset($line['total']) ? (float) $line['total'] : null,
                ],
                'outstanding' => [
                    'type' => Type::float(),
                    'description' => 'The unpaid balance on this invoice.',
                    'resolve' => static fn(array $line): ?float => isset($line['balance']) ? (float) $line['balance'] : null,
                ],
                'dueDate' => [
                    'type' => DateTime::getType(),
                    'description' => 'The payment due date.',
                ],
                'daysPastDue' => [
                    'type' => Type::int(),
                    'description' => 'Days past the due date (0 when not yet due).',
                    'resolve' => static fn(array $line): int => (int) ($line['daysPastDue'] ?? 0),
                ],
            ],
        ]));
    }
}
