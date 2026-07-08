<?php

use craft\db\Query;
use totalwebcreations\b2bcommerce\elements\Quote;
use totalwebcreations\b2bcommerce\enums\CompanyRole;
use totalwebcreations\b2bcommerce\enums\QuoteOrigin;
use totalwebcreations\b2bcommerce\enums\QuoteStatus;
use totalwebcreations\b2bcommerce\Plugin;

// httpClient(), loginAs(), postCpAction(), createTestUserWithPassword(), httpTestPassword() live in
// tests/Http/helpers.php; createTestCompany(), quoteCartWithItem(), trackElement() live in
// tests/Integration/helpers.php. quotes-cp is a CP-only controller (requireCpRequest in
// beforeAction), so its actions are reached via postCpAction (/admin/actions/...) rather than the
// site postAction() helper. The two small client-builders below are kept local (rather than reused
// from sibling Http test files) so this file runs standalone under Pest's per-file filtering.

/**
 * Creates a logged-in CP client for a fresh user granted the manageQuotes permission.
 *
 * @return array{0: GuzzleHttp\Client, 1: craft\elements\User}
 */
function cpUserWithManageQuotes(): array
{
    $user = createTestUserWithPassword('cp_manage_quotes_' . uniqid() . '@example.test');
    craftApp()->getUserPermissions()->saveUserPermissions($user->id, ['accessCp', 'b2b-commerce:manageQuotes']);

    $client = httpClient();
    loginAs($client, $user->email, httpTestPassword());

    return [$client, $user];
}

/**
 * Creates a logged-in CP client for a fresh user granted CP access but WITHOUT the
 * manageQuotes permission, so the create action must be refused for it.
 */
function cpUserWithoutManageQuotes(): GuzzleHttp\Client
{
    $user = createTestUserWithPassword('cp_no_manage_quotes_' . uniqid() . '@example.test');
    craftApp()->getUserPermissions()->saveUserPermissions($user->id, ['accessCp']);

    $client = httpClient();
    loginAs($client, $user->email, httpTestPassword());

    return $client;
}

it('sends a merchant quote from the control panel create action', function () {
    $company = createTestCompany('approved', 'Merchant Quote Co');
    $buyer = createTestUserWithPassword('merchant_quote_cp_' . uniqid() . '@example.test');
    Plugin::getInstance()->companyMembers->addUserToCompany($buyer->id, $company->id, CompanyRole::Purchaser);

    // Build a cart with an item and set the buyer as its customer, mirroring what the CP order
    // editor produces for a merchant-built order.
    $order = quoteCartWithItem();
    $order->setCustomer($buyer);
    craftApp()->getElements()->saveElement($order);

    [$client] = cpUserWithManageQuotes();

    $response = postCpAction($client, 'b2b-commerce/quotes-cp/create', [
        'orderId' => $order->id,
        'companyId' => $company->id,
    ]);

    // asSuccess() redirects (302) for a non-JSON CP request; the actual assertion is the
    // resulting quote row.
    expect($response->getStatusCode())->toBe(302);

    $row = (new Query())
        ->from('{{%b2b_quotes}}')
        ->where(['orderId' => $order->id])
        ->one();

    expect($row)->not->toBeNull()
        ->and($row['status'])->toBe(QuoteStatus::Sent->value)
        ->and($row['origin'])->toBe(QuoteOrigin::Merchant->value)
        ->and((int) $row['companyId'])->toBe($company->id)
        ->and((int) $row['requestedById'])->toBe($buyer->id);

    $quote = Quote::find()->orderId($order->id)->status(null)->one();

    if ($quote !== null) {
        trackElement($quote);
    }
});

it('refuses the create action for a non-manager', function () {
    $company = createTestCompany('approved', 'Merchant Quote Co');
    $buyer = createTestUserWithPassword('merchant_quote_denied_' . uniqid() . '@example.test');
    Plugin::getInstance()->companyMembers->addUserToCompany($buyer->id, $company->id, CompanyRole::Purchaser);

    $order = quoteCartWithItem();
    $order->setCustomer($buyer);
    craftApp()->getElements()->saveElement($order);

    // A CP user with access but without the manageQuotes permission.
    $client = cpUserWithoutManageQuotes();

    $response = postCpAction($client, 'b2b-commerce/quotes-cp/create', [
        'orderId' => $order->id,
        'companyId' => $company->id,
    ]);

    expect($response->getStatusCode())->toBe(403);

    $row = (new Query())
        ->from('{{%b2b_quotes}}')
        ->where(['orderId' => $order->id])
        ->one();

    expect($row)->toBeNull();
});

it('cleanly refuses a company the customer is not a member of, without a 500', function () {
    $memberCompany = createTestCompany('approved', 'Merchant Quote Member Co');
    $otherCompany = createTestCompany('approved', 'Merchant Quote Other Co');
    $buyer = createTestUserWithPassword('merchant_quote_wrongco_' . uniqid() . '@example.test');
    Plugin::getInstance()->companyMembers->addUserToCompany($buyer->id, $memberCompany->id, CompanyRole::Purchaser);

    $order = quoteCartWithItem();
    $order->setCustomer($buyer);
    craftApp()->getElements()->saveElement($order);

    [$client] = cpUserWithManageQuotes();

    $response = postCpAction($client, 'b2b-commerce/quotes-cp/create', [
        'orderId' => $order->id,
        'companyId' => $otherCompany->id,
    ]);

    // The service refuses (customer is not a member of $otherCompany); the controller must
    // surface this as a clean redirect-with-flash, never a 500.
    expect($response->getStatusCode())->toBe(302);

    $row = (new Query())
        ->from('{{%b2b_quotes}}')
        ->where(['orderId' => $order->id])
        ->one();

    expect($row)->toBeNull();
});
