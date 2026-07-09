<?php

namespace totalwebcreations\b2bcommerce\migrations;

use craft\db\Migration;

class m260714_000600_add_catalog_condition_to_companies extends Migration
{
    public function safeUp(): bool
    {
        if ($this->db->columnExists('{{%b2b_companies}}', 'catalogCondition')) {
            return true;
        }

        $this->addColumn(
            '{{%b2b_companies}}',
            'catalogCondition',
            $this->text()->after('customerGroupId')
        );

        return true;
    }
}
