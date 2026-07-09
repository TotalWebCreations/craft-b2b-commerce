<?php

use totalwebcreations\b2bcommerce\elements\Company;

it('persists and reloads a company catalog condition as raw JSON', function () {
    $json = '{"class":"craft\\\\commerce\\\\elements\\\\conditions\\\\products\\\\CatalogPricingRuleProductCondition","conditionRules":[]}';

    $company = createTestCompany('approved', 'Catalog Persist Co');
    $company->catalogCondition = $json;

    expect(craftApp()->getElements()->saveElement($company))->toBeTrue();

    $reloaded = Company::find()->id($company->id)->status(null)->one();

    expect($reloaded->catalogCondition)->toBe($json);
});

it('leaves the catalog condition null by default (full catalog)', function () {
    $company = createTestCompany('approved', 'Catalog Null Co');

    expect(craftApp()->getElements()->saveElement($company))->toBeTrue();

    $reloaded = Company::find()->id($company->id)->status(null)->one();

    expect($reloaded->catalogCondition)->toBeNull();
});
