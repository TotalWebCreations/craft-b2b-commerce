<?php

use totalwebcreations\b2bcommerce\enums\CompanyRole;
use totalwebcreations\b2bcommerce\Plugin;

it('exposes the company approval tiers through b2bContext', function () {
    [$company, $admin] = gqlCompanyWithAdmin(['approvalThreshold' => 100.0]);

    // Phase 18 fixtures: a two-band ladder.
    Plugin::getInstance()->approvalTiers->setTier($company->id, 1, 100.0, 'approver', false);
    Plugin::getInstance()->approvalTiers->setTier($company->id, 2, 1000.0, 'admin', true);

    asGqlIdentity($admin, function () {
        $result = runB2bGql(b2bGqlSchema(B2B_GQL_FULL_SCOPE), <<<'GQL'
            query {
                b2bContext {
                    approvalTiers { level minAmount approverRole departmentScoped }
                }
            }
        GQL);

        expect($result['errors'] ?? null)->toBeNull();

        $tiers = $result['data']['b2bContext']['approvalTiers'];

        expect($tiers)->toHaveCount(2)
            ->and($tiers[0]['level'])->toBe(1)
            ->and($tiers[0]['minAmount'])->toBe(100.0)
            ->and($tiers[0]['approverRole'])->toBe('approver')
            ->and($tiers[0]['departmentScoped'])->toBeFalse()
            ->and($tiers[1]['level'])->toBe(2)
            ->and($tiers[1]['approverRole'])->toBe('admin')
            ->and($tiers[1]['departmentScoped'])->toBeTrue();
    });
});

it('exposes the per-approval step ladder through a b2bContext approval', function () {
    [$company, $purchaser] = gqlCompanyWithAdmin(['approvalThreshold' => 0.0]);
    // Re-home the purchaser as a Purchaser so the order is gated for approval.
    Plugin::getInstance()->companyMembers->addUserToCompany($purchaser->id, $company->id, CompanyRole::Purchaser);

    $approver = createTestUser('gql_ladder_appr_' . uniqid() . '@example.test');
    Plugin::getInstance()->companyMembers->addUserToCompany($approver->id, $company->id, CompanyRole::Approver);

    // Two-level ladder so submitting builds real step rows.
    Plugin::getInstance()->approvalTiers->setTier($company->id, 1, 0.0, 'approver', false);
    Plugin::getInstance()->approvalTiers->setTier($company->id, 2, 500.0, 'approver', false);

    $cart = approvalCart($purchaser, 800.0);
    Plugin::getInstance()->approvals->submitForApproval($cart, $purchaser);

    // Level 1 approves so its step carries a resolver and a resolved date.
    Plugin::getInstance()->approvals->approve($cart->id, $approver);

    asGqlIdentity($purchaser, function () {
        $result = runB2bGql(b2bGqlSchema(B2B_GQL_FULL_SCOPE), <<<'GQL'
            query {
                b2bContext {
                    myApprovalRequests {
                        orderId
                        steps { level status resolvedByName }
                    }
                }
            }
        GQL);

        expect($result['errors'] ?? null)->toBeNull();

        $requests = $result['data']['b2bContext']['myApprovalRequests'];

        expect($requests)->toHaveCount(1);

        $steps = $requests[0]['steps'];

        expect($steps)->toHaveCount(2)
            ->and($steps[0]['level'])->toBe(1)
            ->and($steps[0]['status'])->toBe('approved')
            ->and($steps[0]['resolvedByName'])->not->toBeNull()
            ->and($steps[1]['level'])->toBe(2)
            ->and($steps[1]['status'])->toBe('pending');
    });
});
