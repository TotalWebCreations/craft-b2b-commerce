<?php

use craft\commerce\elements\Order;
use craft\db\Query;
use craft\elements\User;
use craft\web\Response as WebResponse;
use totalwebcreations\b2bcommerce\controllers\QuotesController;
use totalwebcreations\b2bcommerce\elements\Company;
use totalwebcreations\b2bcommerce\enums\CompanyRole;
use totalwebcreations\b2bcommerce\enums\QuoteStatus;
use totalwebcreations\b2bcommerce\Plugin;
use yii\base\InvalidArgumentException;

/**
 * Exposes the shared feature gate the quotes action runs first, without the full
 * web request/response plumbing a real action dispatch would need. asFailure() is
 * Craft's own well-covered helper, so it is stubbed to a bare response carrying the
 * message; requireFeature()'s real logic runs unchanged — it is the exact early
 * check in actionRequest.
 */
class QuotesFeatureGateProbe extends QuotesController
{
    public function gate(string $settingName): ?WebResponse
    {
        return $this->requireFeature($settingName);
    }

    public function asFailure(?string $message = null, array $data = [], array $routeParams = []): ?WebResponse
    {
        $response = new WebResponse();
        $response->data = ['message' => $message];

        return $response;
    }
}

/**
 * Runs the callback while $user is the signed-in identity, restoring the previous
 * identity afterwards so the cart service resolves the right customer.
 */
function withQuoteIdentity(User $user, callable $callback): void
{
    $userComponent = craftApp()->getUser();
    $previous = $userComponent->getIdentity();
    $userComponent->setIdentity($user);

    try {
        $callback();
    } finally {
        $userComponent->setIdentity($previous);
    }
}

/**
 * Creates and saves a tracked cart order carrying a single line item.
 */
function quoteCartWithItem(): Order
{
    $order = new Order();
    $order->number = md5(uniqid((string) mt_rand(), true));

    if (!craftApp()->getElements()->saveElement($order)) {
        throw new RuntimeException('Could not save quote cart: ' . implode(', ', $order->getFirstErrors()));
    }

    trackElement($order);

    $variant = createTestVariant('QUOTE-' . substr(uniqid(), -6));
    Plugin::getInstance()->quickOrder->addResolvedPurchasable($order, $variant->id, 1, $variant->sku);
    craftApp()->getElements()->saveElement($order);

    return $order;
}

/**
 * Creates a tracked user attached to a tracked company with the given status.
 *
 * @return array{0: User, 1: Company}
 */
function quoteMember(string $status = Company::STATUS_APPROVED): array
{
    $company = createTestCompany($status, 'Quote Co');
    $user = createTestUser('quote_' . uniqid() . '@example.test');
    Plugin::getInstance()->companyMembers->addUserToCompany($user->id, $company->id, CompanyRole::Admin);

    return [$user, $company];
}

/**
 * Reads the quote row for the given order straight from the table.
 */
function quoteRow(int $orderId): ?array
{
    return (new Query())
        ->from('{{%b2b_quotes}}')
        ->where(['orderId' => $orderId])
        ->one() ?: null;
}

it('records a requested quote, notifies an admin and leaves the order surviving as a non-completed order', function () {
    // The Carts service (getCart) needs a real web request, so this console test
    // asserts the durable guarantees: the quote row, the admin mail, and that the
    // detached order survives (not deleted, still a cart). The real forgetCart
    // hand-off to a fresh session cart is covered end-to-end in the Http suite.
    [$user, $company] = quoteMember();
    $cart = quoteCartWithItem();
    $originalId = $cart->id;

    $mailBefore = mailCount();

    Plugin::getInstance()->quotes->requestQuote($cart, $user, 'Please quote us.');

    $row = quoteRow($originalId);
    $survivor = Order::find()->id($originalId)->status(null)->one();

    expect($row)->not->toBeNull()
        ->and($row['status'])->toBe(QuoteStatus::Requested->value)
        ->and((int) $row['companyId'])->toBe($company->id)
        ->and((int) $row['requestedById'])->toBe($user->id)
        ->and($row['notes'])->toBe('Please quote us.')
        ->and(strlen($row['acceptToken']))->toBe(40)
        ->and(mailCount())->toBeGreaterThan($mailBefore)
        ->and($survivor)->not->toBeNull()
        ->and($survivor->isCompleted)->toBeFalse();
});

it('refuses a quote request for an empty cart', function () {
    [$user] = quoteMember();

    $cart = new Order();
    $cart->number = md5(uniqid((string) mt_rand(), true));
    craftApp()->getElements()->saveElement($cart);
    trackElement($cart);

    expect(fn () => Plugin::getInstance()->quotes->requestQuote($cart, $user, null))
        ->toThrow(InvalidArgumentException::class, 'Your cart is empty.');
});

it('refuses a quote request from a user without a company', function () {
    $user = createTestUser('quote_nocompany_' . uniqid() . '@example.test');
    $cart = quoteCartWithItem();

    expect(fn () => Plugin::getInstance()->quotes->requestQuote($cart, $user, null))
        ->toThrow(InvalidArgumentException::class, 'Only approved company members can request quotes.');
});

it('refuses a quote request from a member of a pending company', function () {
    [$user] = quoteMember(Company::STATUS_PENDING);
    $cart = quoteCartWithItem();

    expect(fn () => Plugin::getInstance()->quotes->requestQuote($cart, $user, null))
        ->toThrow(InvalidArgumentException::class, 'Only approved company members can request quotes.');
});

it('refuses a second quote request for a cart that is already a quote', function () {
    [$user] = quoteMember();
    $cart = quoteCartWithItem();

    Plugin::getInstance()->quotes->requestQuote($cart, $user, null);

    expect(fn () => Plugin::getInstance()->quotes->requestQuote($cart, $user, null))
        ->toThrow(InvalidArgumentException::class, 'This cart is already a quote request.');
});

it('short-circuits the quotes feature gate when the toggle is off', function () {
    $probe = new QuotesFeatureGateProbe('quotes', Plugin::getInstance());

    expect($probe->gate('enableQuotes'))->toBeNull();

    $plugin = Plugin::getInstance();
    Craft::$app->getPlugins()->savePluginSettings($plugin, ['enableQuotes' => false]);

    try {
        $response = $probe->gate('enableQuotes');

        expect($response)->not->toBeNull()
            ->and($response->data['message'])->toBe('This feature is not enabled.');
    } finally {
        Craft::$app->getPlugins()->savePluginSettings($plugin, ['enableQuotes' => true]);
    }
});
