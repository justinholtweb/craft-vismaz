<?php

namespace justinholtweb\vismaz\records;

use craft\db\ActiveRecord;
use justinholtweb\vismaz\db\Table;

/**
 * @see Table::ENTITIES
 *
 * @property int $id
 * @property string $entityType
 * @property string $localId
 * @property string|null $vismaId
 * @property string|null $vismaNumber
 * @property string|null $contentHash
 * @property string|null $dateSynced
 * @property string $dateCreated
 * @property string $dateUpdated
 */
class EntityRecord extends ActiveRecord
{
    public static function tableName(): string
    {
        return Table::ENTITIES;
    }
}
