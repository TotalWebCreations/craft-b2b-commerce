<?php

namespace totalwebcreations\b2bcommerce\elements\db;

use craft\elements\db\ElementQuery;
use craft\helpers\Db;
use totalwebcreations\b2bcommerce\enums\ApprovalStatus;

class ApprovalQuery extends ElementQuery
{
    public mixed $approvalStatus = null;
    public mixed $orderId = null;

    public function approvalStatus(mixed $value): static
    {
        $this->approvalStatus = $value;

        return $this;
    }

    public function orderId(mixed $value): static
    {
        $this->orderId = $value;

        return $this;
    }

    protected function beforePrepare(): bool
    {
        $this->joinElementTable('b2b_approvals');

        $this->query->select([
            'b2b_approvals.orderId',
            'b2b_approvals.companyId',
            'b2b_approvals.status AS approvalStatus',
            'b2b_approvals.requestedById',
            'b2b_approvals.resolvedById',
            'b2b_approvals.reason',
            'b2b_approvals.thresholdAmount',
        ]);

        if ($this->approvalStatus !== null) {
            $this->subQuery->andWhere(Db::parseParam('b2b_approvals.status', $this->approvalStatus));
        }

        if ($this->orderId !== null) {
            $this->subQuery->andWhere(Db::parseParam('b2b_approvals.orderId', $this->orderId));
        }

        return parent::beforePrepare();
    }

    protected function statusCondition(string $status): mixed
    {
        return match ($status) {
            ApprovalStatus::Pending->value,
            ApprovalStatus::Approved->value,
            ApprovalStatus::Declined->value => ['b2b_approvals.status' => $status],
            default => parent::statusCondition($status),
        };
    }
}
