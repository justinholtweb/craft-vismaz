<?php

namespace justinholtweb\vismaz\records;

use craft\db\ActiveRecord;
use justinholtweb\vismaz\db\Table;

/**
 * @see Table::DOCUMENTS
 */
class DocumentRecord extends ActiveRecord
{
    public static function tableName(): string
    {
        return Table::DOCUMENTS;
    }
}
