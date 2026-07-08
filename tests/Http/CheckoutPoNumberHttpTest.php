<?php

use craft\commerce\elements\Order;
use craft\db\Query;
use totalwebcreations\b2bcommerce\enums\CompanyRole;
use totalwebcreations\b2bcommerce\Plugin;

// createTestCompany(), createTestUserWithPassword(), trackElement() are loaded globally by the
// suite; httpClient(), loginAs(), postAction(), httpTestPassword() live in tests/Http/helpers.php.

/**
 * Reads a cart's id from the commerce get-cart JSON payload, mirroring QuotesHttpTest's helper.
 */
function poHttpCartId(GuzzleHttp\Client $client): int
{
    $response = $client->get('/actions/commerce/cart/get-cart', [
        'headers' => ['Accept' => 'application/json'],
    ]);

    $data = json_decode((string) $response->getBody(), true);

    return (int) ($data['cart']['id'] ?? 0);
}

it('rejects an anonymous set-reference post with a login requirement', function () {
    $client = httpClient();

    $response = postAction($client, 'b2b-commerce/checkout/set-reference', ['poNumber' => 'PO-6006']);

    // requireLogin() sends anonymous storefront posts to a 400/redirect, never a 200 success.
    expect($response->getStatusCode())->not->toBe(200);
});

it('saves a PO number for a logged-in company member', function () {
    $company = createTestCompany('approved');
    $member = createTestUserWithPassword('po_member_' . uniqid() . '@example.test');
    Plugin::getInstance()->companyMembers->addUserToCompany($member->id, $company->id, CompanyRole::Admin);

    $client = httpClient();
    loginAs($client, $member->email, httpTestPassword());

    $response = postAction($client, 'b2b-commerce/checkout/set-reference', ['poNumber' => 'PO-6006']);

    expect($response->getStatusCode())->toBe(200);

    $cartId = poHttpCartId($client);
    expect($cartId)->toBeGreaterThan(0);

    // Track the cart created by the running dev-site process so afterEach hard-deletes it.
    $cart = Order::find()->id($cartId)->status(null)->one();

    if ($cart !== null) {
        trackElement($cart);
    }

    $saved = (new Query())
        ->select('poNumber')
        ->from('{{%b2b_order_references}}')
        ->where(['orderId' => $cartId])
        ->scalar();

    expect($saved)->toBe('PO-6006');
});

it('refuses a logged-in user who is not a company member', function () {
    $loner = createTestUserWithPassword('po_loner_' . uniqid() . '@example.test');

    $client = httpClient();
    loginAs($client, $loner->email, httpTestPassword());

    $response = postAction($client, 'b2b-commerce/checkout/set-reference', ['poNumber' => 'PO-6006']);

    // asFailure over XHR replies 400 with a message, matching the sibling guard responses
    // (ApprovalsHttpTest, TeamHttpTest) for a signed-in user who fails a membership check.
    expect($response->getStatusCode())->toBe(400);
});
