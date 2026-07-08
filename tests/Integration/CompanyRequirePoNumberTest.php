<?php

use craft\db\Query;
use totalwebcreations\b2bcommerce\elements\Company;

it('defaults requirePoNumber to false and persists a true value', function () {
    $company = createTestCompany('approved');

    expect($company->requirePoNumber)->toBeFalse();

    $company->requirePoNumber = true;

    expect(craftApp()->getElements()->saveElement($company))->toBeTrue();

    $persisted = (new Query())
        ->select('requirePoNumber')
        ->from('{{%b2b_companies}}')
        ->where(['id' => $company->id])
        ->scalar();

    expect((bool) $persisted)->toBeTrue();

    $requeried = Company::find()->id($company->id)->status(null)->one();

    expect($requeried->requirePoNumber)->toBeTrue();
});

it('coerces a posted requirePoNumber string to a bool', function () {
    $company = createTestCompany('approved');

    $company->setAttributesFromRequest(['requirePoNumber' => '1']);

    expect($company->requirePoNumber)->toBeTrue();
});
