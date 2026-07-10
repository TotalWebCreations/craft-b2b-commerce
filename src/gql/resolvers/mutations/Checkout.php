<?php

namespace totalwebcreations\b2bcommerce\gql\resolvers\mutations;

use craft\commerce\Plugin as Commerce;
use GraphQL\Error\UserError;
use GraphQL\Type\Definition\ResolveInfo;
use totalwebcreations\b2bcommerce\gql\resolvers\mutations\concerns\ResolvesAuthenticatedMember;
use totalwebcreations\b2bcommerce\Plugin;
use yii\base\InvalidArgumentException;

/**
 * Resolves the `setPoNumber` mutation. A thin wrapper over the phase-15 checkout PO write: it resolves
 * the authenticated member's active cart and calls the same service the `checkout/set-reference`
 * action controller calls, so the per-company require-PO backstop and every other guard apply
 * unchanged. No company id is accepted, so a caller can only ever write their own cart.
 */
class Checkout
{
    use ResolvesAuthenticatedMember;

    public function setPoNumber(mixed $source, array $arguments, mixed $context, ResolveInfo $resolveInfo): string
    {
        $this->requireMember();

        $poNumber = trim((string) ($arguments['poNumber'] ?? ''));

        if ($poNumber === '') {
            throw new UserError('A purchase order number is required.');
        }

        $cart = Commerce::getInstance()->getCarts()->getCart();

        try {
            Plugin::getInstance()->orderReferences->setPoNumber($cart, $poNumber);
        } catch (InvalidArgumentException $exception) {
            throw new UserError($exception->getMessage());
        }

        return $poNumber;
    }
}
