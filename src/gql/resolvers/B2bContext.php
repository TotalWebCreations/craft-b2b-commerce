<?php

namespace totalwebcreations\b2bcommerce\gql\resolvers;

use Craft;
use DateTimeImmutable;
use GraphQL\Type\Definition\ResolveInfo;
use totalwebcreations\b2bcommerce\Plugin;

/**
 * Resolves the `b2bContext` query.
 *
 * Security is enforced here, not by the caller: the context is built entirely from the authenticated
 * user (Craft::$app->getUser()) and their own company. The query accepts no company id, so there is no
 * argument by which one company could ever read another's context. A public token with no signed-in
 * user resolves to null (no leak, no error), matching the storefront variable's behaviour.
 */
class B2bContext
{
    public static function resolve(mixed $source, array $arguments, mixed $context, ResolveInfo $resolveInfo): ?array
    {
        $user = Craft::$app->getUser()->getIdentity();

        if ($user === null) {
            return null;
        }

        $plugin = Plugin::getInstance();
        $members = $plugin->companyMembers;
        $company = $members->getCompanyForUser($user->id);

        if ($company === null) {
            return null;
        }

        $role = $members->getRoleForUser($user->id, $company->id);

        return [
            'company' => $company,
            'role' => $role?->value,
            'memberBudget' => self::memberBudget($company->id, $user->id),
            'creditSummary' => $plugin->creditBalance->getSummary($company->id),
            'members' => self::members($company->id),
            'quotes' => $plugin->quotes->getQuotesForCompany($company->id),
            'pendingApprovals' => ($role !== null && $role->canApproveOrders())
                ? $plugin->approvals->getPendingForCompany($company->id)
                : [],
            'myApprovalRequests' => $plugin->approvals->getRequestsForRequester($user->id),
            'orderLists' => $plugin->getSettings()->enableQuickOrder
                ? $plugin->orderLists->getLists($company->id)
                : [],
        ];
    }

    /**
     * @return array{amount: float, period: string, spent: float, remaining: float}|null
     */
    private static function memberBudget(int $companyId, int $userId): ?array
    {
        $budgets = Plugin::getInstance()->budgets;
        $budget = $budgets->getBudget($companyId, $userId);

        if ($budget === null) {
            return null;
        }

        $amount = (float) $budget['amount'];
        $spent = $budgets->getSpent($companyId, $userId, new DateTimeImmutable('now'));

        return [
            'amount' => $amount,
            'period' => (string) $budget['period'],
            'spent' => $spent,
            'remaining' => max(0.0, $amount - $spent),
        ];
    }

    /**
     * @return array<int, array{user: \craft\elements\User, role: string}>
     */
    private static function members(int $companyId): array
    {
        $rows = [];

        foreach (Plugin::getInstance()->companyMembers->getMemberUsers($companyId) as $member) {
            $rows[] = [
                'user' => $member['user'],
                'role' => $member['role']->value,
            ];
        }

        return $rows;
    }
}
