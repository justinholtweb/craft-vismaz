<?php

namespace justinholtweb\vismaz\services;

use Craft;
use craft\db\Query;
use justinholtweb\vismaz\db\Table;
use justinholtweb\vismaz\models\Document;
use yii\base\Component;
use yii\db\Expression;

/**
 * Where an order stands with Visma, as one word — for the Orders index column and the "Visma
 * status" condition rule.
 *
 * Five statuses, in precedence order. {@see orderStatuses()} works them out in PHP for a page of
 * rows; {@see condition()} builds exactly the same sets in SQL for an order query. The test suite
 * holds the two to partitioning the same orders identically — change one, change both.
 *
 * - `failed` — one of its documents (invoice, credit note, or the voucher it was swept into)
 *   failed, or one of its payments could not be registered against the invoice.
 * - `mismatched` — Visma booked one of its documents at a different total from the one sent.
 * - `synced` — its documents are in Visma and agree.
 * - `pending` — a document is claimed and not yet sent.
 * - `none` — Vismaz has never sent it.
 *
 * A document belongs to an order through its join row (`vismaz_documentorders`, written once the
 * document is in Visma — the only link a voucher has) or through its idempotency key: `order:<id>`
 * for an invoice and `refund:<id>:<hash>` for a credit note, which exist from the moment the row
 * is claimed. That is what lets a *failed* invoice, which never got a join row, count for its order.
 */
class OrderStatus extends Component
{
    public const SYNCED = 'synced';
    public const MISMATCHED = 'mismatched';
    public const FAILED = 'failed';
    public const PENDING = 'pending';
    public const NONE = 'none';

    /** @var array<int, string> order id => status, for the life of the request */
    private array $memo = [];

    /**
     * @return array<string, string> status => label
     */
    public static function options(): array
    {
        return [
            self::SYNCED => Craft::t('vismaz', 'Synced'),
            self::MISMATCHED => Craft::t('vismaz', 'Mismatched'),
            self::FAILED => Craft::t('vismaz', 'Failed'),
            self::PENDING => Craft::t('vismaz', 'Pending'),
            self::NONE => Craft::t('vismaz', 'Not sent'),
        ];
    }

    /**
     * Statuses for any number of orders, in four queries — this runs for a whole index page.
     *
     * @param int[] $orderIds
     * @return array<int, string> order id => status
     */
    public function orderStatuses(array $orderIds): array
    {
        $orderIds = array_values(array_unique(array_filter(array_map('intval', $orderIds))));

        if ($orderIds === []) {
            return [];
        }

        /** @var array<int, array<string, true>> $seen order id => document statuses */
        $seen = [];

        $joined = (new Query())
            ->select(['do.orderId', 'd.status'])
            ->from(['do' => Table::DOCUMENTORDERS])
            ->innerJoin(['d' => Table::DOCUMENTS], '[[d.id]] = [[do.documentId]]')
            ->where(['do.orderId' => $orderIds])
            ->all();

        foreach ($joined as $row) {
            $seen[(int)$row['orderId']][(string)$row['status']] = true;
        }

        // By idempotency key: the invoice keys are an indexed IN list, the credit-note keys one
        // indexed prefix each.
        $refunds = ['or'];

        foreach ($orderIds as $id) {
            $refunds[] = ['like', 'sourceKey', 'refund:' . $id . ':%', false];
        }

        $keyed = (new Query())
            ->select(['sourceKey', 'status'])
            ->from(Table::DOCUMENTS)
            ->where([
                'or',
                ['type' => Document::TYPE_INVOICE, 'sourceKey' => array_map(static fn(int $id) => 'order:' . $id, $orderIds)],
                ['and', ['type' => Document::TYPE_CREDITNOTE], $refunds],
            ])
            ->all();

        foreach ($keyed as $row) {
            if (preg_match('/^(?:order|refund):(\d+)/', (string)$row['sourceKey'], $m)) {
                $seen[(int)$m[1]][(string)$row['status']] = true;
            }
        }

        $failedPayments = array_flip(array_map('intval', (new Query())
            ->select(['orderId'])
            ->distinct()
            ->from(Table::PAYMENTS)
            ->where(['orderId' => $orderIds, 'status' => Payments::STATUS_FAILED])
            ->column()));

        $statuses = [];

        foreach ($orderIds as $id) {
            $s = $seen[$id] ?? [];

            $statuses[$id] = match (true) {
                isset($s[Sync::STATUS_FAILED]) || isset($failedPayments[$id]) => self::FAILED,
                isset($s[Sync::STATUS_MISMATCHED]) => self::MISMATCHED,
                isset($s[Sync::STATUS_SENT]) => self::SYNCED,
                isset($s[Sync::STATUS_PENDING]) => self::PENDING,
                default => self::NONE,
            };
        }

        return $statuses;
    }

    /**
     * One order's status, from a per-request memo that {@see prefetch()} fills for a whole index
     * page at once.
     */
    public function orderStatus(int $orderId): string
    {
        if (!isset($this->memo[$orderId])) {
            $this->prefetch([$orderId]);
        }

        return $this->memo[$orderId] ?? self::NONE;
    }

    /**
     * @param int[] $orderIds
     */
    public function prefetch(array $orderIds): void
    {
        $missing = array_diff(array_map('intval', $orderIds), array_keys($this->memo));

        if ($missing !== []) {
            $this->memo = $this->orderStatuses($missing) + $this->memo;
        }
    }

    /**
     * Forget the memo — after a push changes what it would say.
     */
    public function reset(): void
    {
        $this->memo = [];
    }

    /**
     * A WHERE condition on an order id column that is true for orders in exactly this status.
     *
     * Each status excludes every status above it, so the five sets partition the orders: an order
     * is in one of them and only one.
     *
     * @return array<int|string, mixed>
     */
    public function condition(string $status, string $idColumn = 'elements.id'): array
    {
        $failed = ['in', $idColumn, $this->failedOrderIds()];
        $notFailed = ['not in', $idColumn, $this->failedOrderIds()];
        $mismatched = ['in', $idColumn, $this->documentOrderIds([Sync::STATUS_MISMATCHED])];
        $sent = ['in', $idColumn, $this->documentOrderIds([Sync::STATUS_SENT])];
        $pending = ['in', $idColumn, $this->documentOrderIds([Sync::STATUS_PENDING])];

        return match ($status) {
            self::FAILED => $failed,
            self::MISMATCHED => ['and', $mismatched, $notFailed],
            self::SYNCED => ['and', $sent, ['not', $mismatched], $notFailed],
            self::PENDING => ['and', $pending, ['not', $sent], ['not', $mismatched], $notFailed],
            self::NONE => ['and', ['not in', $idColumn, $this->documentOrderIds(null)], $notFailed],
            // An unknown status matches nothing, rather than everything.
            default => ['and', new Expression('0=1')],
        };
    }

    /**
     * Order ids with a document in one of these statuses (any status when null), optionally of
     * these types: through the join row, or through the invoice/credit-note key.
     *
     * @param string[]|null $statuses
     * @param string[]|null $types
     */
    public function documentOrderIds(?array $statuses, ?array $types = null): Query
    {
        $joined = (new Query())
            ->select(['orderId' => 'do.orderId'])
            ->from(['do' => Table::DOCUMENTORDERS])
            ->innerJoin(['d' => Table::DOCUMENTS], '[[d.id]] = [[do.documentId]]');

        $keyTypes = array_values(array_intersect(
            $types ?? [Document::TYPE_INVOICE, Document::TYPE_CREDITNOTE],
            [Document::TYPE_INVOICE, Document::TYPE_CREDITNOTE],
        ));

        $expression = self::keyOrderIdExpression('k.sourceKey');
        $keyed = (new Query())
            ->select(['orderId' => $expression])
            ->from(['k' => Table::DOCUMENTS])
            ->where(['k.type' => $keyTypes === [] ? '__none__' : $keyTypes])
            ->andWhere($expression->expression . ' IS NOT NULL');

        if ($statuses !== null) {
            $joined->where(['d.status' => $statuses]);
            $keyed->andWhere(['k.status' => $statuses]);
        }

        if ($types !== null) {
            $joined->andWhere(['d.type' => $types]);
        }

        return $joined->union($keyed);
    }

    /**
     * Order ids with a failed document or a failed payment.
     */
    private function failedOrderIds(): Query
    {
        $payments = (new Query())
            ->select(['orderId' => 'p.orderId'])
            ->from(['p' => Table::PAYMENTS])
            ->where(['p.status' => Payments::STATUS_FAILED]);

        return $this->documentOrderIds([Sync::STATUS_FAILED])->union($payments);
    }

    /**
     * The order id inside an `order:<id>` or `refund:<id>:<hash>` key, as SQL: the second
     * colon-separated part, as an integer. Craft runs on MySQL or PostgreSQL, which spell that
     * differently. Null (PostgreSQL) for a key without one, so a malformed key can neither match
     * an order nor turn a `NOT IN` against the whole list into "nothing".
     */
    public static function keyOrderIdExpression(string $column): Expression
    {
        $quoted = Craft::$app->getDb()->quoteColumnName($column);

        return Craft::$app->getDb()->getIsMysql()
            ? new Expression("CAST(SUBSTRING_INDEX(SUBSTRING_INDEX($quoted, ':', 2), ':', -1) AS UNSIGNED)")
            : new Expression("CAST(NULLIF(SPLIT_PART($quoted, ':', 2), '') AS INTEGER)");
    }
}
