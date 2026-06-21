<?php

use craft\db\Query;
use totalwebcreations\b2bcommerce\elements\Company;
use totalwebcreations\b2bcommerce\Plugin;
use yii\base\InvalidArgumentException;

/**
 * Reads the persisted status from the b2b_companies row for a company.
 */
function companyRowStatus(int $companyId): ?string
{
    return (new Query())
        ->select('status')
        ->from('{{%b2b_companies}}')
        ->where(['id' => $companyId])
        ->scalar() ?: null;
}

it('approves a pending company and flips the element and the database row', function () {
    $company = createTestCompany('pending');

    Plugin::getInstance()->companyApproval->approve($company);

    expect($company->companyStatus)->toBe(Company::STATUS_APPROVED)
        ->and(companyRowStatus($company->id))->toBe(Company::STATUS_APPROVED);
});

it('throws with the exact message when approving an already approved company', function () {
    $company = createTestCompany('approved');

    expect(fn () => Plugin::getInstance()->companyApproval->approve($company))
        ->toThrow(InvalidArgumentException::class, 'Cannot change status from approved to approved.');
});

it('re-approves a blocked company', function () {
    $company = createTestCompany('approved');

    Plugin::getInstance()->companyApproval->block($company);

    expect($company->companyStatus)->toBe(Company::STATUS_BLOCKED);

    Plugin::getInstance()->companyApproval->approve($company);

    expect($company->companyStatus)->toBe(Company::STATUS_APPROVED)
        ->and(companyRowStatus($company->id))->toBe(Company::STATUS_APPROVED);
});
