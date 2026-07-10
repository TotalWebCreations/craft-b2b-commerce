<?php

use totalwebcreations\b2bcommerce\enums\BudgetPeriod;
use totalwebcreations\b2bcommerce\enums\QuoteStatus;
use totalwebcreations\b2bcommerce\Plugin;

// Each phase-23 read addition (departments, approval tiers, catalog criteria, statement, PO number)
// is gated by the b2bContext resolver scoping strictly to the caller's own company. GraphqlTest.php
// already proves this for members/orderLists; this test proves it for every phase-23 field in one
// pass, with company A fully populated and company B bare, so a leak of ANY field would show up as
// company B's admin seeing company A's data instead of their own empty/default state.

it('never leaks another company’s departments, approval tiers, catalog criteria, statement or PO numbers through b2bContext', function () {
    [$companyA, $adminA] = gqlCompanyWithAdmin([
        'approvalThreshold' => 100.0,
        'creditLimit' => 5000.0,
        'allowInvoicePayment' => true,
        'paymentTermDays' => 14,
    ]);

    Plugin::getInstance()->departments->createDepartment($companyA, 'A Only Dept', 1000.0, BudgetPeriod::Monthly, $adminA->id);
    Plugin::getInstance()->approvalTiers->setTier($companyA->id, 1, 100.0, 'approver', false);

    $companyA->catalogCondition = catalogConditionForType(quickOrderProductType());

    if (!craftApp()->getElements()->saveElement($companyA)) {
        throw new RuntimeException('Could not save company A: ' . implode(', ', $companyA->getFirstErrors()));
    }

    $invoiceOrder = completedOrderOnGateway($companyA, creditTestInvoiceGateway()->id, 250.0);
    backdateOrder($invoiceOrder, 24);

    $quoteOrder = quoteCartWithItem();
    insertQuoteRow($quoteOrder->id, QuoteStatus::Requested->value, $companyA->id, $adminA->id);
    Plugin::getInstance()->orderReferences->setPoNumber($quoteOrder, 'PO-A-ONLY');

    // Company B: bare, no fixtures at all.
    [$companyB, $adminB] = gqlCompanyWithAdmin();

    $query = <<<'GQL'
        query {
            b2bContext {
                departments { id name }
                approvalTiers { level }
                catalogCriteria
                statement { due1To30 lines { orderNumber } }
                quotes { poNumber }
            }
        }
    GQL;

    asGqlIdentity($adminB, function () use ($query) {
        $result = runB2bGql(b2bGqlSchema(B2B_GQL_FULL_SCOPE), $query);

        expect($result['errors'] ?? null)->toBeNull();

        $context = $result['data']['b2bContext'];

        expect($context['departments'])->toBe([])
            ->and($context['approvalTiers'])->toBe([])
            ->and($context['catalogCriteria'])->toBeNull()
            ->and($context['statement']['due1To30'])->toBe(0.0)
            ->and($context['statement']['lines'])->toBe([])
            ->and($context['quotes'])->toBe([]);
    });

    // Sanity: company A's own admin still sees its own data (the guard is scoping, not a global bug).
    asGqlIdentity($adminA, function () use ($query) {
        $result = runB2bGql(b2bGqlSchema(B2B_GQL_FULL_SCOPE), $query);

        expect($result['errors'] ?? null)->toBeNull();

        $context = $result['data']['b2bContext'];

        expect($context['departments'])->toHaveCount(1)
            ->and($context['approvalTiers'])->toHaveCount(1)
            ->and($context['catalogCriteria'])->not->toBeNull()
            ->and($context['statement']['due1To30'])->toBe(250.0)
            ->and($context['quotes'][0]['poNumber'])->toBe('PO-A-ONLY');
    });
});
