<?php

namespace justinholtweb\vismaz\records;

use craft\db\ActiveRecord;
use justinholtweb\vismaz\db\Table;

/**
 * @see Table::PAYMENTS
 *
 * @property int $id
 * @property int $transactionId
 * @property int $orderId
 * @property int|null $documentId
 * @property string|null $gatewayHandle
 * @property string $status
 * @property float|string|null $amount
 * @property string|null $currency
 * @property int|null $paymentType
 * @property string|null $paymentDate
 * @property string|null $bankAccountId
 * @property string|null $reference
 * @property string|null $vismaReference
 * @property string|null $payload
 * @property string|null $response
 * @property int $attempts
 * @property string|null $lastError
 * @property string|null $dateSent
 * @property string $dateCreated
 * @property string $dateUpdated
 */
class PaymentRecord extends ActiveRecord
{
    public static function tableName(): string
    {
        return Table::PAYMENTS;
    }
}
