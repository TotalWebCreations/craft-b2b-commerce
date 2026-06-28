<?php

namespace totalwebcreations\b2bcommerce\migrations;

use craft\db\Migration;
use craft\db\Table;

class Install extends Migration
{
    public function safeUp(): bool
    {
        if (!$this->db->tableExists('{{%b2b_companies}}')) {
            $this->createTable('{{%b2b_companies}}', [
                'id' => $this->integer()->notNull(),
                'name' => $this->string()->notNull(),
                'registrationNumber' => $this->string(),
                'taxId' => $this->string(),
                'status' => $this->string()->notNull()->defaultValue('pending'),
                'creditLimit' => $this->decimal(14, 4),
                'paymentTermDays' => $this->integer(),
                'allowInvoicePayment' => $this->boolean()->notNull()->defaultValue(false),
                'approvalThreshold' => $this->decimal(14, 4),
                'dateCreated' => $this->dateTime()->notNull(),
                'dateUpdated' => $this->dateTime()->notNull(),
                'uid' => $this->uid(),
                'PRIMARY KEY([[id]])',
            ]);

            $this->createIndex(null, '{{%b2b_companies}}', ['status']);
            $this->addForeignKey(null, '{{%b2b_companies}}', ['id'], Table::ELEMENTS, ['id'], 'CASCADE');
        }

        if (!$this->db->tableExists('{{%b2b_company_users}}')) {
            $this->createTable('{{%b2b_company_users}}', [
                'id' => $this->primaryKey(),
                'companyId' => $this->integer()->notNull(),
                'userId' => $this->integer()->notNull(),
                'role' => $this->string()->notNull()->defaultValue('purchaser'),
                'dateCreated' => $this->dateTime()->notNull(),
                'dateUpdated' => $this->dateTime()->notNull(),
                'uid' => $this->uid(),
            ]);

            $this->createIndex(null, '{{%b2b_company_users}}', ['companyId', 'userId'], true);
            $this->createIndex(null, '{{%b2b_company_users}}', ['userId']);
            $this->addForeignKey(null, '{{%b2b_company_users}}', ['companyId'], '{{%b2b_companies}}', ['id'], 'CASCADE');
            $this->addForeignKey(null, '{{%b2b_company_users}}', ['userId'], Table::USERS, ['id'], 'CASCADE');
        }

        if (!$this->db->tableExists('{{%b2b_order_company}}')) {
            $this->createTable('{{%b2b_order_company}}', [
                'orderId' => $this->integer()->notNull(),
                'companyId' => $this->integer()->notNull(),
                'isInvoice' => $this->boolean()->notNull()->defaultValue(false),
                'dateCreated' => $this->dateTime()->notNull(),
                'dateUpdated' => $this->dateTime()->notNull(),
                'uid' => $this->uid(),
                'PRIMARY KEY([[orderId]])',
            ]);

            $this->createIndex(null, '{{%b2b_order_company}}', ['companyId']);
            $this->addForeignKey(null, '{{%b2b_order_company}}', ['orderId'], '{{%commerce_orders}}', ['id'], 'CASCADE');
            $this->addForeignKey(null, '{{%b2b_order_company}}', ['companyId'], '{{%b2b_companies}}', ['id'], 'CASCADE');
        }

        // Column parity for installs whose table predates the isInvoice snapshot. No backfill here:
        // a fresh install has no link rows, and an existing install reaches this column through the
        // m260709_120000 migration, which does backfill.
        if (!$this->db->columnExists('{{%b2b_order_company}}', 'isInvoice')) {
            $this->addColumn(
                '{{%b2b_order_company}}',
                'isInvoice',
                $this->boolean()->notNull()->defaultValue(false)->after('companyId')
            );
        }

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
