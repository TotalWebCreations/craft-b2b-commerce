<?php

use craft\commerce\elements\Order;
use craft\db\Query;
use totalwebcreations\b2bcommerce\elements\Approval;
use totalwebcreations\b2bcommerce\enums\ApprovalStatus;
use totalwebcreations\b2bcommerce\enums\CompanyRole;
use totalwebcreations\b2bcommerce\Plugin;

// createTestCompany(), createTestVariant(), trackElement() are loaded globally by the suite;
// createTestUserWithPassword(), httpClient(), loginAs(), postAction(), httpTestPassword() live in
// tests/Http/helpers.php.

/**
 * Reads an approval row's status straight from the table.
 */
function httpApprovalStatus(int $orderId): ?string
{
    $status = (new Query())
        ->select('status')
        ->from('{{%b2b_approvals}}')
        ->where(['orderId' => $orderId])
        ->scalar();

    return $status ?: null;
}

it('refuses over HTTP to let an approver approve their own submitted order (four-eyes)', function () {
    $company = createTestCompany();
    $company->approvalThreshold = 100.0;

    if (!craftApp()->getElements()->saveElement($company)) {
        throw new RuntimeException('Could not save approval company: ' . implode(', ', $company->getFirstErrors()));
    }

    // An admin can both request and approve, so a single account exercises the four-eyes guard: the
    // same person is the requester and the would-be approver.
    $admin = createTestUserWithPassword('self_approve_' . uniqid() . '@example.test');
    Plugin::getInstance()->companyMembers->addUserToCompany($admin->id, $company->id, CompanyRole::Admin);

    $order = new Order();
    $order->number = md5(uniqid((string) mt_rand(), true));
    $order->setCustomer($admin);

    if (!craftApp()->getElements()->saveElement($order)) {
        throw new RuntimeException('Could not save order: ' . implode(', ', $order->getFirstErrors()));
    }

    trackElement($order);

    $variant = createTestVariant('SELFAPR-' . substr(uniqid(), -6), 600.0);
    Plugin::getInstance()->quickOrder->addResolvedPurchasable($order, $variant->id, 1, $variant->sku);
    craftApp()->getElements()->saveElement($order);

    // b2b_approvals is element-backed: the row is written through an Approval element's afterSave,
    // keyed on the element id, while orderId stays the business key the resolve action reads.
    $approval = new Approval();
    $approval->orderId = $order->id;
    $approval->companyId = $company->id;
    $approval->approvalStatus = ApprovalStatus::Pending->value;
    $approval->requestedById = $admin->id;
    $approval->thresholdAmount = 100.0;

    if (!craftApp()->getElements()->saveElement($approval)) {
        throw new RuntimeException('Could not save approval element: ' . implode(', ', $approval->getFirstErrors()));
    }

    trackElement($approval);

    $client = httpClient();
    loginAs($client, $admin->email, httpTestPassword());

    $response = postAction($client, 'b2b-commerce/approvals/approve', [
        'orderId' => $order->id,
    ]);

    $body = json_decode((string) $response->getBody(), true);

    // asFailure over XHR replies 400 with a message (localized on the dev site), and -- the
    // security property -- the row must stay pending: the four-eyes guard let nobody approve it.
    expect($response->getStatusCode())->toBe(400)
        ->and($body['message'] ?? '')->toBeString()->not->toBe('')
        ->and(httpApprovalStatus($order->id))->toBe(ApprovalStatus::Pending->value);
});
