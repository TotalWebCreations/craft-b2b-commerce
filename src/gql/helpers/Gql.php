<?php

namespace totalwebcreations\b2bcommerce\gql\helpers;

use craft\helpers\Gql as BaseGqlHelper;
use craft\models\GqlSchema;

/**
 * B2B Commerce GraphQL schema-scope helper. Mirrors how Commerce's own Gql helper reports whether the
 * active (or given) schema is permitted to read a component, so query registration and element
 * resolvers can gate themselves the same way Commerce gates products.
 */
class Gql extends BaseGqlHelper
{
    /**
     * Whether the active (or given) schema may read the Company element type. Gated by the
     * `b2bCompanies.all` schema component, opt-in per schema in the control panel.
     */
    public static function canQueryCompanies(?GqlSchema $schema = null): bool
    {
        $allowedEntities = self::extractAllowedEntitiesFromSchema('read', $schema);

        return isset($allowedEntities['b2bCompanies']);
    }

    /**
     * Whether the active (or given) schema may read the current user's B2B context aggregate. Gated
     * by the `b2bContext.self` schema component. Enabling the component never widens what a caller can
     * see: the resolver always scopes to the authenticated user's own company.
     */
    public static function canQueryB2bContext(?GqlSchema $schema = null): bool
    {
        $allowedEntities = self::extractAllowedEntitiesFromSchema('read', $schema);

        return isset($allowedEntities['b2bContext']);
    }
}
