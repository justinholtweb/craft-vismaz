<?php

namespace justinholtweb\vismaz\services;

use Craft;
use craft\db\Query;
use craft\helpers\Db;
use DateTime;
use justinholtweb\vismaz\db\Table;
use justinholtweb\vismaz\Plugin;
use justinholtweb\vismaz\records\LogRecord;
use yii\base\Component;

/**
 * The connection log.
 *
 * Every request to Visma lands here. When a merchant's accountant says "this invoice is wrong",
 * the log is the only thing that can answer what was actually sent, and when.
 */
class Log extends Component
{
    public const LEVEL_INFO = 'info';
    public const LEVEL_WARNING = 'warning';
    public const LEVEL_ERROR = 'error';

    /**
     * Record an entry. Never throws: a logging failure must not take down the sync it is
     * describing.
     */
    public function write(string $action, array $attributes = []): ?int
    {
        try {
            $settings = Plugin::getInstance()->getSettings();

            $record = new LogRecord();
            $record->action = $action;
            $record->level = $attributes['level'] ?? self::LEVEL_INFO;
            $record->method = $attributes['method'] ?? null;
            $record->url = $attributes['url'] ?? null;
            $record->statusCode = $attributes['statusCode'] ?? null;
            $record->durationMs = $attributes['durationMs'] ?? null;
            $record->documentId = $attributes['documentId'] ?? null;
            $record->orderId = $attributes['orderId'] ?? null;
            $record->message = $attributes['message'] ?? null;

            if ($settings->logPayloads) {
                $record->requestBody = self::stringify($attributes['requestBody'] ?? null);
                $record->responseBody = self::stringify($attributes['responseBody'] ?? null);
            }

            $record->save(false);

            return (int)$record->id;
        } catch (\Throwable $e) {
            Craft::warning('Vismaz could not write a log entry: ' . $e->getMessage(), 'vismaz');

            return null;
        }
    }

    public function error(string $action, string $message, array $attributes = []): ?int
    {
        return $this->write($action, $attributes + ['level' => self::LEVEL_ERROR, 'message' => $message]);
    }

    public function warning(string $action, string $message, array $attributes = []): ?int
    {
        return $this->write($action, $attributes + ['level' => self::LEVEL_WARNING, 'message' => $message]);
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function find(array $criteria = [], int $limit = 100, int $offset = 0): array
    {
        $query = (new Query())
            ->from(Table::LOG)
            ->orderBy(['dateCreated' => SORT_DESC, 'id' => SORT_DESC])
            ->limit($limit)
            ->offset($offset);

        foreach (['level', 'action', 'documentId', 'orderId'] as $key) {
            if (!empty($criteria[$key])) {
                $query->andWhere([$key => $criteria[$key]]);
            }
        }

        return $query->all();
    }

    public function count(array $criteria = []): int
    {
        $query = (new Query())->from(Table::LOG);

        foreach (['level', 'action', 'documentId', 'orderId'] as $key) {
            if (!empty($criteria[$key])) {
                $query->andWhere([$key => $criteria[$key]]);
            }
        }

        return (int)$query->count();
    }

    public function get(int $id): ?array
    {
        return (new Query())->from(Table::LOG)->where(['id' => $id])->one() ?: null;
    }

    /**
     * Drop entries older than the retention setting. Returns the number removed.
     */
    public function prune(?int $days = null): int
    {
        $days ??= Plugin::getInstance()->getSettings()->logRetentionDays;

        if ($days <= 0) {
            return 0;
        }

        $cutoff = (new DateTime())->modify("-$days days");

        return (int)Craft::$app->getDb()->createCommand()
            ->delete(Table::LOG, ['<', 'dateCreated', Db::prepareDateForDb($cutoff)])
            ->execute();
    }

    public function clear(): int
    {
        return (int)Craft::$app->getDb()->createCommand()->delete(Table::LOG)->execute();
    }

    /**
     * Bodies arrive as arrays, strings or nothing. Secrets are stripped before they are stored —
     * a support screenshot of the log should not be a credential leak.
     */
    private static function stringify(mixed $body): ?string
    {
        if ($body === null || $body === '') {
            return null;
        }

        if (is_array($body)) {
            $body = self::redact($body);
            $body = json_encode($body, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        }

        $body = (string)$body;

        return mb_strlen($body) > 65000 ? mb_substr($body, 0, 65000) . "\n… truncated" : $body;
    }

    private static function redact(array $data): array
    {
        foreach ($data as $key => $value) {
            if (is_array($value)) {
                $data[$key] = self::redact($value);
                continue;
            }

            if (preg_match('/secret|refresh_token|access_token|password|authorization/i', (string)$key)) {
                $data[$key] = '[redacted]';
            }
        }

        return $data;
    }
}
