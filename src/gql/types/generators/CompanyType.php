<?php

namespace totalwebcreations\b2bcommerce\gql\types\generators;

use Craft;
use craft\gql\base\Generator;
use craft\gql\base\GeneratorInterface;
use craft\gql\base\ObjectType;
use craft\gql\base\SingleGeneratorInterface;
use craft\gql\GqlEntityRegistry;
use totalwebcreations\b2bcommerce\elements\Company as CompanyElement;
use totalwebcreations\b2bcommerce\gql\interfaces\elements\Company as CompanyInterface;
use totalwebcreations\b2bcommerce\gql\types\elements\Company as CompanyTypeElement;

/**
 * Generates the single Company GraphQL object type. Companies have no sub-types, so this follows
 * Craft's User generator: one context (the Company field layout), one generated type carrying both
 * the native interface fields and the merchant's custom-field values.
 */
class CompanyType extends Generator implements GeneratorInterface, SingleGeneratorInterface
{
    public static function generateTypes(mixed $context = null): array
    {
        $type = static::generateType($context);

        return [$type->name => $type];
    }

    public static function generateType(mixed $context): ObjectType
    {
        return GqlEntityRegistry::getOrCreate(CompanyElement::GQL_TYPE_NAME, fn() => new CompanyTypeElement([
            'name' => CompanyElement::GQL_TYPE_NAME,
            'fields' => function() use ($context) {
                // Companies don't have different types, so the context is always the Company field layout.
                $context ??= Craft::$app->getFields()->getLayoutByType(CompanyElement::class);
                $contentFieldGqlTypes = self::getContentFields($context);
                $companyFields = array_merge(CompanyInterface::getFieldDefinitions(), $contentFieldGqlTypes);

                return Craft::$app->getGql()->prepareFieldDefinitions($companyFields, CompanyElement::GQL_TYPE_NAME);
            },
        ]));
    }
}
