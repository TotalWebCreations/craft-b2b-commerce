<?php

const B2B_GQL_WRITE_SCOPE = ['b2bContext.self:read', 'b2bContext.write:edit'];

it('does not register any b2b mutation when the write scope is off', function () {
    // Read-only scope: the mutation field must be absent from the schema, not merely refused.
    $result = runB2bGql(b2bGqlSchema(B2B_GQL_FULL_SCOPE), 'mutation { setPoNumber(poNumber: "PO-1") }');

    expect($result['errors'] ?? [])->not->toBeEmpty()
        ->and($result['errors'][0]['message'])->toContain('Cannot query field "setPoNumber"');
});

// setPoNumber's resolver calls Commerce::getInstance()->getCarts()->getCart(), which — like every
// Commerce cart lookup — reads the request IP and the cart cookie. GraphQL mutations are served over
// HTTP in production, so the happy-path case runs under asWebRequest() (see tests/Integration/
// helpers.php), which swaps in a real craft\web\Request for the console test harness; this mirrors
// production request handling rather than changing any source behaviour.

it('sets the PO number on the caller’s active cart through the setPoNumber mutation', function () {
    [$company, $admin] = gqlCompanyWithAdmin();

    asGqlIdentity($admin, function () {
        asWebRequest(function () {
            $result = runB2bGql(b2bGqlSchema(B2B_GQL_WRITE_SCOPE), 'mutation { setPoNumber(poNumber: "PO-GQL-1") }');

            expect($result['errors'] ?? null)->toBeNull()
                ->and($result['data']['setPoNumber'])->toBe('PO-GQL-1');
        });
    });
});

it('refuses setPoNumber for a guest even when the write scope is enabled', function () {
    asGqlIdentity(null, function () {
        $result = runB2bGql(b2bGqlSchema(B2B_GQL_WRITE_SCOPE), 'mutation { setPoNumber(poNumber: "PO-X") }');

        expect($result['errors'] ?? [])->not->toBeEmpty()
            ->and($result['errors'][0]['message'])->toContain('signed in');
    });
});

// The same requireMember() guard backs every write mutation, so a guest is refused before the
// underlying service (Quotes / Approvals / OrderLists) is ever reached — no cart, order or list
// lookup happens for an unauthenticated caller.

it('refuses requestQuote for a guest even when the write scope is enabled', function () {
    asGqlIdentity(null, function () {
        $result = runB2bGql(b2bGqlSchema(B2B_GQL_WRITE_SCOPE), 'mutation { requestQuote }');

        expect($result['errors'] ?? [])->not->toBeEmpty()
            ->and($result['errors'][0]['message'])->toContain('signed in');
    });
});

it('refuses submitForApproval for a guest even when the write scope is enabled', function () {
    asGqlIdentity(null, function () {
        $result = runB2bGql(b2bGqlSchema(B2B_GQL_WRITE_SCOPE), 'mutation { submitForApproval }');

        expect($result['errors'] ?? [])->not->toBeEmpty()
            ->and($result['errors'][0]['message'])->toContain('signed in');
    });
});

it('refuses createOrderList for a guest even when the write scope is enabled', function () {
    asGqlIdentity(null, function () {
        $result = runB2bGql(b2bGqlSchema(B2B_GQL_WRITE_SCOPE), 'mutation { createOrderList(name: "Guest List") }');

        expect($result['errors'] ?? [])->not->toBeEmpty()
            ->and($result['errors'][0]['message'])->toContain('signed in');
    });
});
