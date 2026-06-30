<?php

use totalwebcreations\b2bcommerce\enums\QuoteStatus;
use totalwebcreations\b2bcommerce\Plugin;
use totalwebcreations\b2bcommerce\variables\B2bVariable;

// insertQuoteRow(), bareQuoteOrder() live in QuoteMerchantTest.php; quoteMember(),
// quoteCartWithItem() in helpers.php; withQuoteIdentity() in QuoteRequestTest.php —
// all loaded globally by the suite.

it('attaches company name, requester and order to each CP quote row', function () {
    [$user, $company] = quoteMember();
    $order = quoteCartWithItem();
    insertQuoteRow($order->id, QuoteStatus::Sent->value, $company->id, $user->id, new DateTime('+7 days'));

    $rows = Plugin::getInstance()->quotes->getQuotesForCp();

    $mine = null;
    foreach ($rows as $row) {
        if ($row['orderId'] === $order->id) {
            $mine = $row;
        }
    }

    // The joined shape proves the batch-load stitched orders, companies and requesters
    // onto the rows — the CP table renders straight from these, never re-querying per row.
    expect($mine)->not->toBeNull()
        ->and($mine['status'])->toBe(QuoteStatus::Sent->value)
        ->and($mine['companyName'])->toBe($company->title)
        ->and($mine['requesterName'])->toBe($user->fullName ?: $user->email)
        ->and($mine['order'])->not->toBeNull()
        ->and((int) $mine['order']->id)->toBe($order->id);
});

it('surfaces the decline reason on a declined CP quote row so a merchant can read it back', function () {
    [$user, $company] = quoteMember();
    $order = bareQuoteOrder();
    insertQuoteRow($order->id, QuoteStatus::Sent->value, $company->id, $user->id);

    Plugin::getInstance()->quotes->decline($order, 'Out of stock until Q3', byCustomer: false);

    $rows = Plugin::getInstance()->quotes->getQuotesForCp(QuoteStatus::Declined->value);

    $mine = null;
    foreach ($rows as $row) {
        if ($row['orderId'] === $order->id) {
            $mine = $row;
        }
    }

    expect($mine)->not->toBeNull()
        ->and($mine['declineReason'])->toBe('Out of stock until Q3');
});

it('filters CP quotes by status', function () {
    [$user, $company] = quoteMember();
    $sentOrder = bareQuoteOrder();
    $declinedOrder = bareQuoteOrder();
    insertQuoteRow($sentOrder->id, QuoteStatus::Sent->value, $company->id, $user->id);
    insertQuoteRow($declinedOrder->id, QuoteStatus::Declined->value, $company->id, $user->id);

    $sent = Plugin::getInstance()->quotes->getQuotesForCp(QuoteStatus::Sent->value);
    $orderIds = array_column($sent, 'orderId');

    expect($orderIds)->toContain($sentOrder->id)
        ->and($orderIds)->not->toContain($declinedOrder->id)
        ->and(array_values(array_unique(array_column($sent, 'status'))))->toBe([QuoteStatus::Sent->value]);
});

it('exposes only the current user company quotes through the storefront variable', function () {
    [$userA, $companyA] = quoteMember();
    $sentOrder = quoteCartWithItem();
    $declinedOrder = bareQuoteOrder();
    insertQuoteRow($sentOrder->id, QuoteStatus::Sent->value, $companyA->id, $userA->id);
    insertQuoteRow($declinedOrder->id, QuoteStatus::Declined->value, $companyA->id, $userA->id);

    [$userB, $companyB] = quoteMember();
    $foreignOrder = bareQuoteOrder();
    insertQuoteRow($foreignOrder->id, QuoteStatus::Sent->value, $companyB->id, $userB->id);

    $variable = new B2bVariable();

    $forA = [];
    withQuoteIdentity($userA, function () use ($variable, &$forA) {
        $forA = $variable->getQuotes();
    });

    $byNumber = [];
    foreach ($forA as $quote) {
        $byNumber[$quote['orderNumber']] = $quote;
    }

    // Company A sees both of its own quotes and never company B's; the accept token is
    // exposed only for the still-sent quote a buyer can actually act on.
    expect(array_keys($byNumber))->toContain($sentOrder->number)
        ->and(array_keys($byNumber))->toContain($declinedOrder->number)
        ->and(array_keys($byNumber))->not->toContain($foreignOrder->number)
        ->and($byNumber[$sentOrder->number]['acceptToken'])->not->toBeNull()
        ->and($byNumber[$declinedOrder->number]['acceptToken'])->toBeNull();
});
