<?php

namespace totalwebcreations\b2bcommerce\migrations;

use craft\db\Migration;

/**
 * Phase 15 — buyer purchase-order / reference number.
 *
 * Adds b2b_order_references (one PO per order, keyed by orderId) and the per-company
 * requirePoNumber toggle that arms the completion backstop.
 */
class m260714_000100_add_po_number_support extends Migration
{
    public function safeUp(): bool
    {
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

        if (!$this->db->columnExists('{{%b2b_companies}}', 'requirePoNumber')) {
            $this->addColumn(
                '{{%b2b_companies}}',
                'requirePoNumber',
                $this->boolean()->notNull()->defaultValue(false)->after('customerGroupId')
            );
        }

        return true;
    }
}
