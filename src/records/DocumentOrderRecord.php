<?php

namespace justinholtweb\vismaz\records;

use craft\db\ActiveRecord;
use justinholtweb\vismaz\db\Table;

/**
 * @see Table::DOCUMENTORDERS
 */
class DocumentOrderRecord extends ActiveRecord
{
    public static function tableName(): string
    {
        return Table::DOCUMENTORDERS;
    }
}
