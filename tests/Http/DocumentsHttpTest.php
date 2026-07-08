<?php

use craft\commerce\elements\Order;
use totalwebcreations\b2bcommerce\enums\CompanyRole;
use totalwebcreations\b2bcommerce\enums\QuoteStatus;
use totalwebcreations\b2bcommerce\Plugin;

// httpClient(), postAction(), loginAs(), httpTestPassword(), cpManagerClient() live in
// tests/Http/helpers.php; createTestCompany(), createTestUserWithPassword(), createTestVariant(),
// trackElement() in the Integration/Http helpers loaded globally by the suite; httpCartId() is
// declared in tests/Http/QuotesHttpTest.php, also loaded globally.

/**
 * Ensures the bundled example PDF templates exist inside the running dev site's templates folder so
 * an authorized download can actually render a real PDF rather than 500ing on a missing template.
 * Copied from examples/ and returned so the caller can remove them afterwards. Skips (returns null)
 * when the dev-site templates folder is not found, in which case the caller falls back to asserting a
 * non-error status only.
 */
function ensureDevSitePdfTemplates(): ?string
{
    $devTemplates = dirname(getcwd()) . '/b2b-dev/templates/b2b/pdf';

    if (!is_dir(dirname($devTemplates, 2))) {
        return null;
    }

    if (!is_dir($devTemplates)) {
        mkdir($devTemplates, 0777, true);
    }

    foreach (['quote.twig', 'invoice.twig'] as $file) {
        copy(getcwd() . "/examples/templates/b2b/pdf/{$file}", "{$devTemplates}/{$file}");
    }

    return $devTemplates;
}

/**
 * Reads a cart's id from the commerce get-cart JSON payload. Pest only requires this single test
 * file when run standalone, so this local helper does not depend on QuotesHttpTest.php's own copy.
 */
function documentsHttpCartId(GuzzleHttp\Client $client): int
{
    $response = $client->get('/actions/commerce/cart/get-cart', [
        'headers' => ['Accept' => 'application/json'],
    ]);

    $data = json_decode((string) $response->getBody(), true);

    return (int) ($data['cart']['id'] ?? 0);
}

/**
 * Creates a CP-logged-in client for a fresh user holding exactly accessCp plus the given B2B
 * permission — no more — so a subsequent 200/403 can be attributed to that single permission alone.
 *
 * @return array{0: GuzzleHttp\Client, 1: craft\elements\User}
 */
function cpUserWithPermission(string $permission): array
{
    $user = createTestUserWithPassword('cp_perm_' . uniqid() . '@example.test');
    craftApp()->getUserPermissions()->saveUserPermissions($user->id, ['accessCp', $permission]);

    $client = httpClient();
    loginAs($client, $user->email, httpTestPassword());

    return [$client, $user];
}

it('refuses the CP quote PDF download to a guest (permission gated)', function () {
    $client = httpClient();

    $response = $client->get('/actions/b2b-commerce/documents-cp/quote?quoteId=1', [
        'http_errors' => false,
        'allow_redirects' => false,
    ]);

    // requireCpRequest (beforeAction) + requirePermission('b2b-commerce:manageQuotes') (actionQuote)
    // denies an anonymous request before any PDF work — never a 200.
    expect($response->getStatusCode())->not->toBe(200);
});

it('refuses the CP invoice PDF download to a guest (permission gated)', function () {
    $client = httpClient();

    $response = $client->get('/actions/b2b-commerce/documents-cp/invoice?orderId=1', [
        'http_errors' => false,
        'allow_redirects' => false,
    ]);

    // The invoice action requires the DIFFERENT permission b2b-commerce:manageCompanies (checked in
    // actionInvoice, not beforeAction), so a guest is still denied here before any PDF work.
    expect($response->getStatusCode())->not->toBe(200);
});

it('refuses both CP PDF downloads to a CP user with neither manageQuotes nor manageCompanies', function () {
    $user = createTestUserWithPassword('cp_none_' . uniqid() . '@example.test');
    craftApp()->getUserPermissions()->saveUserPermissions($user->id, ['accessCp']);

    $client = httpClient();
    loginAs($client, $user->email, httpTestPassword());

    $quoteResponse = $client->get('/admin/actions/b2b-commerce/documents-cp/quote?quoteId=1', [
        'http_errors' => false,
        'allow_redirects' => false,
    ]);

    $invoiceResponse = $client->get('/admin/actions/b2b-commerce/documents-cp/invoice?orderId=1', [
        'http_errors' => false,
        'allow_redirects' => false,
    ]);

    expect($quoteResponse->getStatusCode())->toBe(403)
        ->and($invoiceResponse->getStatusCode())->toBe(403);
});

it('allows a manageQuotes holder to download the quote PDF but refuses the invoice PDF', function () {
    $devTemplates = ensureDevSitePdfTemplates();

    $sku = 'PDF-CPQ-' . substr(uniqid(), -6);
    createTestVariant($sku);

    $company = createTestCompany('approved');
    $buyer = createTestUserWithPassword('pdf_cpq_buyer_' . uniqid() . '@example.test');
    Plugin::getInstance()->companyMembers->addUserToCompany($buyer->id, $company->id, CompanyRole::Admin);

    $buyerClient = httpClient();
    loginAs($buyerClient, $buyer->email, httpTestPassword());
    postAction($buyerClient, 'b2b-commerce/quick-order/add', ['lines' => "{$sku} 1"]);
    postAction($buyerClient, 'b2b-commerce/quotes/request', ['notes' => 'CP manageQuotes test.']);

    $row = (new craft\db\Query())->from('{{%b2b_quotes}}')->where(['companyId' => $company->id])->one();
    $order = Order::find()->id((int) $row['orderId'])->status(null)->one();
    trackElement($order);

    [$cpClient] = cpUserWithPermission('b2b-commerce:manageQuotes');

    $quoteResponse = $cpClient->get('/admin/actions/b2b-commerce/documents-cp/quote?quoteId=' . $row['id'], [
        'http_errors' => false,
        'allow_redirects' => false,
    ]);

    if ($devTemplates !== null) {
        expect($quoteResponse->getStatusCode())->toBe(200)
            ->and($quoteResponse->getHeaderLine('Content-Type'))->toContain('application/pdf');
        @unlink("{$devTemplates}/quote.twig");
        @unlink("{$devTemplates}/invoice.twig");
    } else {
        // Without the dev-site template the render 500s, but authorization still passed (not a 403).
        expect($quoteResponse->getStatusCode())->not->toBe(403);
    }

    $invoiceResponse = $cpClient->get('/admin/actions/b2b-commerce/documents-cp/invoice?orderId=' . $order->id, [
        'http_errors' => false,
        'allow_redirects' => false,
    ]);

    // A manageQuotes-only holder lacks manageCompanies, so the invoice action's own permission
    // check denies it — proving the two actions are gated independently, not by one shared check.
    expect($invoiceResponse->getStatusCode())->toBe(403);
});

it('allows a manageCompanies holder to download the invoice PDF but refuses the quote PDF', function () {
    $devTemplates = ensureDevSitePdfTemplates();

    $sku = 'PDF-CPI-' . substr(uniqid(), -6);
    createTestVariant($sku);

    $company = createTestCompany('approved');
    $buyer = createTestUserWithPassword('pdf_cpi_buyer_' . uniqid() . '@example.test');
    Plugin::getInstance()->companyMembers->addUserToCompany($buyer->id, $company->id, CompanyRole::Admin);

    $buyerClient = httpClient();
    loginAs($buyerClient, $buyer->email, httpTestPassword());
    postAction($buyerClient, 'b2b-commerce/quick-order/add', ['lines' => "{$sku} 1"]);

    $orderId = documentsHttpCartId($buyerClient);
    $order = Order::find()->id($orderId)->status(null)->one();
    trackElement($order);

    [$cpClient] = cpManagerClient();

    $invoiceResponse = $cpClient->get("/admin/actions/b2b-commerce/documents-cp/invoice?orderId={$orderId}", [
        'http_errors' => false,
        'allow_redirects' => false,
    ]);

    if ($devTemplates !== null) {
        expect($invoiceResponse->getStatusCode())->toBe(200)
            ->and($invoiceResponse->getHeaderLine('Content-Type'))->toContain('application/pdf');
        @unlink("{$devTemplates}/quote.twig");
        @unlink("{$devTemplates}/invoice.twig");
    } else {
        expect($invoiceResponse->getStatusCode())->not->toBe(403);
    }

    // A manageCompanies-only holder lacks manageQuotes, so the quote action's own permission check
    // denies it, regardless of whether quoteId resolves to a real quote.
    $quoteResponse = $cpClient->get("/admin/actions/b2b-commerce/documents-cp/quote?quoteId={$orderId}", [
        'http_errors' => false,
        'allow_redirects' => false,
    ]);

    expect($quoteResponse->getStatusCode())->toBe(403);
});
