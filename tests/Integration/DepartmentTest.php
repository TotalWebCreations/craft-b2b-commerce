<?php

use Craft;

it('has created the b2b_departments table and departmentId column', function () {
    $db = Craft::$app->getDb();

    expect($db->tableExists('{{%b2b_departments}}'))->toBeTrue()
        ->and($db->columnExists('{{%b2b_departments}}', 'budgetAmount'))->toBeTrue()
        ->and($db->columnExists('{{%b2b_departments}}', 'budgetPeriod'))->toBeTrue()
        ->and($db->columnExists('{{%b2b_departments}}', 'approverUserId'))->toBeTrue()
        ->and($db->columnExists('{{%b2b_company_users}}', 'departmentId'))->toBeTrue();
});
