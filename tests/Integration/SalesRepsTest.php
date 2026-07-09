<?php

use craft\db\Query;
use totalwebcreations\b2bcommerce\Plugin;

function salesRepsDb(): \craft\db\Connection
{
    return craftApp()->getDb();
}

it('has created the sales-rep schema objects', function () {
    $db = salesRepsDb();

    expect($db->tableExists('{{%b2b_rep_companies}}'))->toBeTrue()
        ->and($db->tableExists('{{%b2b_impersonation_log}}'))->toBeTrue()
        ->and($db->columnExists('{{%b2b_order_company}}', 'placedByRepId'))->toBeTrue();
});

it('bumps the plugin schema version to 1.1.4', function () {
    expect(Plugin::getInstance()->schemaVersion)->toBe('1.1.4');
});
