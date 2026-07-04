<?php

namespace totalwebcreations\b2bcommerce\gql\types\elements;

use craft\gql\types\elements\Element as ElementType;
use GraphQL\Type\Definition\ResolveInfo;
use totalwebcreations\b2bcommerce\elements\Company as CompanyElement;
use totalwebcreations\b2bcommerce\gql\interfaces\elements\Company as CompanyInterface;

/**
 * The concrete Company GraphQL object type. Native attributes (registrationNumber, taxId, credit
 * limit, …) are public properties on the element, so the base element resolver reads them directly;
 * only `name` needs a hand, mapping to the element title.
 */
class Company extends ElementType
{
    public function __construct(array $config)
    {
        $config['interfaces'] = [
            CompanyInterface::getType(),
        ];

        parent::__construct($config);
    }

    protected function resolve(mixed $source, array $arguments, mixed $context, ResolveInfo $resolveInfo): mixed
    {
        /** @var CompanyElement $source */
        return match ($resolveInfo->fieldName) {
            'name' => $source->title,
            default => parent::resolve($source, $arguments, $context, $resolveInfo),
        };
    }
}
