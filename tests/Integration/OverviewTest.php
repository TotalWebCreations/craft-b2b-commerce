<?php

use craft\helpers\Db;
use totalwebcreations\b2bcommerce\elements\Company;
use totalwebcreations\b2bcommerce\enums\ApprovalStatus;
use totalwebcreations\b2bcommerce\enums\CompanyRole;
use totalwebcreations\b2bcommerce\enums\QuoteStatus;
use totalwebcreations\b2bcommerce\Plugin;
use totalwebcreations\b2bcommerce\widgets\Overview as OverviewWidget;

// creditTestInvoiceGateway() and completedOrderOnGateway() live in CreditBalanceTest.php;
// quoteCartWithItem() in the integration helpers — all loaded globally by the suite. Counts are
// asserted as deltas around a baseline, so the dev database's existing rows never skew the figures.

/**
 * Inserts a b2b_quotes row directly so a test can pin an exact status without walking the request
 * flow. Db::insert fills the audit columns; acceptToken is not-null with no default, so it is set.
 */
function seedQuoteRow(int $orderId, int $companyId, string $status): void
{
    Db::insert('{{%b2b_quotes}}', [
        'orderId' => $orderId,
        'companyId' => $companyId,
        'status' => $status,
        'acceptToken' => bin2hex(random_bytes(16)),
    ]);
}

it('counts companies by status and mirrors the pending count as the registration queue', function () {
    $overview = Plugin::getInstance()->overview;
    $before = $overview->getStats();

    createTestCompany(Company::STATUS_PENDING);
    createTestCompany(Company::STATUS_PENDING);
    createTestCompany(Company::STATUS_APPROVED);
    createTestCompany(Company::STATUS_BLOCKED);

    $after = $overview->getStats();

    expect($after['companies']['pending'] - $before['companies']['pending'])->toBe(2)
        ->and($after['companies']['approved'] - $before['companies']['approved'])->toBe(1)
        ->and($after['companies']['blocked'] - $before['companies']['blocked'])->toBe(1)
        ->and($after['companies']['total'] - $before['companies']['total'])->toBe(4)
        ->and($after['pendingRegistrations'])->toBe($after['companies']['pending']);
});

it('counts distinct members across companies', function () {
    $overview = Plugin::getInstance()->overview;
    $before = $overview->getStats()['members'];

    $companyA = createTestCompany();
    $companyB = createTestCompany();
    $user = createTestUser('ov_member_' . uniqid() . '@example.test');

    // The same user in two companies counts once: this is reach, not membership rows.
    Plugin::getInstance()->companyMembers->addUserToCompany($user->id, $companyA->id, CompanyRole::Admin);
    Plugin::getInstance()->companyMembers->addUserToCompany($user->id, $companyB->id, CompanyRole::Admin);

    expect($overview->getStats()['members'] - $before)->toBe(1);
});

it('counts only requested and sent quotes as open', function () {
    $overview = Plugin::getInstance()->overview;
    $before = $overview->getStats()['openQuotes'];

    $company = createTestCompany();
    $requested = quoteCartWithItem();
    $sent = quoteCartWithItem();
    $accepted = quoteCartWithItem();

    seedQuoteRow($requested->id, $company->id, QuoteStatus::Requested->value);
    seedQuoteRow($sent->id, $company->id, QuoteStatus::Sent->value);
    seedQuoteRow($accepted->id, $company->id, QuoteStatus::Accepted->value);

    expect($overview->getStats()['openQuotes'] - $before)->toBe(2);
});

it('counts only pending approvals', function () {
    $overview = Plugin::getInstance()->overview;
    $before = $overview->getStats()['pendingApprovals'];

    [$purchaser, $company] = approvalMember(CompanyRole::Purchaser, 500.0);
    $pending = approvalCart($purchaser, 600.0);
    $approved = approvalCart($purchaser, 700.0);

    insertApprovalRow($pending->id, $company->id, ApprovalStatus::Pending->value, $purchaser->id, 500.0);
    insertApprovalRow($approved->id, $company->id, ApprovalStatus::Approved->value, $purchaser->id, 500.0);

    expect($overview->getStats()['pendingApprovals'] - $before)->toBe(1);
});

it('sums the outstanding on-account balance across companies', function () {
    $overview = Plugin::getInstance()->overview;
    $before = $overview->getStats()['outstanding'];

    $company = creditTestCompany(500.0);
    $order = completedOrderOnGateway($company, creditTestInvoiceGateway()->id, 40.0);

    expect($overview->getStats()['outstanding'] - $before)->toBe($order->getTotalPrice());
});

it('renders the widget body without error for a permitted user', function () {
    createTestCompany(Company::STATUS_APPROVED);

    $admin = createTestUser('ov_widget_' . uniqid() . '@example.test');
    $admin->admin = true;
    craftApp()->getElements()->saveElement($admin);

    $userSession = craftApp()->getUser();
    $previous = $userSession->getIdentity();
    $userSession->setIdentity($admin);

    try {
        $body = (new OverviewWidget())->getBodyHtml();
    } finally {
        $userSession->setIdentity($previous);
    }

    expect($body)->toBeString()
        ->and($body)->toContain('Companies')
        ->and($body)->toContain('Outstanding on account');
});
