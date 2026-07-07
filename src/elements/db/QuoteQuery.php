<?php

namespace totalwebcreations\b2bcommerce\elements\db;

use craft\elements\db\ElementQuery;
use craft\helpers\Db;
use totalwebcreations\b2bcommerce\enums\QuoteStatus;

class QuoteQuery extends ElementQuery
{
    public mixed $quoteStatus = null;
    public mixed $orderId = null;

    public function quoteStatus(mixed $value): static
    {
        $this->quoteStatus = $value;

        return $this;
    }

    public function orderId(mixed $value): static
    {
        $this->orderId = $value;

        return $this;
    }

    protected function beforePrepare(): bool
    {
        $this->joinElementTable('b2b_quotes');

        $this->query->select([
            'b2b_quotes.orderId',
            'b2b_quotes.companyId',
            'b2b_quotes.status AS quoteStatus',
            'b2b_quotes.validUntil',
            'b2b_quotes.notes',
            'b2b_quotes.declineReason',
            'b2b_quotes.requestedById',
            'b2b_quotes.acceptToken',
        ]);

        if ($this->quoteStatus !== null) {
            $this->subQuery->andWhere(Db::parseParam('b2b_quotes.status', $this->quoteStatus));
        }

        if ($this->orderId !== null) {
            $this->subQuery->andWhere(Db::parseParam('b2b_quotes.orderId', $this->orderId));
        }

        return parent::beforePrepare();
    }

    protected function statusCondition(string $status): mixed
    {
        return match ($status) {
            QuoteStatus::Requested->value,
            QuoteStatus::Sent->value,
            QuoteStatus::Accepted->value,
            QuoteStatus::Declined->value,
            QuoteStatus::Expired->value => ['b2b_quotes.status' => $status],
            default => parent::statusCondition($status),
        };
    }
}
