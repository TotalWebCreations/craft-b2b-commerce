<?php

namespace totalwebcreations\b2bcommerce\migrations;

use craft\db\Migration;
use craft\db\Query;
use craft\db\Table;
use craft\helpers\Db;
use craft\helpers\StringHelper;
use totalwebcreations\b2bcommerce\elements\Quote;

/**
 * Restructures b2b_quotes so it is backed by a Craft element: the primary key becomes the element id
 * (FK to elements) and the former primary key orderId becomes a NOT NULL unique FK column. Every
 * enforcement query keeps reading orderId, so the business key is unchanged.
 *
 * The table is rebuilt rather than altered in place because MySQL cannot cleanly swap a foreign-keyed
 * primary key. Existing rows are preserved: each is given a freshly created element (and an
 * elements_sites row on the primary site, since quotes are non-localized like companies) and
 * repointed. On the pre-live dev/QA sites this is either a no-op (no rows) or a lossless repoint.
 */
class m260712_000000_convert_quotes_to_elements extends Migration
{
    public function safeUp(): bool
    {
        // Already element-backed: nothing to do.
        if ($this->db->columnExists('{{%b2b_quotes}}', 'id')) {
            return true;
        }

        $rows = [];

        if ($this->db->tableExists('{{%b2b_quotes}}')) {
            $rows = (new Query())->from('{{%b2b_quotes}}')->all($this->db);
            $this->dropTableIfExists('{{%b2b_quotes}}');
        }

        $this->createTable('{{%b2b_quotes}}', [
            'id' => $this->integer()->notNull(),
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
            'PRIMARY KEY([[id]])',
        ]);

        $this->createIndex(null, '{{%b2b_quotes}}', ['orderId'], true);
        $this->createIndex(null, '{{%b2b_quotes}}', ['companyId']);
        $this->createIndex(null, '{{%b2b_quotes}}', ['acceptToken'], true);
        $this->addForeignKey(null, '{{%b2b_quotes}}', ['id'], Table::ELEMENTS, ['id'], 'CASCADE');
        $this->addForeignKey(null, '{{%b2b_quotes}}', ['orderId'], '{{%commerce_orders}}', ['id'], 'CASCADE');
        $this->addForeignKey(null, '{{%b2b_quotes}}', ['companyId'], '{{%b2b_companies}}', ['id'], 'CASCADE');
        $this->addForeignKey(null, '{{%b2b_quotes}}', ['requestedById'], Table::USERS, ['id'], 'SET NULL');

        $this->backfillElements($rows);

        return true;
    }

    /**
     * Creates an element (and primary-site row) for each preserved quote row and reinserts it keyed
     * on the new element id.
     *
     * @param array<int, array<string, mixed>> $rows
     */
    private function backfillElements(array $rows): void
    {
        if ($rows === []) {
            return;
        }

        $primarySiteId = \Craft::$app->getSites()->getPrimarySite()->id;

        foreach ($rows as $row) {
            $now = Db::prepareDateForDb(new \DateTime());

            $this->insert(Table::ELEMENTS, [
                'type' => Quote::class,
                'enabled' => true,
                'archived' => false,
                'dateCreated' => $row['dateCreated'] ?? $now,
                'dateUpdated' => $row['dateUpdated'] ?? $now,
                'uid' => StringHelper::UUID(),
            ]);

            $elementId = (int) $this->db->getLastInsertID();

            $this->insert(Table::ELEMENTS_SITES, [
                'elementId' => $elementId,
                'siteId' => $primarySiteId,
                'enabled' => true,
                'dateCreated' => $now,
                'dateUpdated' => $now,
                'uid' => StringHelper::UUID(),
            ]);

            $this->insert('{{%b2b_quotes}}', [
                'id' => $elementId,
                'orderId' => $row['orderId'],
                'companyId' => $row['companyId'],
                'status' => $row['status'],
                'validUntil' => $row['validUntil'] ?? null,
                'notes' => $row['notes'] ?? null,
                'declineReason' => $row['declineReason'] ?? null,
                'requestedById' => $row['requestedById'] ?? null,
                'acceptToken' => $row['acceptToken'],
                'dateCreated' => $row['dateCreated'] ?? $now,
                'dateUpdated' => $row['dateUpdated'] ?? $now,
                'uid' => $row['uid'] ?? StringHelper::UUID(),
            ]);
        }
    }
}
