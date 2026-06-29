<?php

namespace totalwebcreations\b2bcommerce\migrations;

use craft\db\Migration;
use craft\db\Table;

class m260709_180000_add_quotes_table extends Migration
{
    public function safeUp(): bool
    {
        if ($this->db->tableExists('{{%b2b_quotes}}')) {
            return true;
        }

        $this->createTable('{{%b2b_quotes}}', [
            'orderId' => $this->integer()->notNull(),
            'companyId' => $this->integer()->notNull(),
            'status' => $this->string()->notNull()->defaultValue('requested'),
            'validUntil' => $this->dateTime(),
            'notes' => $this->text(),
            'declineReason' => $this->text(),
            'requestedById' => $this->integer(),
            'acceptToken' => $this->string()->notNull(),
            'dateCreated' => $this->dateTime()->notNull(),
            'dateUpdated' => $this->dateTime()->notNull(),
            'uid' => $this->uid(),
            'PRIMARY KEY([[orderId]])',
        ]);

        $this->createIndex(null, '{{%b2b_quotes}}', ['companyId']);
        $this->createIndex(null, '{{%b2b_quotes}}', ['acceptToken'], true);
        $this->addForeignKey(null, '{{%b2b_quotes}}', ['orderId'], '{{%commerce_orders}}', ['id'], 'CASCADE');
        $this->addForeignKey(null, '{{%b2b_quotes}}', ['companyId'], '{{%b2b_companies}}', ['id'], 'CASCADE');
        $this->addForeignKey(null, '{{%b2b_quotes}}', ['requestedById'], Table::USERS, ['id'], 'SET NULL');

        return true;
    }
}
