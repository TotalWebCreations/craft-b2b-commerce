<?php

namespace totalwebcreations\b2bcommerce\migrations;

use craft\db\Migration;
use craft\db\Table;

class m260714_000400_add_departments extends Migration
{
    public function safeUp(): bool
    {
        if (!$this->db->tableExists('{{%b2b_departments}}')) {
            $this->createTable('{{%b2b_departments}}', [
                'id' => $this->primaryKey(),
                'companyId' => $this->integer()->notNull(),
                'name' => $this->string()->notNull(),
                'budgetAmount' => $this->decimal(14, 4),
                'budgetPeriod' => $this->string()->notNull()->defaultValue('monthly'),
                'approverUserId' => $this->integer(),
                'dateCreated' => $this->dateTime()->notNull(),
                'dateUpdated' => $this->dateTime()->notNull(),
                'uid' => $this->uid(),
            ]);

            $this->createIndex(null, '{{%b2b_departments}}', ['companyId']);
            $this->addForeignKey(null, '{{%b2b_departments}}', ['companyId'], '{{%b2b_companies}}', ['id'], 'CASCADE');
            $this->addForeignKey(null, '{{%b2b_departments}}', ['approverUserId'], Table::USERS, ['id'], 'SET NULL');
        }

        if (!$this->db->columnExists('{{%b2b_company_users}}', 'departmentId')) {
            $this->addColumn('{{%b2b_company_users}}', 'departmentId', $this->integer()->after('role'));
            $this->createIndex(null, '{{%b2b_company_users}}', ['departmentId']);
            $this->addForeignKey(null, '{{%b2b_company_users}}', ['departmentId'], '{{%b2b_departments}}', ['id'], 'SET NULL');
        }

        return true;
    }
}
