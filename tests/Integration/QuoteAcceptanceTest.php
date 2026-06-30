<?php

use craft\commerce\elements\Order;
use craft\helpers\StringHelper;
use totalwebcreations\b2bcommerce\enums\QuoteStatus;
use totalwebcreations\b2bcommerce\Plugin;
use totalwebcreations\b2bcommerce\variables\B2bVariable;
use yii\base\InvalidArgumentException;

// insertQuoteRow(), bareQuoteOrder() live in QuoteMerchantTest.php; quoteMember(),
// quoteCartWithItem(), quoteRow(), mailSnapshot(), decodedMailSince() in helpers.php;
// withQuoteIdentity() in QuoteRequestTest.php — all loaded globally by the suite.

it('accepts a sent quote: flips the status, returns the quote order and completes at the frozen total', function () {
    [$user, $company] = quoteMember();
    $order = quoteCartWithItem();
    $token = insertQuoteRow($order->id, QuoteStatus::Requested->value, $company->id, $user->id);

    // Send freezes the prices; capture the frozen total right after so the completed
    // order can be proven to charge exactly that (the brief's frozen-total guarantee).
    Plugin::getInstance()->quotes->markSent($order, null);
    $frozenTotal = Order::find()->id($order->id)->status(null)->one()->getTotalPrice();

    // setSessionCartNumber() only writes the cookie on a web request, so the real
    // session hand-off is proven in the Http suite; here the durable guarantees are
    // asserted: the returned order IS the quote order, the row flips to accepted, the
    // line-item freeze stays armed until completion, and the order can then be taken
    // through checkout at the frozen total.
    $returned = Plugin::getInstance()->quotes->acceptByToken($token, $user);

    expect((int) $returned->id)->toBe($order->id)
        ->and(quoteRow($order->id)['status'])->toBe(QuoteStatus::Accepted->value)
        ->and(Plugin::getInstance()->quotes->orderHasLineItemFrozenQuote($order->id))->toBeTrue();

    $reloaded = Order::find()->id($order->id)->status(null)->one();

    // Completion via a console request (the completion veto is storefront-scoped); an accepted
    // quote passes the veto in any case, and completing clears the line-item freeze.
    expect($reloaded->markAsComplete())->toBeTrue()
        ->and($reloaded->isCompleted)->toBeTrue()
        ->and($reloaded->getTotalPrice())->toBe($frozenTotal)
        ->and(Plugin::getInstance()->quotes->orderHasLineItemFrozenQuote($order->id))->toBeFalse();
});

it('lazily expires a sent quote past its validity and refuses to accept it', function () {
    [$user, $company] = quoteMember();
    $order = bareQuoteOrder();
    $token = insertQuoteRow($order->id, QuoteStatus::Sent->value, $company->id, $user->id, new DateTime('-1 day'));

    expect(fn () => Plugin::getInstance()->quotes->acceptByToken($token, $user))
        ->toThrow(InvalidArgumentException::class, 'This quote has expired.');

    expect(quoteRow($order->id)['status'])->toBe(QuoteStatus::Expired->value);
});

it('returns one generic message for an unknown token and a wrong-company token (no oracle)', function () {
    [$userA, $companyA] = quoteMember();
    $order = bareQuoteOrder();
    $token = insertQuoteRow($order->id, QuoteStatus::Sent->value, $companyA->id, $userA->id);

    [$userB] = quoteMember();

    $unknownMessage = null;
    $wrongCompanyMessage = null;

    try {
        Plugin::getInstance()->quotes->acceptByToken(StringHelper::randomString(40), $userB);
    } catch (InvalidArgumentException $exception) {
        $unknownMessage = $exception->getMessage();
    }

    try {
        Plugin::getInstance()->quotes->acceptByToken($token, $userB);
    } catch (InvalidArgumentException $exception) {
        $wrongCompanyMessage = $exception->getMessage();
    }

    expect($unknownMessage)->toBe('This quote is not available.')
        ->and($wrongCompanyMessage)->toBe('This quote is not available.')
        ->and($unknownMessage)->toBe($wrongCompanyMessage);
});

it('refuses to accept a quote that has only been requested, not sent', function () {
    [$user, $company] = quoteMember();
    $order = bareQuoteOrder();
    $token = insertQuoteRow($order->id, QuoteStatus::Requested->value, $company->id, $user->id);

    expect(fn () => Plugin::getInstance()->quotes->acceptByToken($token, $user))
        ->toThrow(InvalidArgumentException::class, 'This quote has not been sent yet.');
});

it('refuses to re-accept a quote in a terminal status with the processed message', function () {
    [$user, $company] = quoteMember();
    $order = bareQuoteOrder();
    $token = insertQuoteRow($order->id, QuoteStatus::Accepted->value, $company->id, $user->id);

    expect(fn () => Plugin::getInstance()->quotes->acceptByToken($token, $user))
        ->toThrow(InvalidArgumentException::class, 'This quote has already been processed.');
});

it('reports an already-expired quote as expired, not merely processed, on accept', function () {
    [$user, $company] = quoteMember();
    $order = bareQuoteOrder();
    $token = insertQuoteRow($order->id, QuoteStatus::Expired->value, $company->id, $user->id);

    expect(fn () => Plugin::getInstance()->quotes->acceptByToken($token, $user))
        ->toThrow(InvalidArgumentException::class, 'This quote has expired.');
});

it('declines a sent quote by token: records the reason and mails the store admin', function () {
    [$user, $company] = quoteMember();
    $order = bareQuoteOrder();
    $token = insertQuoteRow($order->id, QuoteStatus::Sent->value, $company->id, $user->id);

    $snapshot = mailSnapshot();

    Plugin::getInstance()->quotes->declineByToken($token, $user, 'Found a better price elsewhere');

    $row = quoteRow($order->id);
    $body = decodedMailSince($snapshot);

    expect($row['status'])->toBe(QuoteStatus::Declined->value)
        ->and($row['declineReason'])->toBe('Found a better price elsewhere')
        ->and($body)->toContain('Found a better price elsewhere');
});

it('gives decline the same generic message for an unknown token and a wrong-company token (no oracle)', function () {
    [$userA, $companyA] = quoteMember();
    $order = bareQuoteOrder();
    $token = insertQuoteRow($order->id, QuoteStatus::Sent->value, $companyA->id, $userA->id);

    [$userB] = quoteMember();

    $unknownMessage = null;
    $wrongCompanyMessage = null;

    try {
        Plugin::getInstance()->quotes->declineByToken(StringHelper::randomString(40), $userB, 'nope');
    } catch (InvalidArgumentException $exception) {
        $unknownMessage = $exception->getMessage();
    }

    try {
        Plugin::getInstance()->quotes->declineByToken($token, $userB, 'nope');
    } catch (InvalidArgumentException $exception) {
        $wrongCompanyMessage = $exception->getMessage();
    }

    expect($unknownMessage)->toBe('This quote is not available.')
        ->and($wrongCompanyMessage)->toBe($unknownMessage);
});

it('exposes read-only quote data by token to a company member and hides it from outsiders', function () {
    [$user, $company] = quoteMember();
    $order = quoteCartWithItem();
    $token = insertQuoteRow($order->id, QuoteStatus::Sent->value, $company->id, $user->id);

    $variable = new B2bVariable();

    $forMember = null;
    withQuoteIdentity($user, function () use ($variable, $token, &$forMember) {
        $forMember = $variable->getQuoteByToken($token);
    });

    [$outsider] = quoteMember();

    $forOutsider = null;
    withQuoteIdentity($outsider, function () use ($variable, $token, &$forOutsider) {
        $forOutsider = $variable->getQuoteByToken($token);
    });

    expect($forMember)->not->toBeNull()
        ->and($forMember['status'])->toBe(QuoteStatus::Sent->value)
        ->and($forMember['orderNumber'])->toBe($order->number)
        ->and($forOutsider)->toBeNull();
});
