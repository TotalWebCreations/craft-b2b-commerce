<?php

use craft\commerce\elements\Order;
use craft\commerce\Plugin as Commerce;
use craft\db\Query;
use totalwebcreations\b2bcommerce\elements\Company;
use totalwebcreations\b2bcommerce\enums\BudgetPeriod;
use totalwebcreations\b2bcommerce\enums\CompanyRole;
use totalwebcreations\b2bcommerce\Plugin;

beforeEach(function () {
    if (!httpSiteAvailable()) {
        $this->markTestSkipped('Dev site not reachable.');
    }
});

/**
 * Creates a logged-in storefront rep client with orderOnBehalf + impersonateUsers, plus a member
 * belonging to $company. Returns [client, rep, member].
 *
 * @return array{0: GuzzleHttp\Client, 1: craft\elements\User, 2: craft\elements\User}
 */
function repClientFor(Company $company, bool $assign = true): array
{
    $rep = createTestUserWithPassword('rep_http_' . uniqid() . '@example.test');
    craftApp()->getUserPermissions()->saveUserPermissions($rep->id, ['viewUsers', 'editUsers', 'b2b-commerce:orderOnBehalf', 'impersonateUsers']);

    if ($assign) {
        Plugin::getInstance()->salesReps->assignRep($rep->id, $company->id);
    }

    $member = createTestUserWithPassword('member_http_' . uniqid() . '@example.test');
    Plugin::getInstance()->companyMembers->addUserToCompany($member->id, $company->id, CompanyRole::Purchaser);

    $client = httpClient();
    loginAs($client, $rep->email, httpTestPassword());

    return [$client, $rep, $member];
}

it('refuses act-as without the orderOnBehalf permission', function () {
    $company = createTestCompany(Company::STATUS_APPROVED, 'HTTP NoPerm Co');
    $member = createTestUserWithPassword('m_' . uniqid() . '@example.test');
    Plugin::getInstance()->companyMembers->addUserToCompany($member->id, $company->id, CompanyRole::Purchaser);

    $stranger = createTestUserWithPassword('stranger_' . uniqid() . '@example.test');
    $client = httpClient();
    loginAs($client, $stranger->email, httpTestPassword());

    $response = postAction($client, 'b2b-commerce/sales-rep/act', ['userId' => $member->id]);

    expect($response->getStatusCode())->toBe(403);
});

it('refuses act-as for an unassigned company', function () {
    $company = createTestCompany(Company::STATUS_APPROVED, 'HTTP Unassigned Co');
    [$client, , $member] = repClientFor($company, assign: false);

    $response = postAction($client, 'b2b-commerce/sales-rep/act', ['userId' => $member->id]);

    expect($response->getStatusCode())->toBe(403);
});

it('starts and ends impersonation for an assigned company', function () {
    $company = createTestCompany(Company::STATUS_APPROVED, 'HTTP Assigned Co');
    [$client, $rep, $member] = repClientFor($company);

    $act = postAction($client, 'b2b-commerce/sales-rep/act', ['userId' => $member->id]);
    expect($act->getStatusCode())->toBeIn([200, 302]);

    // The active session is now the member.
    $info = json_decode((string) $client->get('/actions/users/session-info', [
        'headers' => ['Accept' => 'application/json'],
    ])->getBody(), true);
    expect((int) ($info['id'] ?? 0))->toBe($member->id);

    // Ending impersonation restores the rep.
    postAction($client, 'b2b-commerce/sales-rep/end');
    $info = json_decode((string) $client->get('/actions/users/session-info', [
        'headers' => ['Accept' => 'application/json'],
    ])->getBody(), true);
    expect((int) ($info['id'] ?? 0))->toBe($rep->id);
});

/**
 * The dummy gateway id — the running dev site's real, frontend-enabled checkout gateway, so a
 * payment reaches Commerce's processPayment (where the plugin's payment-time gate runs) rather than
 * being turned away by an unavailable gateway.
 */
function salesRepDummyGatewayId(): int
{
    return (int) Commerce::getInstance()->getGateways()->getGatewayByHandle('dummy')->id;
}

/**
 * A valid dummy-gateway credit-card payload, namespaced the way Commerce reads it
 * (paymentForm[dummy][...]). The card passes Luhn and ends in an even digit, which the dummy
 * gateway treats as an approved payment.
 *
 * @return array<string, mixed>
 */
function salesRepDummyCard(): array
{
    return [
        'paymentForm' => [
            'dummy' => [
                'firstName' => 'Sales',
                'lastName' => 'Rep',
                'number' => '4242424242424242',
                'expiry' => '01/' . ((int) date('Y') + 2),
                'cvv' => '123',
            ],
        ],
    ];
}

/**
 * Adds a fresh 500.00 line item to the client's session cart and pays for it over the real web
 * path on the dummy gateway. Returns [httpStatus, completedInDb] and tracks the created order.
 *
 * @return array{0: int, 1: bool}
 */
function salesRepPlaceBehalfOrder(GuzzleHttp\Client $client): array
{
    $sku = 'BEHALF-HTTP-' . substr(uniqid(), -6);
    createTestVariant($sku, 500.0);
    postAction($client, 'b2b-commerce/quick-order/add', ['lines' => "{$sku} 1"]);

    $data = json_decode((string) $client->get('/actions/commerce/cart/get-cart', [
        'headers' => ['Accept' => 'application/json'],
    ])->getBody(), true);
    $cartId = (int) ($data['cart']['id'] ?? 0);

    $pay = postAction($client, 'commerce/payments/pay', ['gatewayId' => salesRepDummyGatewayId()] + salesRepDummyCard());

    $completed = (new Query())
        ->from('{{%commerce_orders}}')
        ->where(['id' => $cartId, 'isCompleted' => true])
        ->exists();

    // Track the cart the running dev-site process created so afterEach hard-deletes it.
    $cart = Order::find()->id($cartId)->status(null)->one();

    if ($cart !== null) {
        trackElement($cart);
    }

    return [$pay->getStatusCode(), $completed];
}

it('holds an on-behalf order to the member\'s own budget over the real web session (no elevation)', function () {
    // Approved, pays-on-account, unlimited credit: the member's own spending budget is the only cap
    // that can turn an order away, and the dummy checkout gateway is available, so the SAME on-behalf
    // order both completes (within budget) and is refused (over budget) — the delta is purely the
    // member's budget, which proves the rep gains no elevation over what the member could place.
    $company = createTestCompany(Company::STATUS_APPROVED, 'HTTP Elevation Co');
    $company->allowInvoicePayment = true;
    $company->creditLimit = null;

    if (!craftApp()->getElements()->saveElement($company)) {
        throw new RuntimeException('Could not save company: ' . implode(', ', $company->getFirstErrors()));
    }

    [$client, , $member] = repClientFor($company);

    // The rep acts as the member over the real web session: the active identity becomes the member,
    // so every downstream storefront guard now runs as the member.
    $act = postAction($client, 'b2b-commerce/sales-rep/act', ['userId' => $member->id]);
    expect($act->getStatusCode())->toBeIn([200, 302]);

    $info = json_decode((string) $client->get('/actions/users/session-info', [
        'headers' => ['Accept' => 'application/json'],
    ])->getBody(), true);
    expect((int) ($info['id'] ?? 0))->toBe($member->id);

    // Control: with no budget, the 500.00 on-behalf order completes end-to-end over the web path.
    [$controlStatus, $controlCompleted] = salesRepPlaceBehalfOrder($client);
    expect($controlStatus)->toBe(200)
        ->and($controlCompleted)->toBeTrue();

    // No-elevation: cap the member at 1.00, and the next identical on-behalf order is refused — the
    // rep cannot push it past the member's own budget.
    Plugin::getInstance()->budgets->setBudget($company, $member->id, 1.0, BudgetPeriod::Monthly);

    [$refusedStatus, $refusedCompleted] = salesRepPlaceBehalfOrder($client);
    expect($refusedStatus)->toBe(400)
        ->and($refusedCompleted)->toBeFalse();
});
