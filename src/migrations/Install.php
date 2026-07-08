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
                'customerGroupId' => $this->integer(),
                'requirePoNumber' => $this->boolean()->notNull()->defaultValue(false),
                'dateCreated' => $this->dateTime()->notNull(),
                'dateUpdated' => $this->dateTime()->notNull(),
                'uid' => $this->uid(),
                'PRIMARY KEY([[id]])',
            ]);

            $this->createIndex(null, '{{%b2b_companies}}', ['status']);
            $this->addForeignKey(null, '{{%b2b_companies}}', ['id'], Table::ELEMENTS, ['id'], 'CASCADE');
            $this->addForeignKey(null, '{{%b2b_companies}}', ['customerGroupId'], Table::USERGROUPS, ['id'], 'SET NULL');
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

        if (!$this->db->tableExists('{{%b2b_order_references}}')) {
            $this->createTable('{{%b2b_order_references}}', [
                'orderId' => $this->integer()->notNull(),
                'poNumber' => $this->string(),
                'dateCreated' => $this->dateTime()->notNull(),
                'dateUpdated' => $this->dateTime()->notNull(),
                'uid' => $this->uid(),
                'PRIMARY KEY([[orderId]])',
            ]);

            $this->addForeignKey(null, '{{%b2b_order_references}}', ['orderId'], '{{%commerce_orders}}', ['id'], 'CASCADE');
        }

        if (!$this->db->tableExists('{{%b2b_quotes}}')) {
            // Element-backed: the primary key id IS the element id, while orderId — the business key
            // every enforcement guard reads — stays a NOT NULL unique column.
            $this->createTable('{{%b2b_quotes}}', [
                'id' => $this->integer()->notNull(),
                'orderId' => $this->integer()->notNull(),
                'companyId' => $this->integer()->notNull(),
                'status' => $this->string()->notNull()->defaultValue('requested'),
                'origin' => $this->string()->notNull()->defaultValue('customer'),
                'validUntil' => $this->dateTime(),
                'notes' => $this->text(),
                'declineReason' => $this->text(),
                'requestedById' => $this->integer(),
                'acceptToken' => $this->string()->notNull(),
                'dateCreated' => $this->dateTime()->notNull(),
                'dateUpdated' => $this->dateTime()->notNull(),
                'uid' => $this->uid(),
                'PRIMARY KEY([[id]])',
            ]);

            $this->createIndex(null, '{{%b2b_quotes}}', ['orderId'], true);
            $this->createIndex(null, '{{%b2b_quotes}}', ['companyId']);
            $this->createIndex(null, '{{%b2b_quotes}}', ['acceptToken'], true);
            $this->addForeignKey(null, '{{%b2b_quotes}}', ['id'], Table::ELEMENTS, ['id'], 'CASCADE');
            $this->addForeignKey(null, '{{%b2b_quotes}}', ['orderId'], '{{%commerce_orders}}', ['id'], 'CASCADE');
            $this->addForeignKey(null, '{{%b2b_quotes}}', ['companyId'], '{{%b2b_companies}}', ['id'], 'CASCADE');
            $this->addForeignKey(null, '{{%b2b_quotes}}', ['requestedById'], Table::USERS, ['id'], 'SET NULL');
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

        // Column parity for installs whose companies table predates the customer-group link. An
        // existing install reaches this column through the m260711_000000 migration; this guard
        // keeps a fresh Install::safeUp() consistent when the createTable branch above was skipped.
        if (!$this->db->columnExists('{{%b2b_companies}}', 'customerGroupId')) {
            $this->addColumn(
                '{{%b2b_companies}}',
                'customerGroupId',
                $this->integer()->after('approvalThreshold')
            );

            $this->addForeignKey(null, '{{%b2b_companies}}', ['customerGroupId'], Table::USERGROUPS, ['id'], 'SET NULL');
        }

        // Column parity for installs whose quotes table predates the origin marker. An existing
        // install reaches this column through the m260714_000200 migration; this guard keeps a
        // fresh Install::safeUp() consistent when the createTable branch above was skipped.
        if (!$this->db->columnExists('{{%b2b_quotes}}', 'origin')) {
            $this->addColumn(
                '{{%b2b_quotes}}',
                'origin',
                $this->string()->notNull()->defaultValue('customer')->after('status')
            );
        }

        // Column parity for installs whose companies table predates the PO-required toggle. An
        // existing install reaches this column through the m260714_000100 migration; this guard
        // keeps a fresh Install::safeUp() consistent when the createTable branch above was skipped.
        if (!$this->db->columnExists('{{%b2b_companies}}', 'requirePoNumber')) {
            $this->addColumn(
                '{{%b2b_companies}}',
                'requirePoNumber',
                $this->boolean()->notNull()->defaultValue(false)->after('customerGroupId')
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

        if (!$this->db->tableExists('{{%b2b_approvals}}')) {
            // Element-backed: the primary key id IS the element id, while orderId — the business key
            // every enforcement guard reads — stays a NOT NULL unique column.
            $this->createTable('{{%b2b_approvals}}', [
                'id' => $this->integer()->notNull(),
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
                'PRIMARY KEY([[id]])',
            ]);

            $this->createIndex(null, '{{%b2b_approvals}}', ['orderId'], true);
            $this->createIndex(null, '{{%b2b_approvals}}', ['companyId']);
            $this->addForeignKey(null, '{{%b2b_approvals}}', ['id'], Table::ELEMENTS, ['id'], 'CASCADE');
            $this->addForeignKey(null, '{{%b2b_approvals}}', ['orderId'], '{{%commerce_orders}}', ['id'], 'CASCADE');
            $this->addForeignKey(null, '{{%b2b_approvals}}', ['companyId'], '{{%b2b_companies}}', ['id'], 'CASCADE');
            $this->addForeignKey(null, '{{%b2b_approvals}}', ['requestedById'], Table::USERS, ['id'], 'SET NULL');
            $this->addForeignKey(null, '{{%b2b_approvals}}', ['resolvedById'], Table::USERS, ['id'], 'SET NULL');
        }

        if (!$this->db->tableExists('{{%b2b_member_budgets}}')) {
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
        }

        return true;
    }
}
