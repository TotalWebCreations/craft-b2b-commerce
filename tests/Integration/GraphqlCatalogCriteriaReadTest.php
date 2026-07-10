<?php

it('exposes the company catalog criteria through b2bContext, null when unrestricted', function () {
    [, $adminOpen] = gqlCompanyWithAdmin();

    asGqlIdentity($adminOpen, function () {
        $result = runB2bGql(b2bGqlSchema(B2B_GQL_FULL_SCOPE), '{ b2bContext { catalogCriteria } }');

        expect($result['errors'] ?? null)->toBeNull()
            ->and($result['data']['b2bContext']['catalogCriteria'])->toBeNull();
    });

    // Phase 21: a company with a catalog condition set reports a non-null criteria string.
    [$companyScoped, $adminScoped] = gqlCompanyWithAdmin();
    $companyScoped->catalogCondition = catalogConditionForType(quickOrderProductType());

    if (!craftApp()->getElements()->saveElement($companyScoped)) {
        throw new RuntimeException('Could not save scoped company: ' . implode(', ', $companyScoped->getFirstErrors()));
    }

    asGqlIdentity($adminScoped, function () {
        $result = runB2bGql(b2bGqlSchema(B2B_GQL_FULL_SCOPE), '{ b2bContext { catalogCriteria } }');

        expect($result['errors'] ?? null)->toBeNull()
            ->and($result['data']['b2bContext']['catalogCriteria'])->not->toBeNull();
    });
});
