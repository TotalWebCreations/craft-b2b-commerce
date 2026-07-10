<?php

namespace totalwebcreations\b2bcommerce\gql\types\objects;

use craft\gql\GqlEntityRegistry;
use craft\gql\types\DateTime;
use GraphQL\Type\Definition\ObjectType;
use GraphQL\Type\Definition\Type;

/**
 * An order approval for the current user's company. One type serves both the approver queue
 * (`pendingApprovals`, carrying the requester name) and the caller's own requests
 * (`myApprovalRequests`, carrying the decision status and reason); fields not present in a given row
 * resolve to null.
 */
class Approval
{
    public static function getName(): string
    {
        return 'B2bApproval';
    }

    public static function getType(): ObjectType
    {
        return GqlEntityRegistry::getOrCreate(self::getName(), fn() => new ObjectType([
            'name' => self::getName(),
            'description' => 'An order approval belonging to the current user’s company.',
            'fields' => [
                'orderId' => [
                    'type' => Type::int(),
                    'description' => 'The ID of the order awaiting or having gone through approval.',
                ],
                'status' => [
                    'type' => Type::string(),
                    'description' => 'The approval status (pending, approved or declined).',
                ],
                'reference' => [
                    'type' => Type::string(),
                    'description' => 'The order reference (or short number).',
                ],
                'total' => [
                    'type' => Type::float(),
                    'description' => 'The order total.',
                ],
                'currency' => [
                    'type' => Type::string(),
                    'description' => 'The order currency.',
                ],
                'requesterName' => [
                    'type' => Type::string(),
                    'description' => 'The name of the member who requested approval (approver queue only).',
                ],
                'reason' => [
                    'type' => Type::string(),
                    'description' => 'The decision reason (own requests only).',
                ],
                'dateCreated' => [
                    'type' => DateTime::getType(),
                    'description' => 'The date the request was raised.',
                ],
                'steps' => [
                    'type' => Type::listOf(ApprovalStep::getType()),
                    'description' => 'The approval’s sequential step ladder (phase 18); empty for a tier-less approval.',
                    'resolve' => static function (array $approval): array {
                        $orderId = $approval['orderId'] ?? null;

                        if ($orderId === null) {
                            return [];
                        }

                        return \totalwebcreations\b2bcommerce\Plugin::getInstance()->approvals->getStepsForApproval((int) $orderId);
                    },
                ],
            ],
        ]));
    }
}
