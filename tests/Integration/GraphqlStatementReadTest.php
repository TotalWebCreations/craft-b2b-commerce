<?php

it('exposes the company statement with aging buckets through b2bContext', function () {
    [$company, $admin] = gqlCompanyWithAdmin([
        'creditLimit' => 5000.0,
        'allowInvoicePayment' => true,
        'paymentTermDays' => 14,
    ]);

    // Phase 22: one overdue invoice landing in the 1–30 bucket. Backdated 24 days on a 14-day term
    // leaves it 10 days past due, so it buckets as due1To30 with a 250.0 outstanding balance.
    $order = completedOrderOnGateway($company, creditTestInvoiceGateway()->id, 250.0);
    backdateOrder($order, 24);

    asGqlIdentity($admin, function () use ($order) {
        $result = runB2bGql(b2bGqlSchema(B2B_GQL_FULL_SCOPE), <<<'GQL'
            query {
                b2bContext {
                    statement {
                        current due1To30 due31To60 due61To90 due90Plus
                        lines { orderNumber outstanding daysPastDue reference }
                    }
                }
            }
        GQL);

        expect($result['errors'] ?? null)->toBeNull();

        $statement = $result['data']['b2bContext']['statement'];

        expect($statement['due1To30'])->toBe(250.0)
            ->and($statement['current'])->toBe(0.0)
            ->and($statement['due31To60'])->toBe(0.0)
            ->and($statement['lines'])->toHaveCount(1)
            ->and($statement['lines'][0]['orderNumber'])->toBe($order->number)
            ->and($statement['lines'][0]['outstanding'])->toBe(250.0)
            ->and($statement['lines'][0]['daysPastDue'])->toBe(10);
    });
});
