<?php

use totalwebcreations\b2bcommerce\elements\Company;
use totalwebcreations\b2bcommerce\enums\CompanyRole;
use totalwebcreations\b2bcommerce\Plugin;

it('renders the PO number column on the CP company orders page', function () {
    $company = createTestCompany(Company::STATUS_APPROVED, 'CP Orders Http Co');
    $user = createTestUser('cp_http_po_' . uniqid() . '@example.test');
    Plugin::getInstance()->companyMembers->addUserToCompany($user->id, $company->id, CompanyRole::Admin);

    $order = createTestOrder($user);
    Plugin::getInstance()->orderReferences->setPoNumber($order, 'PO-7007');

    // The company has no `requirePoNumber` toggle, so the storefront-only completion backstop
    // stands down regardless of request type and this in-process completion needs no faking.
    $order->markAsComplete();

    [$client] = cpManagerClient();

    $response = $client->get("/admin/b2b/companies/{$company->id}/orders", [
        'headers' => ['Accept' => 'text/html'],
    ]);

    $body = (string) $response->getBody();

    expect($response->getStatusCode())->toBe(200)
        ->and($body)->toContain('PO-7007');
});
