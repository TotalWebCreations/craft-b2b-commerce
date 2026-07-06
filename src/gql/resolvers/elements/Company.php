<?php

namespace totalwebcreations\b2bcommerce\gql\resolvers\elements;

use craft\elements\db\ElementQuery;
use craft\gql\base\ElementResolver;
use totalwebcreations\b2bcommerce\elements\Company as CompanyElement;
use totalwebcreations\b2bcommerce\gql\helpers\Gql as GqlHelper;
use yii\base\UnknownMethodException;

/**
 * Resolves the `companies`, `company` and `companyCount` queries. Reading the Company element type is
 * an explicit per-schema opt-in (the `b2bCompanies.all` component); with the component off the query
 * is never registered, and this resolver returns nothing even if reached, mirroring how Commerce's
 * product resolver refuses when the schema can't query products.
 */
class Company extends ElementResolver
{
    public static function prepareQuery(mixed $source, array $arguments, ?string $fieldName = null): mixed
    {
        if ($source === null) {
            $query = CompanyElement::find();
        } else {
            $query = $source->$fieldName;
        }

        if (!$query instanceof ElementQuery) {
            return $query;
        }

        foreach ($arguments as $key => $value) {
            try {
                $query->$key($value);
            } catch (UnknownMethodException $e) {
                if ($value !== null) {
                    throw $e;
                }
            }
        }

        if (!GqlHelper::canQueryCompanies()) {
            return [];
        }

        return $query;
    }
}
