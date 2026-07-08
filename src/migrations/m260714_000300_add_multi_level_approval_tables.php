<?php

namespace totalwebcreations\b2bcommerce\migrations;

use craft\db\Migration;
use craft\db\Table;

/**
 * Adds the multi-level approval tables: b2b_approval_tiers (per-company bands defining which
 * approver levels are required at/above which order amount) and b2b_approval_steps (the per-approval
 * instance of the ladder, one row per required level). Both tables are guarded so a re-run is a
 * no-op, and Install.php carries the same definitions for a fresh install. Up-only.
 */
class m260714_000300_add_multi_level_approval_tables extends Migration
{
    public function safeUp(): bool
    {
        if (!$this->db->tableExists('{{%b2b_approval_tiers}}')) {
            $this->createTable('{{%b2b_approval_tiers}}', [
                'id' => $this->primaryKey(),
                'companyId' => $this->integer()->notNull(),
                'level' => $this->integer()->notNull(),
                'minAmount' => $this->decimal(14, 4)->notNull(),
                'approverRole' => $this->string()->notNull()->defaultValue('approver'),
                'departmentScoped' => $this->boolean()->notNull()->defaultValue(false),
                'dateCreated' => $this->dateTime()->notNull(),
                'dateUpdated' => $this->dateTime()->notNull(),
                'uid' => $this->uid(),
            ]);

            $this->createIndex(null, '{{%b2b_approval_tiers}}', ['companyId', 'level'], true);
            $this->addForeignKey(null, '{{%b2b_approval_tiers}}', ['companyId'], '{{%b2b_companies}}', ['id'], 'CASCADE');
        }

        if (!$this->db->tableExists('{{%b2b_approval_steps}}')) {
            $this->createTable('{{%b2b_approval_steps}}', [
                'id' => $this->primaryKey(),
                'approvalId' => $this->integer()->notNull(),
                'level' => $this->integer()->notNull(),
                'status' => $this->string()->notNull()->defaultValue('pending'),
                'resolvedById' => $this->integer(),
                'reason' => $this->text(),
                'dateResolved' => $this->dateTime(),
                'dateCreated' => $this->dateTime()->notNull(),
                'dateUpdated' => $this->dateTime()->notNull(),
                'uid' => $this->uid(),
            ]);

            $this->createIndex(null, '{{%b2b_approval_steps}}', ['approvalId', 'level'], true);
            $this->addForeignKey(null, '{{%b2b_approval_steps}}', ['approvalId'], '{{%b2b_approvals}}', ['id'], 'CASCADE');
            $this->addForeignKey(null, '{{%b2b_approval_steps}}', ['resolvedById'], Table::USERS, ['id'], 'SET NULL');
        }

        return true;
    }
}
