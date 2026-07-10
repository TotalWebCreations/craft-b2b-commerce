<?php

namespace totalwebcreations\b2bcommerce\gql\resolvers\mutations;

use GraphQL\Error\UserError;
use GraphQL\Type\Definition\ResolveInfo;
use totalwebcreations\b2bcommerce\gql\resolvers\mutations\concerns\ResolvesAuthenticatedMember;
use totalwebcreations\b2bcommerce\Plugin;
use yii\base\InvalidArgumentException;

/**
 * Resolves the order-list write mutations. Thin wrappers over the OrderLists service — the same calls
 * the storefront OrderListsController actions make. Every method passes the caller's OWN company
 * (from requireMember), so a listId belonging to another company is scoped out by the service and the
 * write is refused; no company id is ever accepted as an argument.
 */
class OrderList
{
    use ResolvesAuthenticatedMember;

    public function createOrderList(mixed $source, array $arguments, mixed $context, ResolveInfo $resolveInfo): int
    {
        $member = $this->requireMember();
        $name = trim((string) ($arguments['name'] ?? ''));

        try {
            return Plugin::getInstance()->orderLists->createList($member['company'], $name, $member['user']->id);
        } catch (InvalidArgumentException $exception) {
            throw new UserError($exception->getMessage());
        }
    }

    public function renameOrderList(mixed $source, array $arguments, mixed $context, ResolveInfo $resolveInfo): bool
    {
        $member = $this->requireMember();
        $listId = (int) ($arguments['listId'] ?? 0);
        $name = trim((string) ($arguments['name'] ?? ''));

        try {
            Plugin::getInstance()->orderLists->renameList($member['company'], $listId, $name);
        } catch (InvalidArgumentException $exception) {
            throw new UserError($exception->getMessage());
        }

        return true;
    }

    public function addOrderListItem(mixed $source, array $arguments, mixed $context, ResolveInfo $resolveInfo): bool
    {
        $member = $this->requireMember();
        $listId = (int) ($arguments['listId'] ?? 0);
        $purchasableId = (int) ($arguments['purchasableId'] ?? 0);
        $qty = (int) ($arguments['qty'] ?? 0);

        try {
            Plugin::getInstance()->orderLists->setItem($member['company'], $listId, $purchasableId, $qty);
        } catch (InvalidArgumentException $exception) {
            throw new UserError($exception->getMessage());
        }

        return true;
    }
}
