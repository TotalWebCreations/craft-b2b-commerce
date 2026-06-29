<?php

use craft\commerce\elements\Order;
use craft\db\Query;
use totalwebcreations\b2bcommerce\enums\CompanyRole;
use totalwebcreations\b2bcommerce\enums\QuoteStatus;
use totalwebcreations\b2bcommerce\Plugin;

/**
 * Reads a cart's id from the commerce get-cart JSON payload.
 */
function httpCartId(GuzzleHttp\Client $client): int
{
    $response = $client->get('/actions/commerce/cart/get-cart', [
        'headers' => ['Accept' => 'application/json'],
    ]);

    $data = json_decode((string) $response->getBody(), true);

    return (int) ($data['cart']['id'] ?? 0);
}

it('turns an approved buyer cart into a quote and hands the session a fresh cart', function () {
    $sku = 'QUOTE-HTTP-' . substr(uniqid(), -6);
    createTestVariant($sku);

    $company = createTestCompany('approved');
    $buyer = createTestUserWithPassword('quote_http_' . uniqid() . '@example.test');
    Plugin::getInstance()->companyMembers->addUserToCompany($buyer->id, $company->id, CompanyRole::Admin);

    $client = httpClient();
    loginAs($client, $buyer->email, httpTestPassword());

    $added = postAction($client, 'b2b-commerce/quick-order/add', ['lines' => "{$sku} 2"]);
    expect(($added->getStatusCode()))->toBe(200);

    $quoteOrderId = httpCartId($client);
    expect($quoteOrderId)->toBeGreaterThan(0);

    $response = postAction($client, 'b2b-commerce/quotes/request', ['notes' => 'Quote over HTTP.']);

    // asSuccess replies 200 with a confirmation message (Dutch on the dev site),
    // so assert the status and a non-empty message rather than the localized text.
    $body = json_decode((string) $response->getBody(), true);

    expect($response->getStatusCode())->toBe(200)
        ->and($body['message'] ?? '')->toBeString()->not->toBe('');

    // The session no longer points at the quote order: the next cart is a new one.
    $freshCartId = httpCartId($client);

    $row = (new Query())
        ->from('{{%b2b_quotes}}')
        ->where(['orderId' => $quoteOrderId])
        ->one();

    $survivor = Order::find()->id($quoteOrderId)->status(null)->one();

    // Track the orders so afterEach hard-deletes them (the quote row cascades on delete).
    if ($survivor !== null) {
        trackElement($survivor);
    }

    $freshCart = $freshCartId > 0 ? Order::find()->id($freshCartId)->status(null)->one() : null;

    if ($freshCart !== null) {
        trackElement($freshCart);
    }

    expect($freshCartId)->not->toBe($quoteOrderId)
        ->and($row)->not->toBeNull()
        ->and($row['status'])->toBe(QuoteStatus::Requested->value)
        ->and((int) $row['companyId'])->toBe($company->id)
        ->and(strlen($row['acceptToken']))->toBe(40)
        ->and($survivor)->not->toBeNull()
        ->and($survivor->isCompleted)->toBeFalse();
});

it('refuses a quote request from a guest over HTTP', function () {
    $client = httpClient();

    $response = postAction($client, 'b2b-commerce/quotes/request', ['notes' => 'No session.']);

    // requireLogin() denies a guest before any quote work happens, so the action
    // never returns its 200 success response.
    expect($response->getStatusCode())->not->toBe(200);
});
