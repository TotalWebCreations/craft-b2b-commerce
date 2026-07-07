<?php

use craft\db\Query;
use totalwebcreations\b2bcommerce\elements\Quote;
use totalwebcreations\b2bcommerce\enums\QuoteStatus;
use totalwebcreations\b2bcommerce\Plugin;

// insertQuoteRow() lives in QuoteMerchantTest.php; quoteMember(), quoteCartWithItem() in
// helpers.php — all loaded globally by the suite.

it('creates an element row for a requested quote and makes it findable as an element', function () {
    [$user, $company] = quoteMember();
    $cart = quoteCartWithItem();
    $orderId = $cart->id;

    Plugin::getInstance()->quotes->requestQuote($cart, $user, 'Element please.');

    $quote = Quote::find()->orderId($orderId)->status(null)->one();

    $elementExists = (new Query())
        ->from('{{%elements}}')
        ->where(['id' => $quote?->id, 'type' => Quote::class])
        ->exists();

    // The element identity sits around the b2b_quotes row: the element id is the table PK, while
    // orderId stays the business key the enforcement guards read.
    expect($quote)->not->toBeNull()
        ->and($elementExists)->toBeTrue()
        ->and($quote->orderId)->toBe((int) $orderId)
        ->and($quote->companyId)->toBe($company->id)
        ->and($quote->quoteStatus)->toBe(QuoteStatus::Requested->value)
        ->and($quote->getStatus())->toBe(QuoteStatus::Requested->value)
        ->and($quote->notes)->toBe('Element please.');
});

it('filters quotes by status through the element query', function () {
    [$user, $company] = quoteMember();
    $sentOrder = quoteCartWithItem();
    $declinedOrder = quoteCartWithItem();
    insertQuoteRow($sentOrder->id, QuoteStatus::Sent->value, $company->id, $user->id);
    insertQuoteRow($declinedOrder->id, QuoteStatus::Declined->value, $company->id, $user->id);

    $sentOrderIds = array_map(
        fn(Quote $quote) => $quote->orderId,
        Quote::find()->quoteStatus(QuoteStatus::Sent->value)->status(null)->all()
    );

    expect($sentOrderIds)->toContain($sentOrder->id)
        ->and($sentOrderIds)->not->toContain($declinedOrder->id);
});

it('reflects a status transition on the element index after mark-sent', function () {
    [$user, $company] = quoteMember();
    $order = quoteCartWithItem();
    insertQuoteRow($order->id, QuoteStatus::Requested->value, $company->id, $user->id);

    Plugin::getInstance()->quotes->markSent($order, null);

    $quote = Quote::find()->orderId($order->id)->status(null)->one();

    expect($quote->getStatus())->toBe(QuoteStatus::Sent->value);
});

it('releases order enforcement the moment its quote element is deleted', function () {
    [$user, $company] = quoteMember();
    $order = quoteCartWithItem();
    insertQuoteRow($order->id, QuoteStatus::Sent->value, $company->id, $user->id);

    $quotes = Plugin::getInstance()->quotes;

    // A sent quote freezes the order's line items and blocks a fresh quote/approval on it.
    expect($quotes->orderHasLineItemFrozenQuote($order->id))->toBeTrue()
        ->and($quotes->orderHasOpenQuoteRow($order->id))->toBeTrue();

    $quote = Quote::find()->orderId($order->id)->status(null)->one();

    // Delete it exactly the way the CP element index does: a plain delete with no hardDelete flag.
    // Quote::beforeDelete() promotes it to a hard delete so the id → elements CASCADE fires now.
    craftApp()->getElements()->deleteElement($quote);

    $rowExists = (new Query())->from('{{%b2b_quotes}}')->where(['orderId' => $order->id])->exists();
    $elementExists = (new Query())->from('{{%elements}}')->where(['id' => $quote->id])->exists();

    // The row is gone immediately (not left for garbage collection) and the element was hard-deleted
    // rather than trashed (no lingering elements row), so every orderId-keyed guard now reads clear.
    expect($rowExists)->toBeFalse()
        ->and($elementExists)->toBeFalse()
        ->and($quotes->orderHasLineItemFrozenQuote($order->id))->toBeFalse()
        ->and($quotes->orderHasOpenQuoteRow($order->id))->toBeFalse();

    // And with enforcement released, a brand-new quote can be requested on that same order.
    $quotes->requestQuote($order, $user, 'Second time.');
    $newRow = (new Query())->from('{{%b2b_quotes}}')->where(['orderId' => $order->id])->one();

    expect($newRow)->not->toBeNull()
        ->and($newRow['status'])->toBe(QuoteStatus::Requested->value);
});

it('leaves no zombie Quote element behind when its order is hard-deleted', function () {
    [$user, $company] = quoteMember();
    $order = quoteCartWithItem();
    insertQuoteRow($order->id, QuoteStatus::Sent->value, $company->id, $user->id);

    $quoteId = Quote::find()->orderId($order->id)->status(null)->one()->id;

    // Hard-delete the order (a permanent delete). Its orderId CASCADE drops the b2b_quotes row; the
    // orphan-cleanup handler hard-deletes the backing Quote element so no row-less zombie remains.
    craftApp()->getElements()->deleteElement($order, true);

    $elementExists = (new Query())
        ->from('{{%elements}}')
        ->where(['id' => $quoteId, 'type' => Quote::class])
        ->exists();
    $rowExists = (new Query())->from('{{%b2b_quotes}}')->where(['orderId' => $order->id])->exists();

    expect($elementExists)->toBeFalse()
        ->and($rowExists)->toBeFalse();
});

it('exposes an All source plus one source per quote status', function () {
    $reflection = new ReflectionMethod(Quote::class, 'defineSources');
    $reflection->setAccessible(true);

    $sources = $reflection->invoke(null, 'index');
    $keys = array_column($sources, 'key');

    expect($keys)->toContain('*')
        ->and($keys)->toContain('status:' . QuoteStatus::Requested->value)
        ->and($keys)->toContain('status:' . QuoteStatus::Sent->value)
        ->and($keys)->toContain('status:' . QuoteStatus::Accepted->value)
        ->and($keys)->toContain('status:' . QuoteStatus::Declined->value)
        ->and($keys)->toContain('status:' . QuoteStatus::Expired->value);
});
