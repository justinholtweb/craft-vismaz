<?php

namespace justinholtweb\vismaz\records;

use craft\db\ActiveRecord;
use justinholtweb\vismaz\db\Table;

/**
 * @see Table::ENTITIES
 */
class EntityRecord extends ActiveRecord
{
    public static function tableName(): string
    {
        return Table::ENTITIES;
    }
}
