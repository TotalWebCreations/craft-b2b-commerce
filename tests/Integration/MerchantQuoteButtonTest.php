<?php

use craft\commerce\elements\Order;
use craft\elements\User;
use totalwebcreations\b2bcommerce\elements\Quote;
use totalwebcreations\b2bcommerce\enums\QuoteStatus;

// quoteCartWithItem(), createTestCompany(), createTestUser(), trackElement() live in
// tests/Integration/helpers.php. The quote-row inserter below is kept local (rather than reused
// from QuoteMerchantTest.php) so this file runs standalone under Pest's per-file filtering, which
// does not load sibling test files.

/**
 * Creates a tracked Quote element for the given order, bypassing requestQuote/createMerchantQuote
 * so a test can pin an exact status without the surrounding send/validate flow.
 */
function insertQuoteRowForButtonTest(int $orderId, string $status, int $companyId): void
{
    $quote = new Quote();
    $quote->orderId = $orderId;
    $quote->companyId = $companyId;
    $quote->quoteStatus = $status;
    $quote->acceptToken = craftApp()->getSecurity()->generateRandomString(40);

    if (!craftApp()->getElements()->saveElement($quote)) {
        throw new RuntimeException('Could not save quote element: ' . implode(', ', $quote->getFirstErrors()));
    }

    trackElement($quote);
}

/**
 * Creates a tracked admin user, so a test can act as someone who passes every permission
 * check (checkPermission short-circuits to true for admins).
 */
function quoteButtonAdmin(): User
{
    $admin = createTestUser('quote_button_admin_' . uniqid() . '@example.test');
    $admin->admin = true;
    craftApp()->getElements()->saveElement($admin);

    return $admin;
}

/**
 * Renders the Commerce order-edit secondary-actions hook for the given order, returning the
 * injected HTML. Fires the exact hook Commerce's _edit.twig fires, with the order in the template
 * context, so the plugin's registered callback runs as it does in production. The console test
 * harness has no logged-in user by default, so this temporarily impersonates an admin — the hook
 * itself gates on the manageQuotes permission, which only an authenticated identity can hold.
 */
function renderOrderQuoteHook(Order $order): string
{
    $userSession = craftApp()->getUser();
    $previous = $userSession->getIdentity();
    $userSession->setIdentity(quoteButtonAdmin());

    try {
        $view = craftApp()->getView();
        $context = ['order' => $order];

        return $view->invokeHook('cp.commerce.order.edit.order-secondary-actions', $context);
    } finally {
        $userSession->setIdentity($previous);
    }
}

it('renders the send-as-quote button for a plain, non-completed order', function () {
    $order = quoteCartWithItem();

    expect(renderOrderQuoteHook($order))->toContain('b2b/quotes/new');
});

it('hides the button when the order is already a quote', function () {
    $company = createTestCompany();
    $order = quoteCartWithItem();
    insertQuoteRowForButtonTest($order->id, QuoteStatus::Sent->value, $company->id);

    expect(renderOrderQuoteHook($order))->not->toContain('b2b/quotes/new');
});

it('hides the button for a completed order', function () {
    $order = quoteCartWithItem();
    $order->markAsComplete();

    expect(renderOrderQuoteHook(Order::find()->id($order->id)->status(null)->one()))
        ->not->toContain('b2b/quotes/new');
});
