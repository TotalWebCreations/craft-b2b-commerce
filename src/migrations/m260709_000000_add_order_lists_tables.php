<?php

namespace totalwebcreations\b2bcommerce\migrations;

use craft\db\Migration;
use craft\db\Table;

class m260709_000000_add_order_lists_tables extends Migration
{
    public function safeUp(): bool
    {
        if (!$this->db->tableExists('{{%b2b_order_lists}}')) {
            $this->createTable('{{%b2b_order_lists}}', [
                'id' => $this->primaryKey(),
                'companyId' => $this->integer()->notNull(),
                'name' => $this->string()->notNull(),
                'createdByUserId' => $this->integer(),
                'dateCreated' => $this->dateTime()->notNull(),
                'dateUpdated' => $this->dateTime()->notNull(),
                'uid' => $this->uid(),
            ]);

            $this->createIndex(null, '{{%b2b_order_lists}}', ['companyId']);
            $this->addForeignKey(null, '{{%b2b_order_lists}}', ['companyId'], '{{%b2b_companies}}', ['id'], 'CASCADE');
            $this->addForeignKey(null, '{{%b2b_order_lists}}', ['createdByUserId'], Table::USERS, ['id'], 'SET NULL');
        }

        if (!$this->db->tableExists('{{%b2b_order_list_items}}')) {
            $this->createTable('{{%b2b_order_list_items}}', [
                'id' => $this->primaryKey(),
                'listId' => $this->integer()->notNull(),
                'purchasableId' => $this->integer()->notNull(),
                'qty' => $this->integer()->notNull()->defaultValue(1),
                'dateCreated' => $this->dateTime()->notNull(),
                'dateUpdated' => $this->dateTime()->notNull(),
                'uid' => $this->uid(),
            ]);

            $this->createIndex(null, '{{%b2b_order_list_items}}', ['listId', 'purchasableId'], true);
            $this->addForeignKey(null, '{{%b2b_order_list_items}}', ['listId'], '{{%b2b_order_lists}}', ['id'], 'CASCADE');
            $this->addForeignKey(null, '{{%b2b_order_list_items}}', ['purchasableId'], '{{%commerce_purchasables}}', ['id'], 'CASCADE');
        }

        return true;
    }
}
