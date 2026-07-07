<?php

use craft\db\Query;
use totalwebcreations\b2bcommerce\elements\Approval;
use totalwebcreations\b2bcommerce\enums\ApprovalStatus;
use totalwebcreations\b2bcommerce\enums\CompanyRole;
use totalwebcreations\b2bcommerce\Plugin;

// approvalMember() lives in NeedsApprovalTest.php; approvalCart(), insertApprovalRow() in
// ApprovalSubmitTest.php; createTestUser() in helpers.php — all loaded globally by the suite.

it('creates an element row when an order is submitted for approval, findable as an element', function () {
    [$purchaser, $company] = approvalMember(CompanyRole::Purchaser, 500.0);
    $cart = approvalCart($purchaser, 750.0);
    $orderId = $cart->id;

    Plugin::getInstance()->approvals->submitForApproval($cart, $purchaser);

    $approval = Approval::find()->orderId($orderId)->status(null)->one();

    $elementExists = (new Query())
        ->from('{{%elements}}')
        ->where(['id' => $approval?->id, 'type' => Approval::class])
        ->exists();

    // The element identity sits around the b2b_approvals row: the element id is the table PK, while
    // orderId stays the business key every enforcement guard reads.
    expect($approval)->not->toBeNull()
        ->and($elementExists)->toBeTrue()
        ->and($approval->orderId)->toBe((int) $orderId)
        ->and($approval->companyId)->toBe($company->id)
        ->and($approval->approvalStatus)->toBe(ApprovalStatus::Pending->value)
        ->and($approval->getStatus())->toBe(ApprovalStatus::Pending->value)
        ->and($approval->thresholdAmount)->toBe(500.0);
});

it('filters approvals by status through the element query', function () {
    [$purchaser, $company] = approvalMember(CompanyRole::Purchaser, 500.0);
    $approvedOrder = approvalCart($purchaser, 600.0);
    $declinedOrder = approvalCart($purchaser, 700.0);
    insertApprovalRow($approvedOrder->id, $company->id, ApprovalStatus::Approved->value, $purchaser->id, 500.0);
    insertApprovalRow($declinedOrder->id, $company->id, ApprovalStatus::Declined->value, $purchaser->id, 500.0);

    $approvedOrderIds = array_map(
        fn(Approval $approval) => $approval->orderId,
        Approval::find()->approvalStatus(ApprovalStatus::Approved->value)->status(null)->all()
    );

    expect($approvedOrderIds)->toContain($approvedOrder->id)
        ->and($approvedOrderIds)->not->toContain($declinedOrder->id);
});

it('reflects a status transition on the element index after an approver approves', function () {
    [$purchaser, $company] = approvalMember(CompanyRole::Purchaser, 500.0);
    $approver = createTestUser('appr_element_' . uniqid() . '@example.test');
    Plugin::getInstance()->companyMembers->addUserToCompany($approver->id, $company->id, CompanyRole::Approver);

    $cart = approvalCart($purchaser, 600.0);
    insertApprovalRow($cart->id, $company->id, ApprovalStatus::Pending->value, $purchaser->id, 500.0);

    Plugin::getInstance()->approvals->approve($cart->id, $approver);

    $approval = Approval::find()->orderId($cart->id)->status(null)->one();

    expect($approval->getStatus())->toBe(ApprovalStatus::Approved->value);
});

it('leaves no zombie Approval element behind when its order is hard-deleted', function () {
    [$purchaser, $company] = approvalMember(CompanyRole::Purchaser, 500.0);
    $cart = approvalCart($purchaser, 600.0);
    insertApprovalRow($cart->id, $company->id, ApprovalStatus::Pending->value, $purchaser->id, 500.0);

    $approvalId = Approval::find()->orderId($cart->id)->status(null)->one()->id;

    // Hard-delete the order. Its orderId CASCADE drops the b2b_approvals row; the orphan-cleanup
    // handler hard-deletes the backing Approval element so no row-less zombie remains.
    craftApp()->getElements()->deleteElement($cart, true);

    $elementExists = (new Query())
        ->from('{{%elements}}')
        ->where(['id' => $approvalId, 'type' => Approval::class])
        ->exists();
    $rowExists = (new Query())->from('{{%b2b_approvals}}')->where(['orderId' => $cart->id])->exists();

    expect($elementExists)->toBeFalse()
        ->and($rowExists)->toBeFalse();
});

it('exposes an All source plus one source per approval status', function () {
    $reflection = new ReflectionMethod(Approval::class, 'defineSources');
    $reflection->setAccessible(true);

    $sources = $reflection->invoke(null, 'index');
    $keys = array_column($sources, 'key');

    expect($keys)->toContain('*')
        ->and($keys)->toContain('status:' . ApprovalStatus::Pending->value)
        ->and($keys)->toContain('status:' . ApprovalStatus::Approved->value)
        ->and($keys)->toContain('status:' . ApprovalStatus::Declined->value);
});
