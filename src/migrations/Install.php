<?php

namespace justinholtweb\vismaz\migrations;

use craft\db\Migration;
use craft\db\Table as CraftTable;
use justinholtweb\vismaz\db\Table;

/**
 * Vismaz install migration.
 */
class Install extends Migration
{
    public function safeUp(): bool
    {
        $this->createTables();
        $this->createIndexes();
        $this->addForeignKeys();

        return true;
    }

    public function safeDown(): bool
    {
        $this->dropTableIfExists(Table::LOG);
        $this->dropTableIfExists(Table::TOKENS);
        $this->dropTableIfExists(Table::ENTITIES);
        $this->dropTableIfExists(Table::DOCUMENTORDERS);
        $this->dropTableIfExists(Table::DOCUMENTS);

        return true;
    }

    private function createTables(): void
    {
        $this->createTable(Table::DOCUMENTS, [
            'id' => $this->primaryKey(),
            'type' => $this->string(16)->notNull(),
            // The idempotency key. `order:12`, `refund:12:a1b2…`, `voucher:2026-08-20:2026-08-20`.
            // The unique index on (type, sourceKey) below is what actually stops a double post —
            // the queue can retry, the merchant can mash the button and the console can run, all
            // at once, and only one document exists at the end of it.
            'sourceKey' => $this->string(255)->notNull(),
            'status' => $this->string(16)->notNull()->defaultValue('pending'),
            'storeId' => $this->integer(),
            // GUID Visma assigns. Null until the push succeeds.
            'vismaId' => $this->string(64),
            // Human-facing number Visma assigns — the invoice or verification number the
            // merchant's accountant will actually quote back.
            'vismaNumber' => $this->string(64),
            'documentDate' => $this->dateTime(),
            'currency' => $this->string(8),
            'netTotal' => $this->decimal(14, 4),
            'vatTotal' => $this->decimal(14, 4),
            'grossTotal' => $this->decimal(14, 4),
            'taxKind' => $this->string(24),
            'orderCount' => $this->integer()->notNull()->defaultValue(0),
            'payload' => $this->mediumText(),
            'response' => $this->mediumText(),
            'attempts' => $this->integer()->notNull()->defaultValue(0),
            'lastError' => $this->text(),
            'dateSent' => $this->dateTime(),
            'dateCreated' => $this->dateTime()->notNull(),
            'dateUpdated' => $this->dateTime()->notNull(),
            'uid' => $this->uid(),
        ]);

        $this->createTable(Table::DOCUMENTORDERS, [
            'id' => $this->primaryKey(),
            'documentId' => $this->integer()->notNull(),
            'orderId' => $this->integer()->notNull(),
            'dateCreated' => $this->dateTime()->notNull(),
            'dateUpdated' => $this->dateTime()->notNull(),
            'uid' => $this->uid(),
        ]);

        $this->createTable(Table::ENTITIES, [
            'id' => $this->primaryKey(),
            // customer | article | account | vatcode | unit | term
            'entityType' => $this->string(24)->notNull(),
            // Local identity: a user id, a variant SKU, a BAS account number.
            'localId' => $this->string(255)->notNull(),
            'vismaId' => $this->string(64),
            'vismaNumber' => $this->string(64),
            // Hash of what was last pushed, so an unchanged entity is not re-pushed every order.
            'contentHash' => $this->string(40),
            'dateSynced' => $this->dateTime(),
            'dateCreated' => $this->dateTime()->notNull(),
            'dateUpdated' => $this->dateTime()->notNull(),
            'uid' => $this->uid(),
        ]);

        $this->createTable(Table::TOKENS, [
            'id' => $this->primaryKey(),
            // One row per environment: a merchant testing in sandbox must not have their
            // production connection quietly overwritten when they switch back.
            'environment' => $this->string(16)->notNull(),
            'accessToken' => $this->text(),
            'refreshToken' => $this->text(),
            'expiresAt' => $this->dateTime(),
            'scope' => $this->string(255),
            'companyId' => $this->string(64),
            'companyName' => $this->string(255),
            'organisationNumber' => $this->string(32),
            'connectedBy' => $this->integer(),
            'dateCreated' => $this->dateTime()->notNull(),
            'dateUpdated' => $this->dateTime()->notNull(),
            'uid' => $this->uid(),
        ]);

        $this->createTable(Table::LOG, [
            'id' => $this->primaryKey(),
            'action' => $this->string(48)->notNull(),
            'level' => $this->string(16)->notNull()->defaultValue('info'),
            'method' => $this->string(8),
            'url' => $this->text(),
            'statusCode' => $this->integer(),
            'durationMs' => $this->integer(),
            'documentId' => $this->integer(),
            'orderId' => $this->integer(),
            'message' => $this->text(),
            'requestBody' => $this->mediumText(),
            'responseBody' => $this->mediumText(),
            'dateCreated' => $this->dateTime()->notNull(),
            'dateUpdated' => $this->dateTime()->notNull(),
            'uid' => $this->uid(),
        ]);
    }

    private function createIndexes(): void
    {
        // The whole idempotency guarantee, in one line.
        $this->createIndex(null, Table::DOCUMENTS, ['type', 'sourceKey'], true);
        $this->createIndex(null, Table::DOCUMENTS, ['status']);
        $this->createIndex(null, Table::DOCUMENTS, ['documentDate']);
        $this->createIndex(null, Table::DOCUMENTS, ['vismaId']);

        // An order belongs to at most one document. Without this, a re-run of a summary voucher
        // with shifted period boundaries would book the same revenue twice.
        $this->createIndex(null, Table::DOCUMENTORDERS, ['orderId'], true);
        $this->createIndex(null, Table::DOCUMENTORDERS, ['documentId']);

        $this->createIndex(null, Table::ENTITIES, ['entityType', 'localId'], true);
        $this->createIndex(null, Table::TOKENS, ['environment'], true);
        $this->createIndex(null, Table::LOG, ['dateCreated']);
        $this->createIndex(null, Table::LOG, ['documentId']);
        $this->createIndex(null, Table::LOG, ['orderId']);
    }

    private function addForeignKeys(): void
    {
        $this->addForeignKey(null, Table::DOCUMENTORDERS, ['documentId'], Table::DOCUMENTS, ['id'], 'CASCADE', null);

        // Orders are elements. A deleted order should take its join row with it, but must not
        // take the document: the revenue was still earned and is still in Visma.
        $this->addForeignKey(null, Table::DOCUMENTORDERS, ['orderId'], CraftTable::ELEMENTS, ['id'], 'CASCADE', null);
        $this->addForeignKey(null, Table::LOG, ['documentId'], Table::DOCUMENTS, ['id'], 'SET NULL', null);
        $this->addForeignKey(null, Table::TOKENS, ['connectedBy'], CraftTable::USERS, ['id'], 'SET NULL', null);
    }
}
