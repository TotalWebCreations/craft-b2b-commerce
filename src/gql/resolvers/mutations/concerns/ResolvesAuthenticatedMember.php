<?php

namespace totalwebcreations\b2bcommerce\gql\resolvers\mutations\concerns;

use Craft;
use craft\elements\User;
use GraphQL\Error\UserError;
use totalwebcreations\b2bcommerce\elements\Company;
use totalwebcreations\b2bcommerce\gql\helpers\Gql as GqlHelper;
use totalwebcreations\b2bcommerce\Plugin;

/**
 * Shared guard for every B2B write mutation resolver. Enforces the same boundary as the storefront
 * action controllers: the write scope must be enabled, the caller must be a signed-in user, and they
 * must belong to a company. The acting company is derived from the authenticated user only — a
 * mutation never accepts a company id — so there is no argument by which one company could write
 * another's data. The underlying service (Quotes, Approvals, OrderLists, checkout) applies every
 * further role/membership/enforcement guard unchanged.
 */
trait ResolvesAuthenticatedMember
{
    /**
     * @return array{user: User, company: Company}
     * @throws UserError when the write scope is off, the caller is a guest, or has no company.
     */
    protected function requireMember(): array
    {
        if (!GqlHelper::canWriteB2bContext()) {
            throw new UserError('This schema is not permitted to perform B2B write mutations.');
        }

        $user = Craft::$app->getUser()->getIdentity();

        if ($user === null) {
            throw new UserError('You must be signed in to perform this mutation.');
        }

        $company = Plugin::getInstance()->companyMembers->getCompanyForUser($user->id);

        if ($company === null) {
            throw new UserError('You must belong to a company to perform this mutation.');
        }

        return ['user' => $user, 'company' => $company];
    }
}
