<?php

use craft\commerce\elements\Order;
use craft\commerce\Plugin as Commerce;
use craft\elements\User;
use totalwebcreations\b2bcommerce\enums\CompanyRole;
use totalwebcreations\b2bcommerce\enums\QuoteStatus;
use totalwebcreations\b2bcommerce\Plugin;

// httpClient(), postAction(), loginAs(), httpTestPassword(), cpManagerClient() live in
// tests/Http/helpers.php; createTestCompany(), createTestUserWithPassword(), createTestVariant(),
// trackElement() in the Integration/Http helpers loaded globally by the suite; creditTestCompany(),
// creditTestInvoiceGateway() live in tests/Integration/CreditBalanceTest.php, also loaded globally.

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
 * Completes a tracked order on the given (invoice) gateway for an already-created, HTTP-loginable
 * member, so Order::EVENT_AFTER_COMPLETE_ORDER links the company exactly as a real checkout would —
 * a real b2b_order_company row with isInvoice = true, ready for the invoice-download gate. Mirrors
 * completedOrderOnGateway() in tests/Integration/CreditBalanceTest.php, but takes the user rather
 * than creating one via createTestUser(), so it can log in over HTTP with a known password.
 */
function completeInvoiceOrderForHttpMember(User $user, int $gatewayId, float $price = 10.0): Order
{
    $variant = createTestVariant('PDF-INV-' . uniqid(), $price);

    $order = new Order();
    $order->number = md5(uniqid((string) mt_rand(), true));
    $order->setCustomer($user);
    $order->gatewayId = $gatewayId;

    $lineItem = Commerce::getInstance()->getLineItems()->resolveLineItem($order, $variant->id);
    $lineItem->qty = 1;
    $order->addLineItem($lineItem);

    if (!craftApp()->getElements()->saveElement($order)) {
        throw new RuntimeException('Could not save test order: ' . implode(', ', $order->getFirstErrors()));
    }

    if (!$order->markAsComplete()) {
        throw new RuntimeException('Could not complete test order.');
    }

    trackElement($order);

    return $order;
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

it('serves a quote PDF to a company member with a valid token', function () {
    $devTemplates = ensureDevSitePdfTemplates();

    $sku = 'PDF-HTTP-' . substr(uniqid(), -6);
    createTestVariant($sku);

    $company = createTestCompany('approved');
    $buyer = createTestUserWithPassword('pdf_http_' . uniqid() . '@example.test');
    Plugin::getInstance()->companyMembers->addUserToCompany($buyer->id, $company->id, CompanyRole::Admin);

    $client = httpClient();
    loginAs($client, $buyer->email, httpTestPassword());
    postAction($client, 'b2b-commerce/quick-order/add', ['lines' => "{$sku} 1"]);

    // Request the quote, then mark it sent so its token is downloadable.
    postAction($client, 'b2b-commerce/quotes/request', ['notes' => 'PDF please.']);
    $row = (new craft\db\Query())->from('{{%b2b_quotes}}')->where(['companyId' => $company->id])->one();
    $order = Order::find()->id((int) $row['orderId'])->status(null)->one();
    trackElement($order);
    Plugin::getInstance()->quotes->markSent($order, null);
    $token = $row['acceptToken'];

    $response = $client->get('/actions/b2b-commerce/documents/quote?quoteToken=' . urlencode($token), [
        'http_errors' => false,
        'allow_redirects' => false,
    ]);

    if ($devTemplates !== null) {
        expect($response->getStatusCode())->toBe(200)
            ->and($response->getHeaderLine('Content-Type'))->toContain('application/pdf');
        @unlink("{$devTemplates}/quote.twig");
        @unlink("{$devTemplates}/invoice.twig");
    } else {
        // Without the dev-site template the render 500s, but authorization still passed (not a 404).
        expect($response->getStatusCode())->not->toBe(404);
    }
});

it('refuses a quote PDF to a member of another company (token gate, no oracle)', function () {
    $sku = 'PDF-GATE-' . substr(uniqid(), -6);
    createTestVariant($sku);

    $companyA = createTestCompany('approved');
    $buyerA = createTestUserWithPassword('pdf_a_' . uniqid() . '@example.test');
    Plugin::getInstance()->companyMembers->addUserToCompany($buyerA->id, $companyA->id, CompanyRole::Admin);

    $clientA = httpClient();
    loginAs($clientA, $buyerA->email, httpTestPassword());
    postAction($clientA, 'b2b-commerce/quick-order/add', ['lines' => "{$sku} 1"]);
    postAction($clientA, 'b2b-commerce/quotes/request', ['notes' => 'A quote.']);
    $row = (new craft\db\Query())->from('{{%b2b_quotes}}')->where(['companyId' => $companyA->id])->one();
    $order = Order::find()->id((int) $row['orderId'])->status(null)->one();
    trackElement($order);

    // A buyer of a DIFFERENT company tries company A's token.
    $companyB = createTestCompany('approved');
    $buyerB = createTestUserWithPassword('pdf_b_' . uniqid() . '@example.test');
    Plugin::getInstance()->companyMembers->addUserToCompany($buyerB->id, $companyB->id, CompanyRole::Admin);

    $clientB = httpClient();
    loginAs($clientB, $buyerB->email, httpTestPassword());

    $response = $clientB->get('/actions/b2b-commerce/documents/quote?quoteToken=' . urlencode($row['acceptToken']), [
        'http_errors' => false,
        'allow_redirects' => false,
    ]);

    expect($response->getStatusCode())->toBe(404);
});

it('refuses a quote PDF download to a guest', function () {
    $client = httpClient();

    $response = $client->get('/actions/b2b-commerce/documents/quote?quoteToken=whatever', [
        'http_errors' => false,
        'allow_redirects' => false,
    ]);

    // requireLogin() denies a guest before any authorization work.
    expect($response->getStatusCode())->not->toBe(200);
});

it('refuses an invoice PDF download to a guest', function () {
    $client = httpClient();

    $response = $client->get('/actions/b2b-commerce/documents/invoice?orderNumber=whatever', [
        'http_errors' => false,
        'allow_redirects' => false,
    ]);

    expect($response->getStatusCode())->not->toBe(200);
});

it('allows a company member to download the invoice PDF for their own completed on-account order', function () {
    $devTemplates = ensureDevSitePdfTemplates();

    $company = creditTestCompany(null);
    $gateway = creditTestInvoiceGateway();

    $buyer = createTestUserWithPassword('pdf_inv_member_' . uniqid() . '@example.test');
    Plugin::getInstance()->companyMembers->addUserToCompany($buyer->id, $company->id, CompanyRole::Admin);

    $order = completeInvoiceOrderForHttpMember($buyer, $gateway->id);

    $client = httpClient();
    loginAs($client, $buyer->email, httpTestPassword());

    $response = $client->get('/actions/b2b-commerce/documents/invoice?orderNumber=' . urlencode($order->number), [
        'http_errors' => false,
        'allow_redirects' => false,
    ]);

    if ($devTemplates !== null) {
        expect($response->getStatusCode())->toBe(200)
            ->and($response->getHeaderLine('Content-Type'))->toContain('application/pdf');
        @unlink("{$devTemplates}/quote.twig");
        @unlink("{$devTemplates}/invoice.twig");
    } else {
        // Without the dev-site template the render 500s, but authorization still passed (not a 404).
        expect($response->getStatusCode())->not->toBe(404);
    }
});

it('refuses an invoice PDF to a member of a different company (no oracle)', function () {
    $company = creditTestCompany(null);
    $gateway = creditTestInvoiceGateway();

    $owner = createTestUserWithPassword('pdf_inv_owner_' . uniqid() . '@example.test');
    Plugin::getInstance()->companyMembers->addUserToCompany($owner->id, $company->id, CompanyRole::Admin);

    $order = completeInvoiceOrderForHttpMember($owner, $gateway->id);

    $otherCompany = createTestCompany('approved');
    $intruder = createTestUserWithPassword('pdf_inv_intruder_' . uniqid() . '@example.test');
    Plugin::getInstance()->companyMembers->addUserToCompany($intruder->id, $otherCompany->id, CompanyRole::Admin);

    $client = httpClient();
    loginAs($client, $intruder->email, httpTestPassword());

    $response = $client->get('/actions/b2b-commerce/documents/invoice?orderNumber=' . urlencode($order->number), [
        'http_errors' => false,
        'allow_redirects' => false,
    ]);

    // Same 404 a guest or an unknown order number would get — no oracle revealing the order exists.
    expect($response->getStatusCode())->toBe(404);
});
