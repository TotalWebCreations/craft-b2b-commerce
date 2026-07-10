<?php

namespace totalwebcreations\b2bcommerce\gql\resolvers\mutations;

use craft\commerce\Plugin as Commerce;
use GraphQL\Error\UserError;
use GraphQL\Type\Definition\ResolveInfo;
use totalwebcreations\b2bcommerce\gql\resolvers\mutations\concerns\ResolvesAuthenticatedMember;
use totalwebcreations\b2bcommerce\Plugin;
use yii\base\InvalidArgumentException;

/**
 * Resolves the quote write mutations. Each method is a thin wrapper over the Quotes service — the same
 * calls the storefront QuotesController actions make — so price-freeze, token validation, cart
 * adoption and the buyer-mutation veto all apply unchanged. request uses the caller's active cart;
 * accept/decline are authorized by the quote token itself, and the service records the acting user.
 */
class Quote
{
    use ResolvesAuthenticatedMember;

    public function requestQuote(mixed $source, array $arguments, mixed $context, ResolveInfo $resolveInfo): bool
    {
        $member = $this->requireMember();
        $cart = Commerce::getInstance()->getCarts()->getCart();
        $notes = trim((string) ($arguments['notes'] ?? ''));

        try {
            Plugin::getInstance()->quotes->requestQuote($cart, $member['user'], $notes !== '' ? $notes : null);
        } catch (InvalidArgumentException $exception) {
            throw new UserError($exception->getMessage());
        }

        return true;
    }

    public function acceptQuote(mixed $source, array $arguments, mixed $context, ResolveInfo $resolveInfo): string
    {
        $member = $this->requireMember();
        $token = (string) ($arguments['token'] ?? '');

        try {
            $order = Plugin::getInstance()->quotes->acceptByToken($token, $member['user']);
        } catch (InvalidArgumentException $exception) {
            throw new UserError($exception->getMessage());
        }

        return $order->number;
    }

    public function declineQuote(mixed $source, array $arguments, mixed $context, ResolveInfo $resolveInfo): bool
    {
        $member = $this->requireMember();
        $token = (string) ($arguments['token'] ?? '');
        $reason = (string) ($arguments['reason'] ?? '');

        try {
            Plugin::getInstance()->quotes->declineByToken($token, $member['user'], $reason);
        } catch (InvalidArgumentException $exception) {
            throw new UserError($exception->getMessage());
        }

        return true;
    }
}
