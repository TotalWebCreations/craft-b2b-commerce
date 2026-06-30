<?php

namespace totalwebcreations\b2bcommerce\migrations;

use craft\db\Migration;
use craft\db\Table;

class m260710_000000_add_approvals_table extends Migration
{
    public function safeUp(): bool
    {
        if ($this->db->tableExists('{{%b2b_approvals}}')) {
            return true;
        }

        $this->createTable('{{%b2b_approvals}}', [
            'orderId' => $this->integer()->notNull(),
            'companyId' => $this->integer()->notNull(),
            'status' => $this->string()->notNull()->defaultValue('pending'),
            'requestedById' => $this->integer(),
            'resolvedById' => $this->integer(),
            'reason' => $this->text(),
            'thresholdAmount' => $this->decimal(14, 4),
            'dateCreated' => $this->dateTime()->notNull(),
            'dateUpdated' => $this->dateTime()->notNull(),
            'uid' => $this->uid(),
            'PRIMARY KEY([[orderId]])',
        ]);

        $this->createIndex(null, '{{%b2b_approvals}}', ['companyId']);
        $this->addForeignKey(null, '{{%b2b_approvals}}', ['orderId'], '{{%commerce_orders}}', ['id'], 'CASCADE');
        $this->addForeignKey(null, '{{%b2b_approvals}}', ['companyId'], '{{%b2b_companies}}', ['id'], 'CASCADE');
        $this->addForeignKey(null, '{{%b2b_approvals}}', ['requestedById'], Table::USERS, ['id'], 'SET NULL');
        $this->addForeignKey(null, '{{%b2b_approvals}}', ['resolvedById'], Table::USERS, ['id'], 'SET NULL');

        return true;
    }
}
