<?php

namespace totalwebcreations\b2bcommerce\elements\db;

use craft\elements\db\ElementQuery;
use craft\helpers\Db;
use totalwebcreations\b2bcommerce\elements\Company;

class CompanyQuery extends ElementQuery
{
    public mixed $companyStatus = null;

    public function companyStatus(mixed $value): static
    {
        $this->companyStatus = $value;

        return $this;
    }

    protected function beforePrepare(): bool
    {
        $this->joinElementTable('b2b_companies');

        $this->query->select([
            'b2b_companies.registrationNumber',
            'b2b_companies.taxId',
            'b2b_companies.status AS companyStatus',
            'b2b_companies.creditLimit',
            'b2b_companies.paymentTermDays',
            'b2b_companies.allowInvoicePayment',
            'b2b_companies.approvalThreshold',
            'b2b_companies.customerGroupId',
        ]);

        if ($this->companyStatus !== null) {
            $this->subQuery->andWhere(Db::parseParam('b2b_companies.status', $this->companyStatus));
        }

        return parent::beforePrepare();
    }

    protected function statusCondition(string $status): mixed
    {
        return match ($status) {
            Company::STATUS_PENDING,
            Company::STATUS_APPROVED,
            Company::STATUS_BLOCKED => ['b2b_companies.status' => $status],
            default => parent::statusCondition($status),
        };
    }
}
