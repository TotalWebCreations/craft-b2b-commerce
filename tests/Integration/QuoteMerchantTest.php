<?php

use craft\commerce\elements\Order;
use craft\commerce\Plugin as Commerce;
use craft\db\Query;
use craft\helpers\Db;
use craft\helpers\StringHelper;
use totalwebcreations\b2bcommerce\enums\QuoteStatus;
use totalwebcreations\b2bcommerce\Plugin;
use yii\base\InvalidArgumentException;

/**
 * Creates and saves a tracked bare cart order (no line items). Enough for the
 * pure-SQL flows (decline, expiry) that never touch the order element.
 */
function bareQuoteOrder(): Order
{
    $order = new Order();
    $order->number = md5(uniqid((string) mt_rand(), true));

    if (!craftApp()->getElements()->saveElement($order)) {
        throw new RuntimeException('Could not save bare quote order: ' . implode(', ', $order->getFirstErrors()));
    }

    trackElement($order);

    return $order;
}

/**
 * Inserts a quote row for the given order directly, bypassing requestQuote so a
 * test can pin an exact status and validity. Returns the accept token.
 */
function insertQuoteRow(
    int $orderId,
    string $status,
    int $companyId,
    ?int $requestedById = null,
    ?DateTime $validUntil = null,
): string {
    $token = StringHelper::randomString(40);

    Db::insert('{{%b2b_quotes}}', [
        'orderId' => $orderId,
        'companyId' => $companyId,
        'status' => $status,
        'validUntil' => $validUntil !== null ? Db::prepareDateForDb($validUntil) : null,
        'requestedById' => $requestedById,
        'acceptToken' => $token,
    ]);

    return $token;
}

it('sends a quote: freezes recalculation, stores validity and mails accept and decline links', function () {
    [$user, $company] = quoteMember();
    $order = quoteCartWithItem();
    $token = insertQuoteRow($order->id, QuoteStatus::Requested->value, $company->id, $user->id);

    $validUntil = new DateTime('+14 days');
    $snapshot = mailSnapshot();

    Plugin::getInstance()->quotes->markSent($order, $validUntil);

    $row = quoteRow($order->id);
    $reloaded = Order::find()->id($order->id)->status(null)->one();
    $body = decodedMailSince($snapshot);

    expect($row['status'])->toBe(QuoteStatus::Sent->value)
        ->and($row['validUntil'])->not->toBeNull()
        ->and($reloaded->recalculationMode)->toBe(Order::RECALCULATION_MODE_NONE)
        ->and($body)->toContain('quotes/accept')
        ->and($body)->toContain('quotes/decline')
        ->and($body)->toContain($token);
});

it('freezes prices: a merchant price override survives a resave once the quote is sent', function () {
    [$user, $company] = quoteMember();
    $order = quoteCartWithItem();
    insertQuoteRow($order->id, QuoteStatus::Requested->value, $company->id, $user->id);

    Plugin::getInstance()->quotes->markSent($order, null);

    $reloaded = Order::find()->id($order->id)->status(null)->one();
    $lineItem = $reloaded->getLineItems()[0];
    $lineItem->setPrice(777.0);
    $reloaded->setLineItems([$lineItem]);
    craftApp()->getElements()->saveElement($reloaded);

    $again = Order::find()->id($order->id)->status(null)->one();

    // Under recalculationMode = all the resave would recompute the price back to
    // the variant's 10.00; that it stays at the override proves the freeze holds.
    expect($again->recalculationMode)->toBe(Order::RECALCULATION_MODE_NONE)
        ->and($again->getLineItems()[0]->getPrice())->toBe(777.0);
});

it('refuses to send a quote that is not in the requested status', function () {
    [$user, $company] = quoteMember();
    $order = bareQuoteOrder();
    insertQuoteRow($order->id, QuoteStatus::Accepted->value, $company->id, $user->id);

    expect(fn () => Plugin::getInstance()->quotes->markSent($order, null))
        ->toThrow(InvalidArgumentException::class, 'A quote cannot move from accepted to sent.');
});

it('refuses to act on an order that carries no quote', function () {
    $order = bareQuoteOrder();

    expect(fn () => Plugin::getInstance()->quotes->markSent($order, null))
        ->toThrow(InvalidArgumentException::class, 'This order is not a quote.');
});

it('declines by the merchant: records the reason and mails the requester', function () {
    [$user, $company] = quoteMember();
    $order = bareQuoteOrder();
    insertQuoteRow($order->id, QuoteStatus::Sent->value, $company->id, $user->id);

    $snapshot = mailSnapshot();

    Plugin::getInstance()->quotes->decline($order, 'Out of stock until autumn', false);

    $row = quoteRow($order->id);
    $body = decodedMailSince($snapshot);

    expect($row['status'])->toBe(QuoteStatus::Declined->value)
        ->and($row['declineReason'])->toBe('Out of stock until autumn')
        ->and($body)->toContain('Out of stock until autumn');
});

it('declines by the customer: records the reason and mails the store admin', function () {
    [$user, $company] = quoteMember();
    $order = bareQuoteOrder();
    insertQuoteRow($order->id, QuoteStatus::Sent->value, $company->id, $user->id);

    $snapshot = mailSnapshot();

    Plugin::getInstance()->quotes->decline($order, 'Budget cut for Q3', true);

    $row = quoteRow($order->id);
    $body = decodedMailSince($snapshot);

    expect($row['status'])->toBe(QuoteStatus::Declined->value)
        ->and($row['declineReason'])->toBe('Budget cut for Q3')
        ->and($body)->toContain('Budget cut for Q3');
});

it('refuses to decline a quote that is already in a terminal status', function () {
    [$user, $company] = quoteMember();
    $order = bareQuoteOrder();
    insertQuoteRow($order->id, QuoteStatus::Expired->value, $company->id, $user->id);

    expect(fn () => Plugin::getInstance()->quotes->decline($order, 'too late', false))
        ->toThrow(InvalidArgumentException::class, 'A quote cannot move from expired to declined.');
});

it('expires only open, overdue quotes and leaves fresh and terminal rows untouched', function () {
    $company = createTestCompany();

    $overdueRequested = bareQuoteOrder();
    $overdueSent = bareQuoteOrder();
    $futureSent = bareQuoteOrder();
    $openNoDeadline = bareQuoteOrder();
    $overdueAccepted = bareQuoteOrder();

    insertQuoteRow($overdueRequested->id, QuoteStatus::Requested->value, $company->id, null, new DateTime('-1 day'));
    insertQuoteRow($overdueSent->id, QuoteStatus::Sent->value, $company->id, null, new DateTime('-1 hour'));
    insertQuoteRow($futureSent->id, QuoteStatus::Sent->value, $company->id, null, new DateTime('+7 days'));
    insertQuoteRow($openNoDeadline->id, QuoteStatus::Sent->value, $company->id, null, null);
    insertQuoteRow($overdueAccepted->id, QuoteStatus::Accepted->value, $company->id, null, new DateTime('-1 day'));

    $count = Plugin::getInstance()->quotes->expireOverdue();

    expect($count)->toBe(2)
        ->and(quoteRow($overdueRequested->id)['status'])->toBe(QuoteStatus::Expired->value)
        ->and(quoteRow($overdueSent->id)['status'])->toBe(QuoteStatus::Expired->value)
        ->and(quoteRow($futureSent->id)['status'])->toBe(QuoteStatus::Sent->value)
        ->and(quoteRow($openNoDeadline->id)['status'])->toBe(QuoteStatus::Sent->value)
        ->and(quoteRow($overdueAccepted->id)['status'])->toBe(QuoteStatus::Accepted->value);
});

it('flags open quote carts as unmodifiable and clears the flag once terminal', function () {
    $company = createTestCompany();
    $quotes = Plugin::getInstance()->quotes;

    $requested = bareQuoteOrder();
    $sent = bareQuoteOrder();
    $accepted = bareQuoteOrder();
    $declined = bareQuoteOrder();
    $plainCart = bareQuoteOrder();

    insertQuoteRow($requested->id, QuoteStatus::Requested->value, $company->id);
    insertQuoteRow($sent->id, QuoteStatus::Sent->value, $company->id);
    insertQuoteRow($accepted->id, QuoteStatus::Accepted->value, $company->id);
    insertQuoteRow($declined->id, QuoteStatus::Declined->value, $company->id);

    expect($quotes->orderHasOpenQuote($requested->id))->toBeTrue()
        ->and($quotes->orderHasOpenQuote($sent->id))->toBeTrue()
        ->and($quotes->orderHasOpenQuote($accepted->id))->toBeFalse()
        ->and($quotes->orderHasOpenQuote($declined->id))->toBeFalse()
        ->and($quotes->orderHasOpenQuote($plainCart->id))->toBeFalse()
        ->and($quotes->orderHasOpenQuote(null))->toBeFalse();
});

it('refuses to send a quote with a validity date in the past', function () {
    [$user, $company] = quoteMember();
    $order = bareQuoteOrder();
    insertQuoteRow($order->id, QuoteStatus::Requested->value, $company->id, $user->id);

    expect(fn () => Plugin::getInstance()->quotes->markSent($order, new DateTime('-1 day')))
        ->toThrow(InvalidArgumentException::class, 'The validity date must be in the future.');
});

it('vetoes a quantity change on an open quote cart on a site request', function () {
    $company = createTestCompany();
    $order = quoteCartWithItem();
    insertQuoteRow($order->id, QuoteStatus::Sent->value, $company->id);

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

it('vetoes an in-place options change on an open quote cart on a site request', function () {
    $company = createTestCompany();
    $order = quoteCartWithItem();
    insertQuoteRow($order->id, QuoteStatus::Sent->value, $company->id);

    $reloaded = Order::find()->id($order->id)->status(null)->one();
    $lineItem = $reloaded->getLineItems()[0];
    $lineItemId = $lineItem->id;
    $storedSignature = storedLineItemOptionsSignature($lineItemId);
    $lineItem->setOptions(['engraving' => 'x']);
    $reloaded->setLineItems([$lineItem]);

    $saved = null;

    asSiteRequest(function () use ($reloaded, &$saved) {
        $saved = craftApp()->getElements()->saveElement($reloaded);
    });

    expect($saved)->toBeFalse()
        ->and($reloaded->getFirstError('lineItems'))->toBe('This cart is part of a quote and cannot be modified.')
        ->and(storedLineItemOptionsSignature($lineItemId))->toBe($storedSignature);
});

it('vetoes removing a line item from an open quote cart on a site request', function () {
    $company = createTestCompany();
    $order = quoteCartWithItem();
    insertQuoteRow($order->id, QuoteStatus::Sent->value, $company->id);

    $reloaded = Order::find()->id($order->id)->status(null)->one();
    $reloaded->setLineItems([]);

    $saved = null;

    asSiteRequest(function () use ($reloaded, &$saved) {
        $saved = craftApp()->getElements()->saveElement($reloaded);
    });

    $remaining = (new Query())
        ->from('{{%commerce_lineitems}}')
        ->where(['orderId' => $order->id])
        ->count();

    expect($saved)->toBeFalse()
        ->and((int) $remaining)->toBe(1);
});

it('vetoes adding a new line item to an open quote cart on a site request', function () {
    $company = createTestCompany();
    $order = quoteCartWithItem();
    insertQuoteRow($order->id, QuoteStatus::Sent->value, $company->id);

    $reloaded = Order::find()->id($order->id)->status(null)->one();
    $newVariant = createTestVariant('QUOTE-ADD-' . substr(uniqid(), -6));

    // Resolve the line item in console context (building a purchasable snapshot
    // touches web-request internals the faked request lacks), then add it under
    // a site request so it is the add-line-item guard event that fires.
    $lineItem = Commerce::getInstance()->getLineItems()->resolveLineItem($reloaded, $newVariant->id, []);
    $lineItem->qty = 1;

    asSiteRequest(function () use ($reloaded, $lineItem) {
        $reloaded->addLineItem($lineItem);
    });

    expect($reloaded->getLineItems())->toHaveCount(1)
        ->and($lineItem->getFirstError('purchasableId'))
        ->toBe('This cart is part of a quote and cannot be modified.');
});

it('lets the plugin save an open quote cart through the allow flag', function () {
    $company = createTestCompany();
    $order = quoteCartWithItem();
    insertQuoteRow($order->id, QuoteStatus::Sent->value, $company->id);

    $reloaded = Order::find()->id($order->id)->status(null)->one();
    $lineItem = $reloaded->getLineItems()[0];
    $lineItemId = $lineItem->id;
    $lineItem->qty = 5;
    $reloaded->setLineItems([$lineItem]);

    // A sent quote is frozen (recalculationMode = none); mirror that here so the
    // save does not re-price line items (which would rebuild the CP-URL snapshot
    // the faked request cannot resolve). The allow flag is what lets it through.
    $reloaded->setRecalculationMode(Order::RECALCULATION_MODE_NONE);

    $saved = null;

    asSiteRequest(function () use ($reloaded, &$saved) {
        $saved = Plugin::getInstance()->quotes->allowQuoteSave(
            fn (): bool => craftApp()->getElements()->saveElement($reloaded)
        );
    });

    expect($saved)->toBeTrue()
        ->and(storedLineItemQty($lineItemId))->toBe(5);
});

it('stops vetoing cart mutations once the quote is accepted', function () {
    $company = createTestCompany();
    $order = quoteCartWithItem();
    insertQuoteRow($order->id, QuoteStatus::Accepted->value, $company->id);

    $reloaded = Order::find()->id($order->id)->status(null)->one();
    $lineItem = $reloaded->getLineItems()[0];
    $lineItemId = $lineItem->id;
    $lineItem->qty = 4;
    $reloaded->setLineItems([$lineItem]);

    // An accepted quote inherits the sent quote's frozen mode; keep it frozen so
    // the save does not rebuild the snapshot the faked request cannot resolve.
    $reloaded->setRecalculationMode(Order::RECALCULATION_MODE_NONE);

    $saved = null;

    asSiteRequest(function () use ($reloaded, &$saved) {
        $saved = craftApp()->getElements()->saveElement($reloaded);
    });

    expect($saved)->toBeTrue()
        ->and($reloaded->hasErrors('lineItems'))->toBeFalse()
        ->and(storedLineItemQty($lineItemId))->toBe(4);
});

/**
 * Reads a line item's persisted quantity straight from commerce_lineitems.
 */
function storedLineItemQty(int $lineItemId): int
{
    return (int) (new Query())
        ->select(['qty'])
        ->from('{{%commerce_lineitems}}')
        ->where(['id' => $lineItemId])
        ->scalar();
}

/**
 * Reads a line item's persisted options signature straight from commerce_lineitems.
 */
function storedLineItemOptionsSignature(int $lineItemId): string
{
    return (string) (new Query())
        ->select(['optionsSignature'])
        ->from('{{%commerce_lineitems}}')
        ->where(['id' => $lineItemId])
        ->scalar();
}
