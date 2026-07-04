<?php

namespace totalwebcreations\b2bcommerce\gql\queries;

use craft\gql\base\Query;
use totalwebcreations\b2bcommerce\gql\helpers\Gql as GqlHelper;
use totalwebcreations\b2bcommerce\gql\resolvers\B2bContext as B2bContextResolver;
use totalwebcreations\b2bcommerce\gql\types\objects\B2bContext as B2bContextType;

/**
 * The top-level `b2bContext` query. Registered only when the active schema has the `b2bContext.self`
 * component. The query takes no arguments: it always resolves the authenticated user's own context,
 * so it cannot be pointed at another company.
 */
class B2bContext extends Query
{
    public static function getQueries(bool $checkToken = true): array
    {
        if ($checkToken && !GqlHelper::canQueryB2bContext()) {
            return [];
        }

        return [
            'b2bContext' => [
                'type' => B2bContextType::getType(),
                'args' => [],
                'resolve' => B2bContextResolver::class . '::resolve',
                'description' => 'The authenticated user’s B2B context (company, role, budget, credit, members, quotes, approvals and order lists), scoped to their own company. Null for a visitor with no company.',
            ],
        ];
    }
}
