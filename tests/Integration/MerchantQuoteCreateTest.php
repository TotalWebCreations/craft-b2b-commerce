<?php

use craft\commerce\elements\Order;
use totalwebcreations\b2bcommerce\elements\Company;
use totalwebcreations\b2bcommerce\enums\ApprovalStatus;
use totalwebcreations\b2bcommerce\enums\CompanyRole;
use totalwebcreations\b2bcommerce\enums\QuoteOrigin;
use totalwebcreations\b2bcommerce\enums\QuoteStatus;
use totalwebcreations\b2bcommerce\Plugin;
use yii\base\InvalidArgumentException;

// quoteMember(), quoteCartWithItem(), quoteRow(), createTestCompany(), createTestUser(),
// mailSnapshot(), decodedMailSince(), asSiteRequest() live in helpers.php;
// insertQuoteRow(), bareQuoteOrder(), storedLineItemQty() in QuoteMerchantTest.php;
// insertApprovalRow() in the approval test helpers — all loaded globally by the suite.

it('auto-links the company, marks it a sent merchant quote and freezes the price', function () {
    [$user, $company] = quoteMember();
    $order = quoteCartWithItem();
    $snapshot = mailSnapshot();

    $quote = Plugin::getInstance()->quotes->createMerchantQuote($order, $user, null, new DateTime('+30 days'));

    $row = quoteRow($order->id);
    $reloaded = Order::find()->id($order->id)->status(null)->one();
    $body = decodedMailSince($snapshot);

    expect($row['origin'])->toBe(QuoteOrigin::Merchant->value)
        ->and($row['status'])->toBe(QuoteStatus::Sent->value)
        ->and((int) $row['companyId'])->toBe($company->id)
        ->and((int) $row['requestedById'])->toBe($user->id)
        ->and($row['validUntil'])->not->toBeNull()
        ->and($reloaded->recalculationMode)->toBe(Order::RECALCULATION_MODE_NONE)
        ->and($body)->toContain('quotes/accept')
        ->and($body)->toContain('quotes/decline')
        ->and($body)->toContain($row['acceptToken'])
        ->and($quote->id)->toBeGreaterThan(0);
});

it('honours an explicit company pick when the customer belongs to it', function () {
    [$user, $companyA] = quoteMember();
    $companyB = createTestCompany(Company::STATUS_APPROVED, 'Second Co');
    Plugin::getInstance()->companyMembers->addUserToCompany($user->id, $companyB->id, CompanyRole::Purchaser);

    $order = quoteCartWithItem();

    Plugin::getInstance()->quotes->createMerchantQuote($order, $user, $companyB->id, null);

    expect((int) quoteRow($order->id)['companyId'])->toBe($companyB->id);
});

it('rejects an explicit company the customer is not a member of', function () {
    [$user] = quoteMember();
    $strangerCompany = createTestCompany(Company::STATUS_APPROVED, 'Stranger Co');
    $order = quoteCartWithItem();

    expect(fn () => Plugin::getInstance()->quotes->createMerchantQuote($order, $user, $strangerCompany->id, null))
        ->toThrow(InvalidArgumentException::class, 'This customer is not a member of the selected company.');

    expect(quoteRow($order->id))->toBeNull();
});

it('rejects an auto-link when the customer has no company', function () {
    $user = createTestUser('merchant_quote_nocompany_' . uniqid() . '@example.test');
    $order = quoteCartWithItem();

    expect(fn () => Plugin::getInstance()->quotes->createMerchantQuote($order, $user, null, null))
        ->toThrow(InvalidArgumentException::class, 'Assign this customer to a company before sending a quote.');
});

it('rejects a company that is not approved', function () {
    [$user, $company] = quoteMember(Company::STATUS_PENDING);
    $order = quoteCartWithItem();

    expect(fn () => Plugin::getInstance()->quotes->createMerchantQuote($order, $user, $company->id, null))
        ->toThrow(InvalidArgumentException::class, 'The selected company is not approved.');
});

it('refuses an order that is already a quote', function () {
    [$user, $company] = quoteMember();
    $order = quoteCartWithItem();
    insertQuoteRow($order->id, QuoteStatus::Requested->value, $company->id, $user->id);

    expect(fn () => Plugin::getInstance()->quotes->createMerchantQuote($order, $user, null, null))
        ->toThrow(InvalidArgumentException::class, 'This order is already a quote.');
});

it('refuses an order that is part of an approval request', function () {
    [$user, $company] = quoteMember();
    $order = quoteCartWithItem();
    insertApprovalRow($order->id, $company->id, ApprovalStatus::Pending->value, $user->id, 500.0);

    expect(fn () => Plugin::getInstance()->quotes->createMerchantQuote($order, $user, null, null))
        ->toThrow(InvalidArgumentException::class, 'This cart is part of an approval request.');
});

it('refuses an empty order', function () {
    [$user] = quoteMember();
    $order = bareQuoteOrder();

    expect(fn () => Plugin::getInstance()->quotes->createMerchantQuote($order, $user, null, null))
        ->toThrow(InvalidArgumentException::class, 'Your cart is empty.');
});

it('leaves a merchant quote line-item-frozen and vetoes a buyer quantity change', function () {
    [$user, $company] = quoteMember();
    $order = quoteCartWithItem();

    Plugin::getInstance()->quotes->createMerchantQuote($order, $user, null, null);

    expect(Plugin::getInstance()->quotes->orderHasLineItemFrozenQuote($order->id))->toBeTrue();

    $reloaded = Order::find()->id($order->id)->status(null)->one();
    $lineItem = $reloaded->getLineItems()[0];
    $lineItemId = $lineItem->id;
    $lineItem->qty = $lineItem->qty + 3;
    $reloaded->setLineItems([$lineItem]);

    $saved = null;

    asSiteRequest(function () use ($reloaded, &$saved) {
        $saved = craftApp()->getElements()->saveElement($reloaded);
    });

    expect($saved)->toBeFalse()
        ->and($reloaded->getFirstError('lineItems'))->toBe('This cart is part of a quote and cannot be modified.')
        ->and(storedLineItemQty($lineItemId))->toBe(1);
});

it('lets the customer accept a merchant quote via token and complete at the frozen total', function () {
    [$user, $company] = quoteMember();
    $order = quoteCartWithItem();

    Plugin::getInstance()->quotes->createMerchantQuote($order, $user, null, null);

    $frozenTotal = Order::find()->id($order->id)->status(null)->one()->getTotalPrice();
    $token = quoteRow($order->id)['acceptToken'];

    $returned = Plugin::getInstance()->quotes->acceptByToken($token, $user);

    expect((int) $returned->id)->toBe($order->id)
        ->and(quoteRow($order->id)['status'])->toBe(QuoteStatus::Accepted->value);

    $reloaded = Order::find()->id($order->id)->status(null)->one();

    expect($reloaded->markAsComplete())->toBeTrue()
        ->and($reloaded->getTotalPrice())->toBe($frozenTotal);
});
