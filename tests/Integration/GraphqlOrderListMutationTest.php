<?php

use totalwebcreations\b2bcommerce\Plugin;

it('creates and renames an order list for the caller’s company through mutations', function () {
    [$company, $admin] = gqlCompanyWithAdmin();

    asGqlIdentity($admin, function () use ($company) {
        $created = runB2bGql(b2bGqlSchema(B2B_GQL_WRITE_SCOPE), 'mutation { createOrderList(name: "Weekly") }');

        expect($created['errors'] ?? null)->toBeNull();

        $listId = (int) $created['data']['createOrderList'];

        expect($listId)->toBeGreaterThan(0);

        $renamed = runB2bGql(b2bGqlSchema(B2B_GQL_WRITE_SCOPE), <<<GQL
            mutation { renameOrderList(listId: {$listId}, name: "Fortnightly") }
        GQL);

        expect($renamed['errors'] ?? null)->toBeNull()
            ->and($renamed['data']['renameOrderList'])->toBeTrue();
    });
});

it('refuses to rename an order list belonging to another company', function () {
    [$companyA, $adminA] = gqlCompanyWithAdmin();
    [$companyB, $adminB] = gqlCompanyWithAdmin();
    $listB = Plugin::getInstance()->orderLists->createList($companyB, 'B list', $adminB->id);

    // adminA (company A) tries to rename company B's list: the service scopes by the passed company,
    // and the resolver passes ONLY the caller's own company, so B's list is not found.
    asGqlIdentity($adminA, function () use ($listB) {
        $result = runB2bGql(b2bGqlSchema(B2B_GQL_WRITE_SCOPE), <<<GQL
            mutation { renameOrderList(listId: {$listB}, name: "Hijacked") }
        GQL);

        expect($result['errors'] ?? [])->not->toBeEmpty();
    });

    // B's list name is unchanged.
    $lists = Plugin::getInstance()->orderLists->getLists($companyB->id);
    expect($lists[0]['name'])->toBe('B list');
});

it('refuses to add an item to an order list belonging to another company', function () {
    [$companyA, $adminA] = gqlCompanyWithAdmin();
    [$companyB, $adminB] = gqlCompanyWithAdmin();
    $listB = Plugin::getInstance()->orderLists->createList($companyB, 'B list', $adminB->id);

    $variant = seedPurchasableProduct();
    Plugin::getInstance()->orderLists->setItem($companyB, $listB, $variant->id, 3);

    // adminA (company A) tries to add an item to company B's list: the service scopes by the passed
    // company, and the resolver passes ONLY the caller's own company, so B's list is not found.
    asGqlIdentity($adminA, function () use ($listB, $variant) {
        $result = runB2bGql(b2bGqlSchema(B2B_GQL_WRITE_SCOPE), <<<GQL
            mutation { addOrderListItem(listId: {$listB}, purchasableId: {$variant->id}, qty: 5) }
        GQL);

        expect($result['errors'] ?? [])->not->toBeEmpty();
    });

    // B's list item is unchanged.
    $items = Plugin::getInstance()->orderLists->getItems($companyB, $listB);
    expect($items)->toHaveCount(1)
        ->and($items[0]['purchasableId'])->toBe($variant->id)
        ->and($items[0]['qty'])->toBe(3);
});
