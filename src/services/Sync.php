<?php

namespace justinholtweb\vismaz\services;

use Craft;
use craft\commerce\elements\Order;
use craft\db\Query;
use craft\helpers\Db;
use DateTime;
use DateTimeInterface;
use justinholtweb\vismaz\db\Table;
use justinholtweb\vismaz\models\Document;
use justinholtweb\vismaz\models\Settings;
use justinholtweb\vismaz\Plugin;
use justinholtweb\vismaz\records\DocumentOrderRecord;
use justinholtweb\vismaz\records\DocumentRecord;
use Throwable;
use yii\base\Component;
use yii\db\IntegrityException;

/**
 * Pushing documents to Visma, and remembering that it happened.
 *
 * **`record()` is the only place a document row is written.** The queue job, the CP button and
 * the console command all land there, so the idempotency decision, the attempt counter and the
 * sent/failed transition are made once and cannot disagree with each other.
 *
 * The row is claimed *before* the API call, not after. A crash mid-push then leaves a `pending`
 * row that a retry can find, rather than a silent gap that nothing knows to look for.
 */
class Sync extends Component
{
    public const STATUS_PENDING = 'pending';
    public const STATUS_SENT = 'sent';
    public const STATUS_FAILED = 'failed';
    public const STATUS_SKIPPED = 'skipped';

    /**
     * Sent, and Visma accepted it — but the total it came back with is not the total that was
     * sent. The document exists in the merchant's books either way, so this is not a failure to
     * retry; it is a discrepancy a human has to look at.
     */
    public const STATUS_MISMATCHED = 'mismatched';

    /** Tolerance when comparing our total to Visma's, in kronor. */
    private const RECONCILE_TOLERANCE = 0.01;

    /**
     * Push a document, or return the existing row if it has already been sent.
     *
     * @return array{status: string, document: DocumentRecord, message: ?string}
     */
    public function push(Document $document): array
    {
        $plugin = Plugin::getInstance();

        if ($document->type === Document::TYPE_VOUCHER && !$document->balances()) {
            $record = $this->record($document, self::STATUS_FAILED, null, 'Voucher does not balance.');

            return ['status' => self::STATUS_FAILED, 'document' => $record, 'message' => Craft::t('vismaz', 'The voucher does not balance and was not sent.')];
        }

        $record = $this->record($document);

        // Already done. The unique index means this is the *only* row for this source key, so
        // there is nothing to reconcile — just report it.
        if ($record->status === self::STATUS_SENT) {
            return [
                'status' => self::STATUS_SKIPPED,
                'document' => $record,
                'message' => Craft::t('vismaz', 'Already in Visma as {number}.', ['number' => $record->vismaNumber ?: $record->vismaId]),
            ];
        }

        $payload = $document->toPayload();

        $record->payload = json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        $record->attempts = (int)$record->attempts + 1;
        $record->status = self::STATUS_PENDING;
        $record->save(false);

        try {
            $response = $plugin->getApi()->request(
                'POST',
                $document->getEndpoint(),
                $payload,
                [],
                ['documentId' => $record->id, 'orderId' => $document->orderIds[0] ?? null]
            );

            $record->status = self::STATUS_SENT;
            $record->vismaId = is_array($response) ? ($response['Id'] ?? null) : null;
            $record->vismaNumber = is_array($response)
                ? (string)($response['InvoiceNumber'] ?? $response['VoucherNumber'] ?? $response['Number'] ?? '') ?: null
                : null;
            $record->response = json_encode($response, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
            $record->lastError = null;
            $record->dateSent = Db::prepareDateForDb(new DateTime());
            $record->save(false);

            $this->linkOrders($record, $document->orderIds);

            // Never trust the mapping — check what Visma actually booked against what was sent.
            $mismatch = $this->reconcile($document, $response);

            if ($mismatch !== null) {
                $record->status = self::STATUS_MISMATCHED;
                $record->lastError = $mismatch;
                $record->save(false);

                $plugin->getLog()->warning('sync.reconcile', $mismatch, [
                    'documentId' => $record->id,
                    'orderId' => $document->orderIds[0] ?? null,
                ]);

                return ['status' => self::STATUS_MISMATCHED, 'document' => $record, 'message' => $mismatch];
            }

            if ($document->type === Document::TYPE_INVOICE) {
                $this->afterInvoice($document, $record);
            }

            return ['status' => self::STATUS_SENT, 'document' => $record, 'message' => null];
        } catch (Throwable $e) {
            $record->status = self::STATUS_FAILED;
            $record->lastError = $e->getMessage();
            $record->save(false);

            $plugin->getLog()->error('sync.push', $e->getMessage(), [
                'documentId' => $record->id,
                'orderId' => $document->orderIds[0] ?? null,
            ]);

            return ['status' => self::STATUS_FAILED, 'document' => $record, 'message' => $e->getMessage()];
        }
    }

    /**
     * Find or claim the row for a document. **The only place a document row is created.**
     *
     * The unique index on `(type, sourceKey)` is what makes this safe under concurrency: two
     * processes racing to claim the same key means one of them takes an integrity error, and
     * that one re-reads instead of inserting a duplicate.
     */
    public function record(Document $document, ?string $status = null, ?string $vismaId = null, ?string $error = null): DocumentRecord
    {
        $existing = $this->findRecord($document->type, $document->sourceKey);

        if ($existing === null) {
            $record = new DocumentRecord([
                'type' => $document->type,
                'sourceKey' => $document->sourceKey,
                'status' => $status ?? self::STATUS_PENDING,
            ]);

            try {
                $this->hydrate($record, $document);
                $record->save(false);
            } catch (IntegrityException) {
                // Lost the race. The winner's row is the real one.
                $record = $this->findRecord($document->type, $document->sourceKey);

                if ($record === null) {
                    throw new \RuntimeException('Could not claim a Vismaz document row.');
                }
            }
        } else {
            $record = $existing;

            if ($record->status !== self::STATUS_SENT) {
                $this->hydrate($record, $document);

                if ($status !== null) {
                    $record->status = $status;
                }
            }
        }

        if ($vismaId !== null) {
            $record->vismaId = $vismaId;
        }

        if ($error !== null) {
            $record->lastError = $error;
        }

        $record->save(false);

        return $record;
    }

    /**
     * Everything Vismaz has done for an order.
     *
     * @return array<int, array<string, mixed>>
     */
    public function getDocumentsForOrder(int $orderId): array
    {
        return (new Query())
            ->select('d.*')
            ->from(['d' => Table::DOCUMENTS])
            ->innerJoin(['o' => Table::DOCUMENTORDERS], '[[o.documentId]] = [[d.id]]')
            ->where(['o.orderId' => $orderId])
            ->orderBy(['d.dateCreated' => SORT_DESC])
            ->all();
    }

    public function findRecord(string $type, string $sourceKey): ?DocumentRecord
    {
        /** @var DocumentRecord|null $record */
        $record = DocumentRecord::find()->where(['type' => $type, 'sourceKey' => $sourceKey])->one();

        return $record;
    }

    public function getRecordById(int $id): ?DocumentRecord
    {
        /** @var DocumentRecord|null $record */
        $record = DocumentRecord::findOne($id);

        return $record;
    }

    /**
     * Orders eligible to be synced but not yet in any document.
     *
     * @return Order[]
     */
    public function findUnsyncedOrders(?DateTimeInterface $from = null, ?DateTimeInterface $to = null, int $limit = 500): array
    {
        $settings = Plugin::getInstance()->getSettings();

        $query = Order::find()
            ->isCompleted(true)
            ->limit($limit)
            ->orderBy(['commerce_orders.dateOrdered' => SORT_ASC]);

        if ($settings->syncStatusHandles !== []) {
            $query->orderStatus($settings->syncStatusHandles);
        }

        if ($from !== null) {
            $query->dateOrdered('>= ' . Db::prepareDateForDb($from));
        }

        if ($to !== null) {
            $query->dateOrdered('<= ' . Db::prepareDateForDb($to));
        }

        // Already-documented orders are excluded here rather than filtered afterwards, so `limit`
        // means "500 orders to do" and not "500 orders, most of which are done".
        $done = (new Query())->select(['orderId'])->from(Table::DOCUMENTORDERS)->column();

        if ($done !== []) {
            $query->andWhere(['not', ['commerce_orders.id' => $done]]);
        }

        /** @var Order[] $orders */
        $orders = $query->all();

        return $orders;
    }

    /**
     * Sync one order, in whichever mode the merchant configured.
     *
     * @return array{status: string, document: ?DocumentRecord, message: ?string}
     */
    public function syncOrder(Order $order): array
    {
        $plugin = Plugin::getInstance();
        $settings = $plugin->getSettings();

        if (!$this->isEligible($order)) {
            return ['status' => self::STATUS_SKIPPED, 'document' => null, 'message' => Craft::t('vismaz', 'Order is not eligible for syncing.')];
        }

        // Voucher mode aggregates, so a single order is swept up by the period run rather than
        // pushed on its own.
        if ($settings->documentMode === Settings::MODE_VOUCHER && $plugin->isPro()) {
            [$start, $end] = Documents::periodFor($order->dateOrdered ?? new DateTime(), $settings->voucherPeriod);

            return $this->syncPeriod($start, $end);
        }

        $document = $plugin->getDocuments()->buildInvoice($order);

        return $this->push($document);
    }

    /**
     * Build and push the summary voucher for a period.
     *
     * @return array{status: string, document: ?DocumentRecord, message: ?string}
     */
    public function syncPeriod(DateTimeInterface $start, DateTimeInterface $end): array
    {
        $plugin = Plugin::getInstance();

        $endOfDay = (clone \DateTimeImmutable::createFromInterface($end))->setTime(23, 59, 59);
        $orders = array_filter($this->findUnsyncedOrders($start, $endOfDay), fn(Order $o): bool => $this->isEligible($o));

        if ($orders === []) {
            return ['status' => self::STATUS_SKIPPED, 'document' => null, 'message' => Craft::t('vismaz', 'No unsynced orders in that period.')];
        }

        $document = $plugin->getDocuments()->buildVoucher(array_values($orders), $start, $end);

        return $this->push($document);
    }

    /**
     * Push a credit note for an order's refunds, if there are any not yet credited.
     *
     * @return array{status: string, document: ?DocumentRecord, message: ?string}
     */
    public function syncRefund(Order $order): array
    {
        $plugin = Plugin::getInstance();

        if (!$plugin->isPro() || !$plugin->getSettings()->syncRefunds) {
            return ['status' => self::STATUS_SKIPPED, 'document' => null, 'message' => Craft::t('vismaz', 'Refund syncing is off.')];
        }

        $document = $plugin->getDocuments()->buildCreditNote($order);

        if ($document === null) {
            return ['status' => self::STATUS_SKIPPED, 'document' => null, 'message' => Craft::t('vismaz', 'Nothing has been refunded on this order.')];
        }

        return $this->push($document);
    }

    /**
     * Retry everything that failed.
     *
     * @return array{attempted: int, sent: int, failed: int}
     */
    public function retryFailed(int $limit = 100): array
    {
        $plugin = Plugin::getInstance();
        $result = ['attempted' => 0, 'sent' => 0, 'failed' => 0];

        $records = DocumentRecord::find()
            ->where(['status' => self::STATUS_FAILED])
            ->orderBy(['dateCreated' => SORT_ASC])
            ->limit($limit)
            ->all();

        /** @var DocumentRecord $record */
        foreach ($records as $record) {
            $document = $this->rebuild($record);

            if ($document === null) {
                continue;
            }

            $result['attempted']++;
            $outcome = $this->push($document);
            $outcome['status'] === self::STATUS_SENT ? $result['sent']++ : $result['failed']++;
        }

        return $result;
    }

    /**
     * Rebuild a document from its record, so a retry sends *current* data rather than replaying
     * a stale payload. An order edited after a failure should push as it is now.
     */
    public function rebuild(DocumentRecord $record): ?Document
    {
        $plugin = Plugin::getInstance();

        try {
            if ($record->type === Document::TYPE_VOUCHER) {
                [, $start, $end] = explode(':', (string)$record->sourceKey, 3) + [null, null, null];

                if ($start === null || $end === null) {
                    return null;
                }

                $orders = array_values(array_filter(
                    $this->findUnsyncedOrders(new \DateTimeImmutable($start), (new \DateTimeImmutable($end))->setTime(23, 59, 59)),
                    fn(Order $o): bool => $this->isEligible($o)
                ));

                return $orders === []
                    ? null
                    : $plugin->getDocuments()->buildVoucher($orders, new \DateTimeImmutable($start), new \DateTimeImmutable($end));
            }

            $orderId = (int)((new Query())
                ->select(['orderId'])
                ->from(Table::DOCUMENTORDERS)
                ->where(['documentId' => $record->id])
                ->scalar() ?: 0);

            if ($orderId === 0 && preg_match('/^(?:order|refund):(\d+)/', (string)$record->sourceKey, $matches)) {
                $orderId = (int)$matches[1];
            }

            $order = $orderId ? Order::find()->id($orderId)->one() : null;

            if (!$order instanceof Order) {
                return null;
            }

            return $record->type === Document::TYPE_CREDITNOTE
                ? $plugin->getDocuments()->buildCreditNote($order)
                : $plugin->getDocuments()->buildInvoice($order);
        } catch (Throwable $e) {
            $plugin->getLog()->error('sync.rebuild', $e->getMessage(), ['documentId' => $record->id]);

            return null;
        }
    }

    /**
     * Whether an order should be synced at all.
     */
    public function isEligible(Order $order): bool
    {
        if (!$order->isCompleted) {
            return false;
        }

        $handles = Plugin::getInstance()->getSettings()->syncStatusHandles;

        if ($handles === []) {
            return true;
        }

        try {
            $handle = $order->getOrderStatus()?->handle;
        } catch (Throwable) {
            return false;
        }

        return $handle !== null && in_array($handle, $handles, true);
    }

    /**
     * Compare the total Visma booked with the total that was sent.
     *
     * Every plugin in this family does this, for the same reason: a mapping that is subtly wrong
     * does not throw, it just books the wrong number, and nobody finds out until a bank
     * reconciliation months later. Visma may also apply its own invoice rounding, which is
     * exactly the kind of silent difference worth surfacing.
     *
     * Returns a message describing the discrepancy, or null when the two agree.
     */
    public function reconcile(Document $document, mixed $response): ?string
    {
        if (!is_array($response) || $document->type === Document::TYPE_VOUCHER) {
            return null;
        }

        $reported = $response['TotalAmount']
            ?? $response['Total']
            ?? $response['TotalAmountInvoiceCurrency']
            ?? null;

        // Visma not returning a total is not a discrepancy; there is simply nothing to compare.
        if ($reported === null || !is_numeric($reported)) {
            return null;
        }

        $ours = abs($document->getGrossTotal());
        $theirs = abs((float)$reported);

        if (abs($ours - $theirs) <= self::RECONCILE_TOLERANCE) {
            return null;
        }

        return Craft::t('vismaz', 'Visma booked {theirs} but {ours} was sent — check the document before relying on these books.', [
            'theirs' => \justinholtweb\vismaz\helpers\Money::format($theirs, $document->currency),
            'ours' => \justinholtweb\vismaz\helpers\Money::format($ours, $document->currency),
        ]);
    }

    /**
     * Join rows tying a document to the orders it covers.
     *
     * The unique index on `orderId` is what stops an order being swept into two vouchers, so an
     * integrity error here is expected and means exactly that.
     */
    private function linkOrders(DocumentRecord $record, array $orderIds): void
    {
        foreach (array_unique(array_filter($orderIds)) as $orderId) {
            try {
                (new DocumentOrderRecord(['documentId' => $record->id, 'orderId' => $orderId]))->save(false);
            } catch (IntegrityException) {
                // Already accounted for elsewhere. Not an error — it is the guarantee working.
            }
        }
    }

    /**
     * Record the invoice's payment in Visma, so the merchant's receivables do not fill up with
     * invoices that were paid before they existed.
     */
    private function afterInvoice(Document $document, DocumentRecord $record): void
    {
        $plugin = Plugin::getInstance();
        $settings = $plugin->getSettings();

        if ($settings->sendInvoiceFromVisma && $record->vismaId) {
            try {
                $plugin->getApi()->post('customerinvoices/' . $record->vismaId . '/email', []);
            } catch (Throwable $e) {
                $plugin->getLog()->warning('sync.email', $e->getMessage(), ['documentId' => $record->id]);
            }
        }
    }

    private function hydrate(DocumentRecord $record, Document $document): void
    {
        $record->documentDate = $document->date ? Db::prepareDateForDb($document->date) : null;
        $record->currency = $document->currency;
        $record->netTotal = $document->getNetTotal();
        $record->vatTotal = $document->getVatTotal();
        $record->grossTotal = $document->getGrossTotal();
        $record->taxKind = $document->tax?->kind;
        $record->orderCount = count($document->orderIds);
        $record->storeId = null;
    }
}
