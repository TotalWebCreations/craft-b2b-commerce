<?php

namespace totalwebcreations\b2bcommerce\modules\approvals\services;

use craft\commerce\elements\Order;
use craft\db\Query;
use craft\elements\User;
use totalwebcreations\b2bcommerce\elements\Company;
use totalwebcreations\b2bcommerce\enums\ApprovalStatus;
use totalwebcreations\b2bcommerce\enums\CompanyRole;
use totalwebcreations\b2bcommerce\Plugin;
use yii\base\Component;

class Approvals extends Component
{
    /**
     * Whether the actor's order must be held for a company approver before it can be placed.
     *
     * Only a purchaser is ever gated: approvers and admins can place orders directly, and a
     * user with no company (or a company not approved to order) has no approval flow at all.
     * With a threshold of null the company runs no approval gate; a threshold of 0.0 gates
     * every order. Otherwise the order total is compared STRICTLY greater than the threshold,
     * so an order exactly at the threshold is placed directly and does not need approval.
     */
    public function needsApproval(Order $order, User $actor): bool
    {
        $members = Plugin::getInstance()->companyMembers;
        $company = $members->getCompanyForUser($actor->id);

        if ($company === null) {
            return false;
        }

        if ($company->companyStatus !== Company::STATUS_APPROVED) {
            return false;
        }

        if ($members->getRoleForUser($actor->id, $company->id) !== CompanyRole::Purchaser) {
            return false;
        }

        $threshold = $company->approvalThreshold;

        if ($threshold === null) {
            return false;
        }

        if ($threshold === 0.0) {
            return true;
        }

        return (float) $order->getTotalPrice() > $threshold;
    }

    /**
     * Whether the order carries an approval request still awaiting a decision. A simple
     * indexed existence check on the pending status, mirroring the quote row guards.
     */
    public function orderHasPendingApproval(int $orderId): bool
    {
        return (new Query())
            ->from('{{%b2b_approvals}}')
            ->where([
                'orderId' => $orderId,
                'status' => ApprovalStatus::Pending->value,
            ])
            ->exists();
    }
}
