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

    /**
     * Whether the active (or given) schema may read a company's sensitive financial fields (taxId,
     * creditLimit, paymentTermDays, allowInvoicePayment, approvalThreshold) across all companies.
     * Gated by the separate, opt-in `b2bCompanies.financials` schema component so that enabling the
     * plain `b2bCompanies.all` scope exposes only non-sensitive company identity. Reading one's own
     * company's financials never requires this scope; that path is served by `b2bContext`.
     */
    public static function canReadCompanyFinancials(?GqlSchema $schema = null): bool
    {
        return self::canSchema('b2bCompanies.financials', 'read', $schema);
    }

    /**
     * Whether the active (or given) schema may perform B2B write mutations. Gated by the separate,
     * opt-in `b2bContext.write` mutation component, which is OFF by default: enabling any read scope
     * never enables writes. The mutation resolvers re-check this and additionally require an
     * authenticated member, so a schema token alone can never write another company's data.
     */
    public static function canWriteB2bContext(?GqlSchema $schema = null): bool
    {
        return self::canSchema('b2bContext.write', 'edit', $schema);
    }
}
