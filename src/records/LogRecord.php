<?php

namespace justinholtweb\vismaz\records;

use craft\db\ActiveRecord;
use justinholtweb\vismaz\db\Table;

/**
 * @see Table::LOG
 *
 * @property int $id
 * @property string $action
 * @property string $level
 * @property string|null $method
 * @property string|null $url
 * @property int|null $statusCode
 * @property int|null $durationMs
 * @property int|null $documentId
 * @property int|null $orderId
 * @property string|null $message
 * @property string|null $requestBody
 * @property string|null $responseBody
 * @property string $dateCreated
 * @property string $dateUpdated
 */
class LogRecord extends ActiveRecord
{
    public static function tableName(): string
    {
        return Table::LOG;
    }
}
