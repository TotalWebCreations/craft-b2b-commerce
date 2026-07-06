<?php

namespace totalwebcreations\b2bcommerce\migrations;

use craft\db\Migration;
use craft\db\Table;

class m260711_000000_add_customer_group_to_companies extends Migration
{
    public function safeUp(): bool
    {
        if ($this->db->columnExists('{{%b2b_companies}}', 'customerGroupId')) {
            return true;
        }

        $this->addColumn(
            '{{%b2b_companies}}',
            'customerGroupId',
            $this->integer()->after('approvalThreshold')
        );

        $this->addForeignKey(
            null,
            '{{%b2b_companies}}',
            ['customerGroupId'],
            Table::USERGROUPS,
            ['id'],
            'SET NULL'
        );

        return true;
    }
}
