<?php

use totalwebcreations\b2bcommerce\enums\CompanyRole;
use totalwebcreations\b2bcommerce\Plugin;
use totalwebcreations\b2bcommerce\variables\B2bVariable;

it('returns null for a visitor without a company', function () {
    craftApp()->getUser()->setIdentity(null);

    expect((new B2bVariable())->getStatement())->toBeNull();
});

it('returns the signed-in member company statement', function () {
    $company = statementCompany(0);
    $order = completedOrderOnGateway($company, creditTestInvoiceGateway()->id, 90.0);
    backdateOrder($order, 45); // 31-60 bucket

    $member = createTestUser('stmtvar_' . uniqid() . '@example.test');
    Plugin::getInstance()->companyMembers->addUserToCompany($member->id, $company->id, CompanyRole::Purchaser);
    craftApp()->getUser()->setIdentity($member);

    try {
        $statement = (new B2bVariable())->getStatement();

        expect($statement)->not->toBeNull()
            ->and($statement['companyId'])->toBe($company->id)
            ->and($statement['buckets']['31-60'])->toBe($order->getOutstandingBalance());
    } finally {
        craftApp()->getUser()->setIdentity(null);
    }
});
