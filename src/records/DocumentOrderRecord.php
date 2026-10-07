<?php

namespace justinholtweb\vismaz\records;

use craft\db\ActiveRecord;
use justinholtweb\vismaz\db\Table;

/**
 * @see Table::DOCUMENTORDERS
 *
 * @property int $id
 * @property int $documentId
 * @property int $orderId
 * @property string $dateCreated
 * @property string $dateUpdated
 */
class DocumentOrderRecord extends ActiveRecord
{
    public static function tableName(): string
    {
        return Table::DOCUMENTORDERS;
    }
}
