<?php

namespace totalwebcreations\b2bcommerce\migrations;

use craft\db\Migration;
use craft\db\Table;

class m260710_120000_add_member_budgets_table extends Migration
{
    public function safeUp(): bool
    {
        if ($this->db->tableExists('{{%b2b_member_budgets}}')) {
            return true;
        }

        $this->createTable('{{%b2b_member_budgets}}', [
            'id' => $this->primaryKey(),
            'companyId' => $this->integer()->notNull(),
            'userId' => $this->integer()->notNull(),
            'amount' => $this->decimal(14, 4)->notNull(),
            'period' => $this->string()->notNull()->defaultValue('monthly'),
            'dateCreated' => $this->dateTime()->notNull(),
            'dateUpdated' => $this->dateTime()->notNull(),
            'uid' => $this->uid(),
        ]);

        $this->createIndex(null, '{{%b2b_member_budgets}}', ['companyId', 'userId'], true);
        $this->createIndex(null, '{{%b2b_member_budgets}}', ['companyId']);
        $this->addForeignKey(null, '{{%b2b_member_budgets}}', ['companyId'], '{{%b2b_companies}}', ['id'], 'CASCADE');
        $this->addForeignKey(null, '{{%b2b_member_budgets}}', ['userId'], Table::USERS, ['id'], 'CASCADE');

        return true;
    }
}
