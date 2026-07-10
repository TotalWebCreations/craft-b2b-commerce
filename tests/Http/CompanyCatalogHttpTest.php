<?php

use craft\commerce\elements\Order;
use totalwebcreations\b2bcommerce\enums\CompanyRole;
use totalwebcreations\b2bcommerce\Plugin;

/**
 * Reads the current cart's line-item count from the commerce get-cart JSON payload.
 */
function httpCatalogCartItemCount(GuzzleHttp\Client $client): int
{
    $response = $client->get('/actions/commerce/cart/get-cart', [
        'headers' => ['Accept' => 'application/json'],
    ]);

    $data = json_decode((string) $response->getBody(), true);

    return count($data['cart']['lineItems'] ?? []);
}

it('refuses a restricted product but accepts an allowed product on the real storefront add path', function () {
    $allowedSku = 'CAT-HTTP-ALLOW-' . substr(uniqid(), -6);
    $deniedSku = 'CAT-HTTP-DENY-' . substr(uniqid(), -6);
    createTestVariant($allowedSku);
    createTestVariantOfType(catalogOtherProductType(), $deniedSku);

    $company = createTestCompany('approved', 'Catalog HTTP Co');
    $company->catalogCondition = catalogConditionForType(quickOrderProductType());
    craftApp()->getElements()->saveElement($company);

    $buyer = createTestUserWithPassword('cat_http_' . uniqid() . '@example.test');
    Plugin::getInstance()->companyMembers->addUserToCompany($buyer->id, $company->id, CompanyRole::Purchaser);

    $client = httpClient();
    loginAs($client, $buyer->email, httpTestPassword());

    // Restricted: quick-order add reports zero added and the cart stays empty.
    postAction($client, 'b2b-commerce/quick-order/add', ['lines' => "{$deniedSku} 1"]);
    expect(httpCatalogCartItemCount($client))->toBe(0);

    // Allowed: the same real path adds the line.
    postAction($client, 'b2b-commerce/quick-order/add', ['lines' => "{$allowedSku} 1"]);
    expect(httpCatalogCartItemCount($client))->toBe(1);
})->group('http');
