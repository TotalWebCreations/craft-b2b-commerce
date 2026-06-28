<?php

namespace totalwebcreations\b2bcommerce\migrations;

use craft\commerce\db\Table as CommerceTable;
use craft\db\Migration;
use craft\db\Query;
use totalwebcreations\b2bcommerce\gateways\InvoiceGateway;

/**
 * Snapshots each order-company link's invoice status on the link row.
 *
 * Commerce's Gateways::archiveGatewayById() nulls gatewayId on EVERY order that referenced a
 * gateway when it is archived, so a gatewayId-keyed balance loses archived invoice orders entirely.
 * Recording isInvoice at link time makes the balance immune to that: the receivable is remembered
 * on our own row rather than derived from the live, mutable gatewayId.
 */
class m260709_120000_add_is_invoice_to_order_company extends Migration
{
    public function safeUp(): bool
    {
        if (!$this->db->columnExists('{{%b2b_order_company}}', 'isInvoice')) {
            $this->addColumn(
                '{{%b2b_order_company}}',
                'isInvoice',
                $this->boolean()->notNull()->defaultValue(false)->after('companyId')
            );
        }

        // Backfill existing rows: an order still pointing at an invoice gateway today was placed on
        // account, so its link row is an invoice receivable. (Only this migration backfills --
        // Install.php needs none because a fresh install has no link rows yet.)
        $invoiceGatewayIds = $this->invoiceGatewayIds();

        if ($invoiceGatewayIds === []) {
            return true;
        }

        $invoiceOrderIds = (new Query())
            ->select('id')
            ->from(CommerceTable::ORDERS)
            ->where(['gatewayId' => $invoiceGatewayIds])
            ->column($this->db);

        if ($invoiceOrderIds === []) {
            return true;
        }

        $this->update('{{%b2b_order_company}}', ['isInvoice' => true], ['orderId' => $invoiceOrderIds]);

        return true;
    }

    /**
     * @return array<int, int>
     */
    private function invoiceGatewayIds(): array
    {
        return (new Query())
            ->select('id')
            ->from(CommerceTable::GATEWAYS)
            ->where(['type' => InvoiceGateway::class])
            ->column($this->db);
    }
}
