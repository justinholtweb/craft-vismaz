<?php

namespace justinholtweb\vismaz\records;

use craft\db\ActiveRecord;
use justinholtweb\vismaz\db\Table;

/**
 * @see Table::TOKENS
 *
 * @property int $id
 * @property string $environment
 * @property string|null $accessToken
 * @property string|null $refreshToken
 * @property string|null $expiresAt
 * @property string|null $scope
 * @property string|null $companyId
 * @property string|null $companyName
 * @property string|null $organisationNumber
 * @property int|null $connectedBy
 * @property string $dateCreated
 * @property string $dateUpdated
 */
class TokenRecord extends ActiveRecord
{
    public static function tableName(): string
    {
        return Table::TOKENS;
    }
}
