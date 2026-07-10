<?php

namespace totalwebcreations\b2bcommerce\gql\resolvers\mutations;

use craft\commerce\Plugin as Commerce;
use GraphQL\Error\UserError;
use GraphQL\Type\Definition\ResolveInfo;
use totalwebcreations\b2bcommerce\gql\resolvers\mutations\concerns\ResolvesAuthenticatedMember;
use totalwebcreations\b2bcommerce\Plugin;
use yii\base\InvalidArgumentException;

/**
 * Resolves the approval write mutations. Thin wrappers over the Approvals service — the same calls the
 * storefront ApprovalsController actions make. The service enforces four-eyes, sequential step order
 * and same-company membership for the approver, so an orderId naming another company's order is
 * refused by the service, not by a check duplicated here. submit uses the caller's active cart.
 */
class Approval
{
    use ResolvesAuthenticatedMember;

    public function submitForApproval(mixed $source, array $arguments, mixed $context, ResolveInfo $resolveInfo): bool
    {
        $member = $this->requireMember();
        $cart = Commerce::getInstance()->getCarts()->getCart();

        try {
            Plugin::getInstance()->approvals->submitForApproval($cart, $member['user']);
        } catch (InvalidArgumentException $exception) {
            throw new UserError($exception->getMessage());
        }

        return true;
    }

    public function approveOrder(mixed $source, array $arguments, mixed $context, ResolveInfo $resolveInfo): bool
    {
        $member = $this->requireMember();
        $orderId = (int) ($arguments['orderId'] ?? 0);

        try {
            Plugin::getInstance()->approvals->approve($orderId, $member['user']);
        } catch (InvalidArgumentException $exception) {
            throw new UserError($exception->getMessage());
        }

        return true;
    }

    public function declineOrder(mixed $source, array $arguments, mixed $context, ResolveInfo $resolveInfo): bool
    {
        $member = $this->requireMember();
        $orderId = (int) ($arguments['orderId'] ?? 0);
        $reason = (string) ($arguments['reason'] ?? '');

        try {
            Plugin::getInstance()->approvals->decline($orderId, $member['user'], $reason);
        } catch (InvalidArgumentException $exception) {
            throw new UserError($exception->getMessage());
        }

        return true;
    }
}
