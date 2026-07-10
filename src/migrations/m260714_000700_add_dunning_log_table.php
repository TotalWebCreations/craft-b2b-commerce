<?php

namespace totalwebcreations\b2bcommerce\migrations;

use craft\db\Migration;

class m260714_000700_add_dunning_log_table extends Migration
{
    public function safeUp(): bool
    {
        if ($this->db->tableExists('{{%b2b_dunning_log}}')) {
            return true;
        }

        $this->createTable('{{%b2b_dunning_log}}', [
            'id' => $this->primaryKey(),
            'orderId' => $this->integer()->notNull(),
            'offset' => $this->integer()->notNull(),
            'dateSent' => $this->dateTime()->notNull(),
            'dateCreated' => $this->dateTime()->notNull(),
            'dateUpdated' => $this->dateTime()->notNull(),
            'uid' => $this->uid(),
        ]);

        // Unique on (orderId, offset): a given invoice is dunned at most once per day-offset. This is
        // the hard de-dup backstop behind Dunning::hasReminderBeenSent().
        $this->createIndex(null, '{{%b2b_dunning_log}}', ['orderId', 'offset'], true);
        $this->addForeignKey(null, '{{%b2b_dunning_log}}', ['orderId'], '{{%commerce_orders}}', ['id'], 'CASCADE');

        return true;
    }
}
