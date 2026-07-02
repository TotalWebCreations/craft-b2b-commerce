<?php

use craft\helpers\Db;
use totalwebcreations\b2bcommerce\enums\ApprovalStatus;
use totalwebcreations\b2bcommerce\enums\CompanyRole;
use totalwebcreations\b2bcommerce\Plugin;

// approvalMember() lives in NeedsApprovalTest.php; approvalCart(), insertApprovalRow() in
// ApprovalSubmitTest.php — all loaded globally by the suite.

it('attaches company, requester, resolver, threshold and order to each CP approval row', function () {
    [$purchaser, $company] = approvalMember(CompanyRole::Purchaser, 500.0);
    $approver = createTestUser('cp_resolver_' . uniqid() . '@example.test');
    Plugin::getInstance()->companyMembers->addUserToCompany($approver->id, $company->id, CompanyRole::Approver);

    $order = approvalCart($purchaser, 750.0);

    // A declined row carries a resolver and a reason, so the joined shape proves the batch-load
    // stitched orders, companies, requesters and resolvers on — the CP table renders straight from
    // these, never re-querying per row.
    Db::insert('{{%b2b_approvals}}', [
        'orderId' => $order->id,
        'companyId' => $company->id,
        'status' => ApprovalStatus::Declined->value,
        'requestedById' => $purchaser->id,
        'resolvedById' => $approver->id,
        'reason' => 'Over budget this quarter',
        'thresholdAmount' => 500.0,
    ]);

    $rows = Plugin::getInstance()->approvals->getApprovalsForCp();

    $mine = null;
    foreach ($rows as $row) {
        if ($row['orderId'] === $order->id) {
            $mine = $row;
        }
    }

    expect($mine)->not->toBeNull()
        ->and($mine['status'])->toBe(ApprovalStatus::Declined->value)
        ->and($mine['companyName'])->toBe($company->title)
        ->and($mine['requesterName'])->toBe($purchaser->fullName ?: $purchaser->email)
        ->and($mine['resolverName'])->toBe($approver->fullName ?: $approver->email)
        ->and($mine['thresholdAmount'])->toBe(500.0)
        ->and($mine['reason'])->toBe('Over budget this quarter')
        ->and($mine['order'])->not->toBeNull()
        ->and((int) $mine['order']->id)->toBe($order->id);
});

it('filters CP approvals by status', function () {
    [$purchaser, $company] = approvalMember(CompanyRole::Purchaser, 500.0);
    $pendingOrder = approvalCart($purchaser, 600.0);
    $approvedOrder = approvalCart($purchaser, 700.0);
    insertApprovalRow($pendingOrder->id, $company->id, ApprovalStatus::Pending->value, $purchaser->id, 500.0);
    insertApprovalRow($approvedOrder->id, $company->id, ApprovalStatus::Approved->value, $purchaser->id, 500.0);

    $pending = Plugin::getInstance()->approvals->getApprovalsForCp(ApprovalStatus::Pending->value);
    $orderIds = array_column($pending, 'orderId');

    expect($orderIds)->toContain($pendingOrder->id)
        ->and($orderIds)->not->toContain($approvedOrder->id)
        ->and(array_values(array_unique(array_column($pending, 'status'))))->toBe([ApprovalStatus::Pending->value]);
});
