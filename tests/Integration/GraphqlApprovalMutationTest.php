<?php

use totalwebcreations\b2bcommerce\enums\CompanyRole;
use totalwebcreations\b2bcommerce\Plugin;

// submitForApproval's resolver calls Commerce::getInstance()->getCarts()->getCart(), so its
// happy-path case runs under asWebRequest() (see the note atop GraphqlWriteScopeTest.php).
// approveOrder/declineOrder are authorized by the orderId's own approval row and never touch the
// "current cart", so they need no such wrapping.

it('submits the caller’s cart for approval through the submitForApproval mutation', function () {
    [$company, $admin] = gqlCompanyWithAdmin(['approvalThreshold' => 0.0]);
    $purchaser = createTestUser('gql_appr_purchaser_' . uniqid() . '@example.test');
    Plugin::getInstance()->companyMembers->addUserToCompany($purchaser->id, $company->id, CompanyRole::Purchaser);
    $product = seedPurchasableProduct();

    asGqlIdentity($purchaser, function () use ($product) {
        asWebRequest(function () use ($product) {
            addLineItemToCurrentCart($product, 1);

            $result = runB2bGql(b2bGqlSchema(B2B_GQL_WRITE_SCOPE), 'mutation { submitForApproval }');

            expect($result['errors'] ?? null)->toBeNull()
                ->and($result['data']['submitForApproval'])->toBeTrue();
        });
    });
});

it('refuses to approve an order belonging to another company (cross-company write)', function () {
    [$companyA, $adminA] = gqlCompanyWithAdmin(['approvalThreshold' => 0.0]);
    $purchaserA = createTestUser('gql_appr_pa_' . uniqid() . '@example.test');
    Plugin::getInstance()->companyMembers->addUserToCompany($purchaserA->id, $companyA->id, CompanyRole::Purchaser);
    $orderId = seedPendingApproval($companyA, $purchaserA);

    // adminB approves from a foreign company: the Approvals service must refuse.
    [$companyB, $adminB] = gqlCompanyWithAdmin();

    asGqlIdentity($adminB, function () use ($orderId) {
        $result = runB2bGql(b2bGqlSchema(B2B_GQL_WRITE_SCOPE), <<<GQL
            mutation { approveOrder(orderId: {$orderId}) }
        GQL);

        expect($result['errors'] ?? [])->not->toBeEmpty();
    });
});
