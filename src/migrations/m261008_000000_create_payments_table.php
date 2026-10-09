<?php

namespace justinholtweb\vismaz\migrations;

use craft\db\Migration;
use justinholtweb\vismaz\db\Table;

/**
 * 5.1.0: invoice payments, one row per Commerce transaction registered against a Visma invoice.
 */
class m261008_000000_create_payments_table extends Migration
{
    public function safeUp(): bool
    {
        if ($this->db->tableExists(Table::PAYMENTS)) {
            return true;
        }

        Install::createPaymentsTable($this);

        return true;
    }

    public function safeDown(): bool
    {
        $this->dropTableIfExists(Table::PAYMENTS);

        return true;
    }
}
