<?php

namespace justinholtweb\vismaz\records;

use craft\db\ActiveRecord;
use justinholtweb\vismaz\db\Table;

/**
 * @see Table::DOCUMENTS
 *
 * @property int $id
 * @property string $type
 * @property string $sourceKey
 * @property string $status
 * @property int|null $storeId
 * @property string|null $vismaId
 * @property string|null $vismaNumber
 * @property string|null $documentDate
 * @property string|null $currency
 * @property float|string|null $netTotal
 * @property float|string|null $vatTotal
 * @property float|string|null $grossTotal
 * @property string|null $taxKind
 * @property int $orderCount
 * @property string|null $payload
 * @property string|null $response
 * @property int $attempts
 * @property string|null $lastError
 * @property string|null $dateSent
 * @property string $dateCreated
 * @property string $dateUpdated
 */
class DocumentRecord extends ActiveRecord
{
    public static function tableName(): string
    {
        return Table::DOCUMENTS;
    }
}
