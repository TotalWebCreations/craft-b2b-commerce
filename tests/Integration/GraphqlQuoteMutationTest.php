<?php

use craft\commerce\Plugin as Commerce;
use totalwebcreations\b2bcommerce\Plugin;

// requestQuote's resolver calls Commerce::getInstance()->getCarts()->getCart(), so its happy-path
// case runs under asWebRequest() (see tests/Integration/helpers.php) — see the note atop
// GraphqlWriteScopeTest.php. acceptQuote/declineQuote are authorized by their token and never touch
// the "current cart", so they need no such wrapping.

it('requests a quote for the caller’s cart through the requestQuote mutation', function () {
    [$company, $admin] = gqlCompanyWithAdmin();
    $product = seedPurchasableProduct();

    asGqlIdentity($admin, function () use ($product) {
        asWebRequest(function () use ($product) {
            // Prime the cart with a line item so requestQuote's non-empty guard passes.
            addLineItemToCurrentCart($product, 1);

            $result = runB2bGql(b2bGqlSchema(B2B_GQL_WRITE_SCOPE), 'mutation { requestQuote(notes: "please quote") }');

            expect($result['errors'] ?? null)->toBeNull()
                ->and($result['data']['requestQuote'])->toBeTrue();
        });
    });
});

it('accepts a sent quote by token through the acceptQuote mutation and returns the cart number', function () {
    [$company, $admin] = gqlCompanyWithAdmin();
    $token = seedSentQuoteForCompany($company, $admin);

    asGqlIdentity($admin, function () use ($token) {
        $result = runB2bGql(b2bGqlSchema(B2B_GQL_WRITE_SCOPE), <<<GQL
            mutation { acceptQuote(token: "{$token}") }
        GQL);

        expect($result['errors'] ?? null)->toBeNull()
            ->and($result['data']['acceptQuote'])->toBeString();
    });
});

it('refuses a quote mutation without the write scope', function () {
    [$company, $admin] = gqlCompanyWithAdmin();

    asGqlIdentity($admin, function () {
        $result = runB2bGql(b2bGqlSchema(B2B_GQL_FULL_SCOPE), 'mutation { requestQuote }');

        expect($result['errors'] ?? [])->not->toBeEmpty()
            ->and($result['errors'][0]['message'])->toContain('Cannot query field "requestQuote"');
    });
});
