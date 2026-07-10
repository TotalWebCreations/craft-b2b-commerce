<?php

namespace totalwebcreations\b2bcommerce\gql\types\objects;

use craft\gql\GqlEntityRegistry;
use GraphQL\Type\Definition\ObjectType;
use GraphQL\Type\Definition\Type;
use totalwebcreations\b2bcommerce\gql\interfaces\elements\Company as CompanyInterface;

/**
 * The current user's B2B context: their company, role, budget and credit position, plus the members,
 * quotes, approvals and order lists of that company. Every field is scoped to the authenticated
 * user's own company by the resolver, so this type can never surface another company's data.
 */
class B2bContext
{
    public static function getName(): string
    {
        return 'B2bContext';
    }

    public static function getType(): ObjectType
    {
        return GqlEntityRegistry::getOrCreate(self::getName(), fn() => new ObjectType([
            'name' => self::getName(),
            'description' => 'The authenticated user’s B2B context, scoped to their own company.',
            'fields' => [
                'company' => [
                    'type' => CompanyInterface::getType(),
                    'description' => 'The current user’s company.',
                ],
                'role' => [
                    'type' => Type::string(),
                    'description' => 'The current user’s role in the company (admin, purchaser or approver).',
                ],
                'memberBudget' => [
                    'type' => MemberBudget::getType(),
                    'description' => 'The current user’s spending budget, or null when unlimited.',
                ],
                'creditSummary' => [
                    'type' => CreditSummary::getType(),
                    'description' => 'The company’s credit position.',
                ],
                'members' => [
                    'type' => Type::listOf(Member::getType()),
                    'description' => 'The company’s members.',
                ],
                'quotes' => [
                    'type' => Type::listOf(Quote::getType()),
                    'description' => 'The company’s quotes, newest first.',
                ],
                'pendingApprovals' => [
                    'type' => Type::listOf(Approval::getType()),
                    'description' => 'The company’s pending approval queue, only when the user is an approver.',
                ],
                'myApprovalRequests' => [
                    'type' => Type::listOf(Approval::getType()),
                    'description' => 'The current user’s own approval requests, any status, newest first.',
                ],
                'orderLists' => [
                    'type' => Type::listOf(OrderList::getType()),
                    'description' => 'The company’s saved order lists.',
                ],
                'departments' => [
                    'type' => Type::listOf(Department::getType()),
                    'description' => 'The company’s departments (phase 19), each with its own budget.',
                ],
                'departmentBudget' => [
                    'type' => MemberBudget::getType(),
                    'description' => 'The current user’s department spend budget, or null when they have no department or it is unlimited.',
                ],
                'approvalTiers' => [
                    'type' => Type::listOf(ApprovalTier::getType()),
                    'description' => 'The company’s amount-tiered approval bands (phase 18); empty for a tier-less company.',
                ],
                'catalogCriteria' => [
                    'type' => Type::string(),
                    'description' => 'The company’s catalog restriction summary (phase 21), or null when the full catalog is available. This is a convenience hint; the add-to-cart veto is the security boundary.',
                ],
            ],
        ]));
    }
}
