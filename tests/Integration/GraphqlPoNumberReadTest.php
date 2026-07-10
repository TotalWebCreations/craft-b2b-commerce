<?php

use totalwebcreations\b2bcommerce\enums\CompanyRole;
use totalwebcreations\b2bcommerce\enums\QuoteStatus;
use totalwebcreations\b2bcommerce\Plugin;

// Commerce 5 exposes no Order GraphQL type, interface or top-level `order` query (only products and
// variants), so the buyer PO number is read where B2B already surfaces orders: on the company's
// quotes and approval requests, each resolved from the order's phase-15 b2bPoNumber behaviour and
// scoped to the caller's own company by the b2bContext resolver.

it('exposes the buyer PO number on a company quote through b2bContext', function () {
    [$company, $admin] = gqlCompanyWithAdmin();

    $order = quoteCartWithItem();
    insertQuoteRow($order->id, QuoteStatus::Requested->value, $company->id, $admin->id);
    Plugin::getInstance()->orderReferences->setPoNumber($order, 'PO-2026-777');

    asGqlIdentity($admin, function () {
        $result = runB2bGql(b2bGqlSchema(B2B_GQL_FULL_SCOPE), <<<'GQL'
            query {
                b2bContext {
                    quotes { orderNumber poNumber }
                }
            }
        GQL);

        expect($result['errors'] ?? null)->toBeNull();

        $quotes = $result['data']['b2bContext']['quotes'];

        expect($quotes)->toHaveCount(1)
            ->and($quotes[0]['poNumber'])->toBe('PO-2026-777');
    });
});

it('exposes the buyer PO number on an approval request through b2bContext', function () {
    [$company, $purchaser] = gqlCompanyWithAdmin(['approvalThreshold' => 0.0]);
    Plugin::getInstance()->companyMembers->addUserToCompany($purchaser->id, $company->id, CompanyRole::Purchaser);

    $cart = approvalCart($purchaser, 300.0);
    Plugin::getInstance()->approvals->submitForApproval($cart, $purchaser);
    Plugin::getInstance()->orderReferences->setPoNumber($cart, 'PO-APPR-42');

    asGqlIdentity($purchaser, function () {
        $result = runB2bGql(b2bGqlSchema(B2B_GQL_FULL_SCOPE), <<<'GQL'
            query {
                b2bContext {
                    myApprovalRequests { orderId poNumber }
                }
            }
        GQL);

        expect($result['errors'] ?? null)->toBeNull();

        $requests = $result['data']['b2bContext']['myApprovalRequests'];

        expect($requests)->toHaveCount(1)
            ->and($requests[0]['poNumber'])->toBe('PO-APPR-42');
    });
});

it('reports a null PO number on a quote whose order carries none', function () {
    [$company, $admin] = gqlCompanyWithAdmin();

    $order = quoteCartWithItem();
    insertQuoteRow($order->id, QuoteStatus::Requested->value, $company->id, $admin->id);

    asGqlIdentity($admin, function () {
        $result = runB2bGql(b2bGqlSchema(B2B_GQL_FULL_SCOPE), '{ b2bContext { quotes { poNumber } } }');

        expect($result['errors'] ?? null)->toBeNull()
            ->and($result['data']['b2bContext']['quotes'][0]['poNumber'])->toBeNull();
    });
});
