<?php

use craft\db\Query;

it('has the b2b_order_references table', function () {
    expect(craftApp()->getDb()->tableExists('{{%b2b_order_references}}'))->toBeTrue();
});

it('has the requirePoNumber column on b2b_companies', function () {
    expect(craftApp()->getDb()->columnExists('{{%b2b_companies}}', 'requirePoNumber'))->toBeTrue();
});

it('keys the reference table on orderId', function () {
    $company = createTestCompany('approved');
    $user = createTestUser('poschema_' . uniqid() . '@example.test');

    // A fresh company defaults requirePoNumber to false.
    $requirePoNumber = (new Query())
        ->select('requirePoNumber')
        ->from('{{%b2b_companies}}')
        ->where(['id' => $company->id])
        ->scalar();

    expect((bool) $requirePoNumber)->toBeFalse();
});
