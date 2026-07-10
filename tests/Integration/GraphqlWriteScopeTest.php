<?php

const B2B_GQL_WRITE_SCOPE = ['b2bContext.self:read', 'b2bContext.write:edit'];

it('does not register any b2b mutation when the write scope is off', function () {
    // Read-only scope: the mutation field must be absent from the schema, not merely refused.
    $result = runB2bGql(b2bGqlSchema(B2B_GQL_FULL_SCOPE), 'mutation { setPoNumber(poNumber: "PO-1") }');

    expect($result['errors'] ?? [])->not->toBeEmpty()
        ->and($result['errors'][0]['message'])->toContain('Cannot query field "setPoNumber"');
});
