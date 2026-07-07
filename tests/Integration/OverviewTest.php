<?php

use totalwebcreations\b2bcommerce\elements\Company;
use totalwebcreations\b2bcommerce\elements\Quote;
use totalwebcreations\b2bcommerce\enums\ApprovalStatus;
use totalwebcreations\b2bcommerce\enums\CompanyRole;
use totalwebcreations\b2bcommerce\enums\QuoteStatus;
use totalwebcreations\b2bcommerce\Plugin;
use totalwebcreations\b2bcommerce\widgets\Overview as OverviewWidget;

// creditTestInvoiceGateway() and completedOrderOnGateway() live in CreditBalanceTest.php;
// quoteCartWithItem() in the integration helpers — all loaded globally by the suite. Counts are
// asserted as deltas around a baseline, so the dev database's existing rows never skew the figures.

/**
 * Creates a tracked Quote element so a test can pin an exact status without walking the request
 * flow. Saving the element writes the b2b_quotes row through afterSave.
 */
function seedQuoteRow(int $orderId, int $companyId, string $status): void
{
    $quote = new Quote();
    $quote->orderId = $orderId;
    $quote->companyId = $companyId;
    $quote->quoteStatus = $status;
    $quote->acceptToken = bin2hex(random_bytes(16));

    if (!craftApp()->getElements()->saveElement($quote)) {
        throw new RuntimeException('Could not save quote element: ' . implode(', ', $quote->getFirstErrors()));
    }

    trackElement($quote);
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

/**
 * Creates and saves a tracked admin user, so a test can act as someone who passes every
 * permission check (checkPermission short-circuits to true for admins).
 */
function overviewAdmin(): craft\elements\User
{
    $admin = createTestUser('ov_admin_' . uniqid() . '@example.test');
    $admin->admin = true;
    craftApp()->getElements()->saveElement($admin);

    return $admin;
}

it('badges the B2B nav with the pending registration count for a permitted user', function () {
    $before = Plugin::getInstance()->overview->getPendingRegistrationsCount();

    createTestCompany(Company::STATUS_PENDING);
    createTestCompany(Company::STATUS_PENDING);

    $userSession = craftApp()->getUser();
    $previous = $userSession->getIdentity();
    $userSession->setIdentity(overviewAdmin());

    try {
        $item = Plugin::getInstance()->getCpNavItem();
    } finally {
        $userSession->setIdentity($previous);
    }

    $expected = $before + 2;

    expect($item['badgeCount'])->toBe($expected)
        ->and($item['subnav']['companies']['badgeCount'])->toBe($expected);
});

it('omits the nav badge for a user without manageCompanies', function () {
    // A pending company exists, so the badge is withheld purely by the permission gate, not by an
    // empty queue.
    createTestCompany(Company::STATUS_PENDING);

    $userSession = craftApp()->getUser();
    $previous = $userSession->getIdentity();
    $userSession->setIdentity(createTestUser('ov_noperm_' . uniqid() . '@example.test'));

    try {
        $item = Plugin::getInstance()->getCpNavItem();
    } finally {
        $userSession->setIdentity($previous);
    }

    expect($item)->not->toHaveKey('badgeCount')
        ->and($item['subnav']['companies'])->not->toHaveKey('badgeCount');
});

it('adds a settings subnav item for an admin when admin changes are allowed', function () {
    $userSession = craftApp()->getUser();
    $previous = $userSession->getIdentity();
    $userSession->setIdentity(overviewAdmin());

    try {
        $item = Plugin::getInstance()->getCpNavItem();
    } finally {
        $userSession->setIdentity($previous);
    }

    // The item mirrors Craft's own gate on the plugin-settings screen, so it only appears when
    // admin changes are actually allowed in this environment.
    if (craftApp()->getConfig()->getGeneral()->allowAdminChanges) {
        expect($item['subnav'])->toHaveKey('settings')
            ->and($item['subnav']['settings']['url'])->toContain('settings/plugins/');
    } else {
        expect($item['subnav'])->not->toHaveKey('settings');
    }
});

it('hides the settings subnav from a non-admin', function () {
    $userSession = craftApp()->getUser();
    $previous = $userSession->getIdentity();
    $userSession->setIdentity(createTestUser('ov_plain_' . uniqid() . '@example.test'));

    try {
        $item = Plugin::getInstance()->getCpNavItem();
    } finally {
        $userSession->setIdentity($previous);
    }

    expect($item['subnav'])->not->toHaveKey('settings');
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
