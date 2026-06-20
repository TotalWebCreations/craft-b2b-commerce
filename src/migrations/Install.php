<?php

namespace totalwebcreations\b2bcommerce\migrations;

use craft\db\Migration;
use craft\db\Table;

class Install extends Migration
{
    public function safeUp(): bool
    {
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

        $this->createTable('{{%b2b_company_users}}', [
            'id' => $this->primaryKey(),
            'companyId' => $this->integer()->notNull(),
            'userId' => $this->integer()->notNull(),
            'role' => $this->string()->notNull()->defaultValue('purchaser'),
            'dateCreated' => $this->dateTime()->notNull(),
            'dateUpdated' => $this->dateTime()->notNull(),
            'uid' => $this->uid(),
        ]);

        $this->createIndex(null, '{{%b2b_companies}}', ['status']);
        $this->createIndex(null, '{{%b2b_company_users}}', ['companyId', 'userId'], true);

        $this->addForeignKey(null, '{{%b2b_companies}}', ['id'], Table::ELEMENTS, ['id'], 'CASCADE');
        $this->addForeignKey(null, '{{%b2b_company_users}}', ['companyId'], '{{%b2b_companies}}', ['id'], 'CASCADE');
        $this->addForeignKey(null, '{{%b2b_company_users}}', ['userId'], Table::USERS, ['id'], 'CASCADE');

        return true;
    }
}
