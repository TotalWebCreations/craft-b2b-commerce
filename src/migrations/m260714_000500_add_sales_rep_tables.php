<?php

namespace totalwebcreations\b2bcommerce\migrations;

use craft\db\Migration;
use craft\db\Table;

class m260714_000500_add_sales_rep_tables extends Migration
{
    public function safeUp(): bool
    {
        if (!$this->db->tableExists('{{%b2b_rep_companies}}')) {
            $this->createTable('{{%b2b_rep_companies}}', [
                'id' => $this->primaryKey(),
                'repUserId' => $this->integer()->notNull(),
                'companyId' => $this->integer()->notNull(),
                'dateCreated' => $this->dateTime()->notNull(),
                'dateUpdated' => $this->dateTime()->notNull(),
                'uid' => $this->uid(),
            ]);

            $this->createIndex(null, '{{%b2b_rep_companies}}', ['repUserId', 'companyId'], true);
            $this->createIndex(null, '{{%b2b_rep_companies}}', ['companyId']);
            $this->addForeignKey(null, '{{%b2b_rep_companies}}', ['repUserId'], Table::USERS, ['id'], 'CASCADE');
            $this->addForeignKey(null, '{{%b2b_rep_companies}}', ['companyId'], '{{%b2b_companies}}', ['id'], 'CASCADE');
        }

        if (!$this->db->tableExists('{{%b2b_impersonation_log}}')) {
            $this->createTable('{{%b2b_impersonation_log}}', [
                'id' => $this->primaryKey(),
                'repUserId' => $this->integer(),
                'targetUserId' => $this->integer(),
                'companyId' => $this->integer(),
                'orderId' => $this->integer(),
                'action' => $this->string()->notNull(),
                'dateCreated' => $this->dateTime()->notNull(),
                'dateUpdated' => $this->dateTime()->notNull(),
                'uid' => $this->uid(),
            ]);

            $this->createIndex(null, '{{%b2b_impersonation_log}}', ['companyId']);
            $this->createIndex(null, '{{%b2b_impersonation_log}}', ['repUserId']);
            // Audit rows must survive actor deletion, so repUserId/targetUserId use SET NULL like companyId/orderId.
            $this->addForeignKey(null, '{{%b2b_impersonation_log}}', ['repUserId'], Table::USERS, ['id'], 'SET NULL');
            $this->addForeignKey(null, '{{%b2b_impersonation_log}}', ['targetUserId'], Table::USERS, ['id'], 'SET NULL');
            $this->addForeignKey(null, '{{%b2b_impersonation_log}}', ['companyId'], '{{%b2b_companies}}', ['id'], 'SET NULL');
            $this->addForeignKey(null, '{{%b2b_impersonation_log}}', ['orderId'], '{{%commerce_orders}}', ['id'], 'SET NULL');
        }

        if (!$this->db->columnExists('{{%b2b_order_company}}', 'placedByRepId')) {
            $this->addColumn(
                '{{%b2b_order_company}}',
                'placedByRepId',
                $this->integer()->after('isInvoice')
            );

            $this->addForeignKey(null, '{{%b2b_order_company}}', ['placedByRepId'], Table::USERS, ['id'], 'SET NULL');
        }

        return true;
    }
}
