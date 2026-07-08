<?php

namespace totalwebcreations\b2bcommerce\migrations;

use craft\db\Migration;

class m260714_000200_add_origin_to_quotes extends Migration
{
    public function safeUp(): bool
    {
        if ($this->db->columnExists('{{%b2b_quotes}}', 'origin')) {
            return true;
        }

        $this->addColumn(
            '{{%b2b_quotes}}',
            'origin',
            $this->string()->notNull()->defaultValue('customer')->after('status')
        );

        return true;
    }
}
