<?php

use craft\commerce\elements\Order;
use craft\db\Query;
use totalwebcreations\b2bcommerce\enums\CompanyRole;
use totalwebcreations\b2bcommerce\enums\QuoteStatus;
use totalwebcreations\b2bcommerce\Plugin;

// httpClient(), loginAs(), postAction(), createTestUserWithPassword(), httpTestPassword(),
// httpCartId() (in QuotesHttpTest.php) live in the Http suite; quoteCartWithItem() and
// createTestCompany() in the Integration helpers — all loaded globally by the suite.

it('lets a customer accept a merchant quote via token and hands the session that quote cart', function () {
    $company = createTestCompany('approved', 'Merchant Accept Co');
    $buyer = createTestUserWithPassword('merchant_accept_' . uniqid() . '@example.test');
    Plugin::getInstance()->companyMembers->addUserToCompany($buyer->id, $company->id, CompanyRole::Purchaser);

    // A merchant builds and sends the quote in-process (the CP flow is proven in Task 5).
    $order = quoteCartWithItem();
    $order->setCustomer($buyer);
    craftApp()->getElements()->saveElement($order);
    trackElement($order);

    Plugin::getInstance()->quotes->createMerchantQuote($order, $buyer, $company->id, null);

    $token = (new Query())
        ->select(['acceptToken'])
        ->from('{{%b2b_quotes}}')
        ->where(['orderId' => $order->id])
        ->scalar();

    $client = httpClient();
    loginAs($client, $buyer->email, httpTestPassword());

    $response = postAction($client, 'b2b-commerce/quotes/accept', ['token' => $token]);
    $body = json_decode((string) $response->getBody(), true);

    expect($response->getStatusCode())->toBe(200)
        ->and($body['cartNumber'] ?? null)->toBe($order->number);

    // The session cart is now the accepted quote order.
    expect(httpCartId($client))->toBe($order->id);

    $row = (new Query())
        ->from('{{%b2b_quotes}}')
        ->where(['orderId' => $order->id])
        ->one();

    expect($row['status'])->toBe(QuoteStatus::Accepted->value)
        ->and(Plugin::getInstance()->quotes->orderHasLineItemFrozenQuote($order->id))->toBeTrue();
});
