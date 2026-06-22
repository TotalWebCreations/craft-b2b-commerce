<?php

namespace totalwebcreations\b2bcommerce\migrations;

use craft\db\Migration;

class m260708_120000_add_order_company_table extends Migration
{
    public function safeUp(): bool
    {
        if ($this->db->tableExists('{{%b2b_order_company}}')) {
            return true;
        }

        $this->createTable('{{%b2b_order_company}}', [
            'orderId' => $this->integer()->notNull(),
            'companyId' => $this->integer()->notNull(),
            'dateCreated' => $this->dateTime()->notNull(),
            'dateUpdated' => $this->dateTime()->notNull(),
            'uid' => $this->uid(),
            'PRIMARY KEY([[orderId]])',
        ]);

        $this->createIndex(null, '{{%b2b_order_company}}', ['companyId']);
        $this->addForeignKey(null, '{{%b2b_order_company}}', ['orderId'], '{{%commerce_orders}}', ['id'], 'CASCADE');
        $this->addForeignKey(null, '{{%b2b_order_company}}', ['companyId'], '{{%b2b_companies}}', ['id'], 'CASCADE');

        return true;
    }
}
