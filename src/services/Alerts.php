<?php

namespace justinholtweb\vismaz\services;

use Craft;
use craft\commerce\db\Table as CommerceTable;
use craft\commerce\elements\Order;
use craft\db\Query;
use craft\helpers\App;
use craft\helpers\DateTimeHelper;
use craft\helpers\Db;
use craft\helpers\StringHelper;
use craft\helpers\UrlHelper;
use DateTime;
use DateTimeZone;
use GuzzleHttp\Client;
use GuzzleHttp\ClientInterface;
use GuzzleHttp\Handler\CurlHandler;
use GuzzleHttp\HandlerStack;
use justinholtweb\vismaz\db\Table;
use justinholtweb\vismaz\events\AlertEvent;
use justinholtweb\vismaz\helpers\Ip;
use justinholtweb\vismaz\models\Document;
use justinholtweb\vismaz\models\Settings;
use justinholtweb\vismaz\Plugin;
use Throwable;
use yii\base\Component;

/**
 * Failure alerts: tell somebody when the Visma connection is in trouble, once, and again when it
 * is not.
 *
 * Ported from Erpy, the reference for the connector family (see its CLAUDE.md, "Failure alerts"),
 * by way of Zo. The Documents screen already knows everything that has gone wrong. What it cannot
 * do is make a merchant look at it. Five incidents are worth interrupting somebody for:
 *
 * - **Failures** — at least `alertFailureThreshold` invoices, credit notes or vouchers failed to
 *   reach Visma inside the window. Every one of those is revenue that is not in the books.
 * - **Mismatched** — a document Visma accepted at a different total from the one sent. The
 *   invoice exists; the books are wrong. {@see Sync::reconcile()}.
 * - **Payments** — at least `alertFailureThreshold` Commerce payments could not be registered
 *   against their Visma invoice inside the window. The invoice then sits unpaid in Visma's
 *   receivables, and Visma's own reminders chase a customer who has paid.
 * - **Authentication** — Visma refused the refresh token, or answered a 401 after Vismaz had
 *   already refreshed. A refresh token dies the moment the Visma user changes their password, and
 *   nothing syncs until somebody reconnects.
 * - **Stalled** — work that should have happened has not: a completed order with no invoice
 *   `alertStallHours` after it was placed while automatic sending is on, a send stuck in
 *   `pending`, a payment whose job never ran. Almost always a queue that is not running.
 *
 * Each incident is a latch in `vismaz_alerts`, one row per incident type, unique in the
 * database. Opening it sends one alert; it stays open — and silent — however many checks see the
 * same trouble; clearing it sends one recovery. A send is claimed with a conditional update before
 * it goes out and released if it fails, so the queue and cron checking in the same minute cannot
 * both send, and a mail outage does not swallow the alert.
 *
 * Detection runs from four places: {@see afterDocument()} at the end of every push and
 * {@see afterPayment()} after every payment registration (no cron needed), {@see noteAuthFailure()}
 * /{@see noteAuthSuccess()} from `Api` and `Auth` (authentication, immediately), and
 * {@see check()} from `vismaz/alerts/check`, `vismaz/sync/retry` and `vismaz/sync/payments` — the
 * only paths that can notice an incident *clearing*, or a stall, when nothing is syncing.
 *
 * Bodies are redacted ({@see redact()}): an alert goes to a mailbox and a chat channel, both of
 * which outlive the credential they would otherwise quote.
 */
class Alerts extends Component
{
    public const INCIDENT_FAILURES = 'failures';
    public const INCIDENT_MISMATCHED = 'mismatched';
    public const INCIDENT_PAYMENTS = 'payments';
    public const INCIDENT_AUTH = 'auth';
    public const INCIDENT_STALLED = 'stalled';

    public const STATE_OK = 'ok';
    public const STATE_OPEN = 'open';

    /** @event AlertEvent before an alert or a recovery is sent; set `isValid` false to swallow it. */
    public const EVENT_BEFORE_NOTIFY = 'beforeNotify';

    /** How often one process re-checks that an authentication incident is still clear. */
    private const AUTH_SUCCESS_TTL = 60;

    /** How far back the stall check looks for orders that never got an invoice. */
    private const STALL_LOOKBACK_DAYS = 7;

    /**
     * The HTTP client for the webhook. Null means a curl-only client built per send; tests put a
     * Guzzle `MockHandler` client here. Never a client on Guzzle's default stack: it can hand a
     * request to PHP's stream wrapper, which ignores `CURLOPT_RESOLVE` — the address pin.
     */
    public ?ClientInterface $webhookClient = null;

    /** When this process last confirmed authentication is clear. */
    private ?int $authClearAt = null;

    /**
     * @return array<string,string> incident => label
     */
    public static function incidents(): array
    {
        return [
            self::INCIDENT_FAILURES => Craft::t('vismaz', 'Orders failing to reach Visma'),
            self::INCIDENT_MISMATCHED => Craft::t('vismaz', 'Documents booked at the wrong total'),
            self::INCIDENT_PAYMENTS => Craft::t('vismaz', 'Payments not registered in Visma'),
            self::INCIDENT_AUTH => Craft::t('vismaz', 'Visma refused the connection'),
            self::INCIDENT_STALLED => Craft::t('vismaz', 'Sending to Visma has stalled'),
        ];
    }

    public static function incidentLabel(string $incident): string
    {
        return self::incidents()[$incident] ?? $incident;
    }

    // ---------------------------------------------------------------------------------------
    // Detection
    // ---------------------------------------------------------------------------------------

    /**
     * Evaluate every incident and send whatever is owed.
     *
     * Nothing is checked until Vismaz is connected: an install that was never connected is not an
     * incident, and disconnecting is neither a new incident nor a recovery.
     *
     * @param string[]|null $only limit to these incidents
     * @return array<int,array{incident:string,state:string,transition:?string,notified:bool,detail:?string}>
     */
    public function check(?array $only = null): array
    {
        $plugin = Plugin::getInstance();

        if (!$plugin->getAuth()->isConfigured() || !$plugin->getAuth()->isConnected()) {
            return [];
        }

        $results = [];

        foreach (array_keys(self::incidents()) as $incident) {
            if ($only !== null && !in_array($incident, $only, true)) {
                continue;
            }

            $row = $this->row($incident);
            [$open, $detail] = $this->measure($incident, $row);
            $results[] = $this->transition($incident, $open, $detail);
        }

        return $results;
    }

    /**
     * The end of every push. Fail-open: an alert that cannot be worked out must never be the
     * reason a push reports a failure.
     *
     * The stall check only runs here while a stall is open — an invoice going out is the thing
     * that ends one, and the query is not free.
     */
    public function afterDocument(): void
    {
        try {
            $only = [self::INCIDENT_FAILURES, self::INCIDENT_MISMATCHED];

            if (($this->row(self::INCIDENT_STALLED)['state'] ?? null) === self::STATE_OPEN) {
                $only[] = self::INCIDENT_STALLED;
            }

            $this->check($only);
        } catch (Throwable $e) {
            Craft::warning('Vismaz could not evaluate alerts after a push: ' . $e->getMessage(), 'vismaz');
        }
    }

    /**
     * The end of every payment registration. Fail-open, like {@see afterDocument()}.
     */
    public function afterPayment(): void
    {
        try {
            $only = [self::INCIDENT_PAYMENTS];

            if (($this->row(self::INCIDENT_STALLED)['state'] ?? null) === self::STATE_OPEN) {
                $only[] = self::INCIDENT_STALLED;
            }

            $this->check($only);
        } catch (Throwable $e) {
            Craft::warning('Vismaz could not evaluate alerts after a payment: ' . $e->getMessage(), 'vismaz');
        }
    }

    /**
     * Visma refused the credentials: a final 401, or a refresh token it would not honour.
     *
     * Recorded as a signal rather than measured, because nothing else remembers it — the log can
     * be pruned, and a refused token never makes a document row.
     */
    public function noteAuthFailure(string $reason): void
    {
        try {
            $this->ensureRow(self::INCIDENT_AUTH);

            Craft::$app->getDb()->createCommand()->update(Table::ALERTS, [
                'signalledAt' => $this->now(),
                'signalClearedAt' => null,
                'detail' => $this->redact($reason),
                'dateUpdated' => $this->now(),
            ], ['incident' => self::INCIDENT_AUTH])->execute();

            $this->authClearAt = null;

            $this->check([self::INCIDENT_AUTH]);
        } catch (Throwable $e) {
            Craft::warning('Vismaz could not record an authentication failure: ' . $e->getMessage(), 'vismaz');
        }
    }

    /**
     * An authenticated request succeeded. Called by `Api` on every success, so it costs at most
     * one conditional UPDATE per minute per process, and usually nothing.
     */
    public function noteAuthSuccess(): void
    {
        if ($this->authClearAt !== null && time() - $this->authClearAt < self::AUTH_SUCCESS_TTL) {
            return;
        }

        $this->authClearAt = time();

        try {
            $cleared = Craft::$app->getDb()->createCommand()->update(Table::ALERTS, [
                'signalClearedAt' => $this->now(),
                'dateUpdated' => $this->now(),
            ], [
                'and',
                ['incident' => self::INCIDENT_AUTH, 'signalClearedAt' => null],
                ['not', ['signalledAt' => null]],
            ])->execute();

            if ($cleared > 0) {
                $this->check([self::INCIDENT_AUTH]);
            }
        } catch (Throwable $e) {
            Craft::warning('Vismaz could not clear an authentication alert: ' . $e->getMessage(), 'vismaz');
        }
    }

    /**
     * Whether an incident is happening right now, and a redacted line saying what was seen.
     *
     * @param array<string,mixed> $row
     * @return array{0:bool,1:?string}
     */
    private function measure(string $incident, array $row): array
    {
        $settings = Plugin::getInstance()->getSettings();
        $wasOpen = ($row['state'] ?? self::STATE_OK) === self::STATE_OPEN;
        $window = max(5, $settings->alertWindowMinutes);
        $cutoff = Db::prepareDateForDb((new DateTime())->modify("-$window minutes"));

        switch ($incident) {
            case self::INCIDENT_FAILURES:
                if (!$settings->alertOnFailures) {
                    return [false, null];
                }

                $recent = (new Query())
                    ->from(Table::DOCUMENTS)
                    ->where(['status' => Sync::STATUS_FAILED])
                    ->andWhere(['>=', 'dateUpdated', $cutoff]);
                $count = (int)(clone $recent)->count();

                // Hysteresis: it takes the threshold to open, and a whole quiet window to close.
                // Closing the moment the count dipped below the threshold would page somebody
                // twice an hour for a Visma that is refusing every fourth order.
                $open = $wasOpen ? $count > 0 : $count >= max(1, $settings->alertFailureThreshold);

                if (!$open) {
                    return [false, null];
                }

                $latest = (clone $recent)
                    ->select(['type', 'sourceKey', 'lastError'])
                    ->orderBy(['dateUpdated' => SORT_DESC, 'id' => SORT_DESC])
                    ->one();

                return [true, $this->redact(Craft::t('vismaz', '{count} documents failed to reach Visma in the last {window} minutes; {failed} show as failed in all. Latest: {type} {key}: {error}', [
                    'count' => $count,
                    'window' => $window,
                    'failed' => $this->standingCount(self::INCIDENT_FAILURES),
                    'type' => (string)($latest['type'] ?? ''),
                    'key' => (string)($latest['sourceKey'] ?? ''),
                    'error' => is_string($latest['lastError'] ?? null) && $latest['lastError'] !== '' ? $latest['lastError'] : '—',
                ]))];

            case self::INCIDENT_MISMATCHED:
                if (!$settings->alertOnMismatch) {
                    return [false, null];
                }

                $recent = (new Query())
                    ->from(Table::DOCUMENTS)
                    ->where(['status' => Sync::STATUS_MISMATCHED])
                    ->andWhere(['>=', 'dateUpdated', $cutoff]);
                $count = (int)(clone $recent)->count();

                if ($count === 0) {
                    return [false, null];
                }

                $latest = (clone $recent)
                    ->select(['type', 'sourceKey', 'vismaNumber', 'lastError'])
                    ->orderBy(['dateUpdated' => SORT_DESC, 'id' => SORT_DESC])
                    ->one();

                return [true, $this->redact(Craft::t('vismaz', '{count} documents sent in the last {window} minutes were booked at a different total; {total} in all. Latest: {type} {number}: {error}', [
                    'count' => $count,
                    'window' => $window,
                    'total' => $this->standingCount(self::INCIDENT_MISMATCHED),
                    'type' => (string)($latest['type'] ?? ''),
                    'number' => (string)(($latest['vismaNumber'] ?? '') ?: ($latest['sourceKey'] ?? '')),
                    'error' => (string)($latest['lastError'] ?? ''),
                ]))];

            case self::INCIDENT_PAYMENTS:
                if (!$settings->alertOnPayments) {
                    return [false, null];
                }

                $recent = (new Query())
                    ->from(Table::PAYMENTS)
                    ->where(['status' => Payments::STATUS_FAILED])
                    ->andWhere(['>=', 'dateUpdated', $cutoff]);
                $count = (int)(clone $recent)->count();
                $open = $wasOpen ? $count > 0 : $count >= max(1, $settings->alertFailureThreshold);

                if (!$open) {
                    return [false, null];
                }

                $latest = (clone $recent)
                    ->select(['orderId', 'amount', 'currency', 'lastError'])
                    ->orderBy(['dateUpdated' => SORT_DESC, 'id' => SORT_DESC])
                    ->one();

                return [true, $this->redact(Craft::t('vismaz', '{count} payments could not be registered in Visma in the last {window} minutes; {failed} show as failed in all. Latest: order {reference}, {amount} {currency}: {error}', [
                    'count' => $count,
                    'window' => $window,
                    'failed' => $this->standingCount(self::INCIDENT_PAYMENTS),
                    'reference' => $this->orderReference((int)($latest['orderId'] ?? 0)),
                    'amount' => number_format((float)($latest['amount'] ?? 0), 2, '.', ''),
                    'currency' => (string)($latest['currency'] ?? ''),
                    'error' => is_string($latest['lastError'] ?? null) && $latest['lastError'] !== '' ? $latest['lastError'] : '—',
                ]))];

            case self::INCIDENT_AUTH:
                $open = $settings->alertOnAuthFailure
                    && !empty($row['signalledAt'])
                    && empty($row['signalClearedAt']);

                return [$open, $open ? ($row['detail'] ?? null) : null];

            case self::INCIDENT_STALLED:
                $stalled = $this->stalled();

                if ($stalled['orders'] === [] && $stalled['pending'] === 0 && $stalled['payments'] === 0) {
                    return [false, null];
                }

                $hours = $settings->alertStallHours;
                $lines = [];

                if ($stalled['orders'] !== []) {
                    $oldest = $stalled['orders'][0];
                    $lines[] = Craft::t('vismaz', '{count} completed orders have had no invoice in Visma for more than {hours} hours (oldest: order {reference}, placed {since} UTC). Is the queue running?', [
                        'count' => count($stalled['orders']),
                        'hours' => $hours,
                        'reference' => $this->orderReference($oldest['id']),
                        'since' => $oldest['since']->format('Y-m-d H:i'),
                    ]);
                }

                if ($stalled['pending'] > 0) {
                    $lines[] = Craft::t('vismaz', '{count} documents have been sending for more than {hours} hours.', [
                        'count' => $stalled['pending'],
                        'hours' => $hours,
                    ]);
                }

                if ($stalled['payments'] > 0) {
                    $lines[] = Craft::t('vismaz', '{count} payments have been waiting to be registered for more than {hours} hours although their invoice is in Visma.', [
                        'count' => $stalled['payments'],
                        'hours' => $hours,
                    ]);
                }

                return [true, $this->redact(implode(' ', $lines))];
        }

        return [false, null];
    }

    /**
     * What is still wrong regardless of when it happened — quoted in alerts and recoveries, so a
     * recovery ("nothing new for an hour") does not read as "everything is fixed".
     */
    public function standingCount(string $incident): int
    {
        return match ($incident) {
            self::INCIDENT_FAILURES => (int)(new Query())->from(Table::DOCUMENTS)->where(['status' => Sync::STATUS_FAILED])->count(),
            self::INCIDENT_MISMATCHED => (int)(new Query())->from(Table::DOCUMENTS)->where(['status' => Sync::STATUS_MISMATCHED])->count(),
            self::INCIDENT_PAYMENTS => (int)(new Query())->from(Table::PAYMENTS)->where(['status' => Payments::STATUS_FAILED])->count(),
            default => 0,
        };
    }

    /**
     * Work that should have happened by now and has not.
     *
     * - **Orders** — only with automatic sending on and in invoice mode (voucher mode posts a
     *   period from cron, and manual mode is a choice). Completed, eligible orders placed between
     *   `alertStallHours` and a week ago — and after Vismaz was installed: a shop's history from
     *   before is what `vismaz/sync/orders --from` is for, not news — with no invoice row in any
     *   state. A failed or pending one is its own incident or count.
     * - **Pending** — document rows claimed and not finished for `alertStallHours`: a push that
     *   died mid-request.
     * - **Payments** — `pending` rows that old (queued, never run), and `waiting` rows that old
     *   whose invoice *is* in Visma (released, never run).
     *
     * @return array{orders:array<int,array{id:int,since:DateTime}>,pending:int,payments:int}
     */
    public function stalled(): array
    {
        $plugin = Plugin::getInstance();
        $settings = $plugin->getSettings();
        $hours = $settings->alertStallHours;
        $none = ['orders' => [], 'pending' => 0, 'payments' => 0];

        if ($hours <= 0) {
            return $none;
        }

        $utc = new DateTimeZone('UTC');
        $cutoff = (new DateTime('now', $utc))->modify("-$hours hours");
        $cutoffDb = Db::prepareDateForDb($cutoff);

        $pending = (int)(new Query())
            ->from(Table::DOCUMENTS)
            ->where(['status' => Sync::STATUS_PENDING])
            ->andWhere(['<', 'dateUpdated', $cutoffDb])
            ->count();

        $payments = (int)(new Query())
            ->from(['p' => Table::PAYMENTS])
            ->where(['<', 'p.dateUpdated', $cutoffDb])
            ->andWhere([
                'or',
                ['p.status' => Payments::STATUS_PENDING],
                [
                    'and',
                    ['p.status' => Payments::STATUS_WAITING],
                    // An invoice is joined to its order only once it is in Visma.
                    ['exists', (new Query())
                        ->from(['do' => Table::DOCUMENTORDERS])
                        ->innerJoin(['d' => Table::DOCUMENTS], '[[d.id]] = [[do.documentId]]')
                        ->where('[[do.orderId]] = [[p.orderId]]')
                        ->andWhere(['d.type' => Document::TYPE_INVOICE, 'd.status' => [Sync::STATUS_SENT, Sync::STATUS_MISMATCHED]])
                        ->andWhere(['<', 'd.dateSent', $cutoffDb]),
                    ],
                ],
            ])
            ->count();

        $orders = [];

        if (
            $settings->autoPush
            && $settings->documentMode === Settings::MODE_INVOICE
            && Plugin::commerceIsReady()
        ) {
            $floor = (clone $cutoff)->modify('-' . self::STALL_LOOKBACK_DAYS . ' days');
            $installed = Craft::$app->getPlugins()->getStoredPluginInfo(Plugin::HANDLE)['installDate'] ?? null;
            $installedAt = $installed instanceof DateTime ? $installed : (is_string($installed) ? DateTimeHelper::toDateTime($installed) : null);

            if ($installedAt instanceof DateTime && $installedAt > $floor) {
                $floor = $installedAt;
            }

            $query = Order::find()
                ->isCompleted(true)
                ->status(null)
                ->dateOrdered(['and', '>= ' . $floor->format(DATE_ATOM), '< ' . $cutoff->format(DATE_ATOM)])
                // No invoice row in any state, by key or by join row (a voucher's orders have
                // only the latter).
                ->andWhere(['not in', 'commerce_orders.id', $plugin->getOrderStatus()->documentOrderIds(null, [Document::TYPE_INVOICE, Document::TYPE_VOUCHER])])
                ->orderBy(['commerce_orders.dateOrdered' => SORT_ASC])
                ->limit(200);

            if ($settings->syncStatusHandles !== []) {
                $query->orderStatus($settings->syncStatusHandles);
            }

            /** @var Order $order */
            foreach ($query->all() as $order) {
                if ($order->dateOrdered === null) {
                    continue;
                }

                $orders[] = ['id' => (int)$order->id, 'since' => (clone $order->dateOrdered)->setTimezone($utc)];
            }
        }

        return ['orders' => $orders, 'pending' => $pending, 'payments' => $payments];
    }

    private function orderReference(int $orderId): string
    {
        if ($orderId === 0 || !Plugin::commerceIsReady()) {
            return '#' . $orderId;
        }

        $row = (new Query())
            ->select(['reference', 'number'])
            ->from(CommerceTable::ORDERS)
            ->where(['id' => $orderId])
            ->one();

        if (!$row) {
            return '#' . $orderId;
        }

        return (string)($row['reference'] ?: substr((string)$row['number'], 0, 7));
    }

    // ---------------------------------------------------------------------------------------
    // The latch
    // ---------------------------------------------------------------------------------------

    /**
     * Move the latch, then send whatever it says is owed.
     *
     * @return array{incident:string,state:string,transition:?string,notified:bool,detail:?string}
     */
    private function transition(string $incident, bool $open, ?string $detail): array
    {
        $db = Craft::$app->getDb();
        $row = $this->row($incident);
        $transition = null;
        $where = ['id' => $row['id']];

        if ($open && $row['state'] !== self::STATE_OPEN) {
            // Conditional on the old state, so two checks racing open it once.
            $won = $db->createCommand()->update(Table::ALERTS, [
                'state' => self::STATE_OPEN,
                'openedAt' => $this->now(),
                'notifiedAt' => null,
                'recoveryNotifiedAt' => null,
                'detail' => $detail,
                'dateUpdated' => $this->now(),
            ], $where + ['state' => self::STATE_OK])->execute();
            $transition = $won ? 'opened' : null;
        } elseif ($open && $detail !== null && $detail !== $row['detail']) {
            $db->createCommand()->update(Table::ALERTS, ['detail' => $detail, 'dateUpdated' => $this->now()], $where)->execute();
        } elseif (!$open && $row['state'] === self::STATE_OPEN) {
            $won = $db->createCommand()->update(Table::ALERTS, [
                'state' => self::STATE_OK,
                'recoveredAt' => $this->now(),
                'dateUpdated' => $this->now(),
            ], $where + ['state' => self::STATE_OPEN])->execute();
            $transition = $won ? 'recovered' : null;
        }

        $notified = $this->deliverOwed($incident);
        $row = $this->row($incident);

        return [
            'incident' => $incident,
            'state' => $row['state'],
            'transition' => $transition,
            'notified' => $notified,
            'detail' => $row['detail'],
        ];
    }

    /**
     * Send what the latch says is owed: an opening alert nobody has had yet, or the recovery for
     * one somebody has. Claimed by a conditional update first, released again if every channel
     * failed — so it is sent once, and a mail outage delays it rather than losing it.
     */
    private function deliverOwed(string $incident): bool
    {
        $db = Craft::$app->getDb();
        $row = $this->row($incident);
        $where = ['id' => $row['id']];

        if ($row['state'] === self::STATE_OPEN && $row['notifiedAt'] === null) {
            // A flapping connection gets one alert and one recovery per cooldown, not one each
            // per check. A reopening inside the cooldown is told about once the cooldown ends,
            // if it is still open by then.
            if ($row['quietUntil'] !== null && $row['quietUntil'] > $this->now()) {
                return false;
            }

            $claimed = $db->createCommand()->update(Table::ALERTS, ['notifiedAt' => $this->now()], $where + ['notifiedAt' => null, 'state' => self::STATE_OPEN])->execute();

            if (!$claimed) {
                return false;
            }

            if ($this->notify($incident, false, (string)$row['detail'])) {
                return true;
            }

            $db->createCommand()->update(Table::ALERTS, ['notifiedAt' => null], $where)->execute();

            return false;
        }

        // A recovery is only owed for an incident somebody was told about.
        if ($row['state'] === self::STATE_OK && $row['notifiedAt'] !== null && $row['recoveryNotifiedAt'] === null) {
            $cooldown = max(0, Plugin::getInstance()->getSettings()->alertCooldownMinutes);
            $claimed = $db->createCommand()->update(Table::ALERTS, [
                'recoveryNotifiedAt' => $this->now(),
                'quietUntil' => $cooldown > 0 ? Db::prepareDateForDb((new DateTime())->modify("+$cooldown minutes")) : null,
            ], $where + ['state' => self::STATE_OK, 'recoveryNotifiedAt' => null])->execute();

            if (!$claimed) {
                return false;
            }

            if ($this->notify($incident, true, (string)$row['detail'])) {
                return true;
            }

            $db->createCommand()->update(Table::ALERTS, [
                'recoveryNotifiedAt' => null,
                'quietUntil' => $row['quietUntil'],
            ], $where)->execute();
        }

        return false;
    }

    /**
     * The latch row, created on first sight.
     *
     * @return array<string,mixed>
     */
    private function row(string $incident): array
    {
        $this->ensureRow($incident);

        return (array)(new Query())
            ->from(Table::ALERTS)
            ->where(['incident' => $incident])
            ->one();
    }

    private function ensureRow(string $incident): void
    {
        if ((new Query())->from(Table::ALERTS)->where(['incident' => $incident])->exists()) {
            return;
        }

        try {
            Craft::$app->getDb()->createCommand()->insert(Table::ALERTS, [
                'incident' => $incident,
                'state' => self::STATE_OK,
                'dateCreated' => $this->now(),
                'dateUpdated' => $this->now(),
                'uid' => StringHelper::UUID(),
            ])->execute();
        } catch (\yii\db\IntegrityException) {
            // Another process created it between the check and the insert. The unique index is
            // the point; there is nothing to do.
        }
    }

    /**
     * Every open incident, for the widget and the console.
     *
     * @return array<int,array<string,mixed>>
     */
    public function openIncidents(): array
    {
        $rows = (new Query())
            ->from(Table::ALERTS)
            ->where(['state' => self::STATE_OPEN])
            ->orderBy(['openedAt' => SORT_DESC])
            ->all();

        return array_map(static fn(array $row) => $row + ['label' => self::incidentLabel((string)$row['incident'])], $rows);
    }

    /**
     * What the Dashboard widget shows.
     *
     * @return array{connected:bool,company:?string,environment:string,documents:array<string,int>,payments:array<string,int>,lastSent:?string,incidents:array<int,array<string,mixed>>}
     */
    public function overview(): array
    {
        $plugin = Plugin::getInstance();
        $connection = $plugin->getAuth()->getConnection();

        $documents = array_fill_keys([Sync::STATUS_SENT, Sync::STATUS_FAILED, Sync::STATUS_MISMATCHED, Sync::STATUS_PENDING], 0);

        foreach ((new Query())->select(['status', 'n' => 'COUNT(*)'])->from(Table::DOCUMENTS)->groupBy(['status'])->all() as $row) {
            $documents[(string)$row['status']] = (int)$row['n'];
        }

        $payments = array_fill_keys([Payments::STATUS_SENT, Payments::STATUS_FAILED, Payments::STATUS_WAITING, Payments::STATUS_PENDING], 0);

        foreach ((new Query())->select(['status', 'n' => 'COUNT(*)'])->from(Table::PAYMENTS)->groupBy(['status'])->all() as $row) {
            $payments[(string)$row['status']] = (int)$row['n'];
        }

        $lastSent = (new Query())
            ->from(Table::DOCUMENTS)
            ->where(['status' => [Sync::STATUS_SENT, Sync::STATUS_MISMATCHED]])
            ->max('dateSent');

        return [
            'connected' => $connection !== null,
            'company' => $connection?->companyName,
            'environment' => $plugin->getSettings()->environment,
            'documents' => $documents,
            'payments' => $payments,
            // A bare UTC string; the zone is named so it is not read as site-local.
            'lastSent' => is_string($lastSent) ? $lastSent : null,
            'incidents' => $this->openIncidents(),
        ];
    }

    // ---------------------------------------------------------------------------------------
    // Delivery
    // ---------------------------------------------------------------------------------------

    /**
     * Send one alert to every configured channel. True when at least one channel took it — a
     * second email because the webhook failed would be worse than a missing Slack message.
     */
    public function notify(string $incident, bool $recovered, string $detail): bool
    {
        $settings = Plugin::getInstance()->getSettings();
        $recipients = $settings->recipientList();
        $webhookUrl = trim((string)App::parseEnv($settings->alertWebhookUrl));
        $hasWebhook = $webhookUrl !== '' && !str_starts_with($webhookUrl, '$');

        if ($recipients === [] && !$hasWebhook) {
            return false;
        }

        $message = $this->compose($incident, $recovered, $detail);

        $event = new AlertEvent([
            'incident' => $incident,
            'recovered' => $recovered,
            'detail' => $message['detail'],
            'subject' => $message['subject'],
            'body' => $message['body'],
            'payload' => $this->payload($settings->alertWebhookFormat, $message),
        ]);

        if ($this->hasEventHandlers(self::EVENT_BEFORE_NOTIFY)) {
            $this->trigger(self::EVENT_BEFORE_NOTIFY, $event);

            if (!$event->isValid) {
                return true;
            }
        }

        $sent = false;

        if ($recipients !== []) {
            try {
                $sent = Craft::$app->getMailer()->compose()
                    ->setTo($recipients)
                    ->setSubject($event->subject)
                    ->setTextBody($event->body)
                    ->send();
            } catch (Throwable $e) {
                Craft::error('Vismaz could not email an alert: ' . $e->getMessage(), 'vismaz');
            }
        }

        if ($hasWebhook) {
            $result = $this->postWebhook($webhookUrl, $event->payload);

            if ($result === true) {
                $sent = true;
            } else {
                Craft::error('Vismaz could not post an alert webhook: ' . $result, 'vismaz');
            }
        }

        return $sent;
    }

    /**
     * Send a sample through every channel, for the settings screen's "Send a test alert".
     *
     * @return array{email:?bool,webhook:bool|string|null}
     */
    public function sendTest(): array
    {
        $settings = Plugin::getInstance()->getSettings();
        $recipients = $settings->recipientList();
        $webhookUrl = trim((string)App::parseEnv($settings->alertWebhookUrl));
        $message = $this->compose('test', false, Craft::t('vismaz', 'This is a test. If you can read it, failure alerts will reach you here.'));
        $result = ['email' => null, 'webhook' => null];

        if ($recipients !== []) {
            try {
                $result['email'] = Craft::$app->getMailer()->compose()
                    ->setTo($recipients)
                    ->setSubject($message['subject'])
                    ->setTextBody($message['body'])
                    ->send();
            } catch (Throwable $e) {
                Craft::error('Vismaz could not email a test alert: ' . $e->getMessage(), 'vismaz');
                $result['email'] = false;
            }
        }

        if ($webhookUrl !== '' && !str_starts_with($webhookUrl, '$')) {
            $result['webhook'] = $this->postWebhook($webhookUrl, $this->payload($settings->alertWebhookFormat, $message));
        }

        return $result;
    }

    /**
     * Subject, plain-text body and links for one alert.
     *
     * Plain text on purpose: it may be read on a phone at an inconvenient hour, and it should say
     * what happened and where to go — nothing that needs a rendering engine. `UrlHelper::cpUrl()`
     * rather than a hand-assembled host, because this runs from the queue and the console, where
     * there is no request to read a host from.
     *
     * @return array{subject:string,body:string,title:string,detail:string,url:string,syncUrl:string,incident:string,recovered:bool,site:string}
     */
    public function compose(string $incident, bool $recovered, string $detail): array
    {
        $site = Craft::$app->getSites()->getPrimarySite()->getName();
        $label = $incident === 'test' ? Craft::t('vismaz', 'Test alert') : self::incidentLabel($incident);
        $detail = $this->redact($detail);
        $window = max(5, Plugin::getInstance()->getSettings()->alertWindowMinutes);

        $syncUrl = UrlHelper::cpUrl('vismaz/documents');
        $url = match ($incident) {
            self::INCIDENT_AUTH => UrlHelper::cpUrl('vismaz/connection'),
            self::INCIDENT_FAILURES => UrlHelper::cpUrl('vismaz/documents', ['status' => Sync::STATUS_FAILED]),
            self::INCIDENT_MISMATCHED => UrlHelper::cpUrl('vismaz/documents', ['status' => Sync::STATUS_MISMATCHED]),
            self::INCIDENT_PAYMENTS => UrlHelper::cpUrl('vismaz/log', ['level' => Log::LEVEL_ERROR]),
            self::INCIDENT_STALLED => UrlHelper::cpUrl('utilities/queue-manager'),
            default => $syncUrl,
        };

        $title = $recovered
            ? Craft::t('vismaz', 'Recovered: {label}', ['label' => $label])
            : $label;

        $lines = [
            $recovered
                ? Craft::t('vismaz', 'Vismaz on {site}: this has cleared.', ['site' => $site])
                : Craft::t('vismaz', 'Vismaz on {site} needs attention.', ['site' => $site]),
            '',
            Craft::t('vismaz', 'Incident: {label}', ['label' => $label]),
        ];

        if (!$recovered && $detail !== '') {
            $lines[] = '';
            $lines[] = $detail;
        }

        if (!$recovered) {
            $advice = match ($incident) {
                self::INCIDENT_AUTH => Craft::t('vismaz', 'Nothing will reach Visma until Vismaz is reconnected. A Visma refresh token stops working the moment the Visma user changes their password. Open Vismaz → Connection and connect again.'),
                self::INCIDENT_PAYMENTS => Craft::t('vismaz', 'Until they are registered, those invoices stay open in Visma and its reminders can chase customers who have paid. Fix the cause, then run `php craft vismaz/sync/payments`.'),
                self::INCIDENT_STALLED => Craft::t('vismaz', 'Check that Craft’s queue is running. Orders that were missed can be sent with `php craft vismaz/sync/orders --from=…`.'),
                self::INCIDENT_MISMATCHED => Craft::t('vismaz', 'These documents are in Visma, so they are not retried. Compare each with its order and correct it in Visma by hand.'),
                default => null,
            };

            if ($advice !== null) {
                $lines[] = '';
                $lines[] = $advice;
            }
        }

        // "Nothing new for an hour" is not "fixed". Say what is still waiting.
        if ($recovered && in_array($incident, [self::INCIDENT_FAILURES, self::INCIDENT_MISMATCHED, self::INCIDENT_PAYMENTS], true)) {
            $standing = $this->standingCount($incident);
            $lines[] = '';
            $lines[] = match ($incident) {
                self::INCIDENT_FAILURES => Craft::t('vismaz', 'Nothing new has failed in the last {window} minutes. {count} documents still show as failed on the Documents screen.', ['window' => $window, 'count' => $standing]),
                self::INCIDENT_MISMATCHED => Craft::t('vismaz', 'Nothing sent in the last {window} minutes was booked at a different total. {count} documents still show as mismatched.', ['window' => $window, 'count' => $standing]),
                default => Craft::t('vismaz', 'No payment has failed in the last {window} minutes. {count} payments still show as failed.', ['window' => $window, 'count' => $standing]),
            };
        } elseif ($recovered) {
            $lines[] = '';
            $lines[] = Craft::t('vismaz', 'No action is needed.');
        }

        $lines[] = '';
        $lines[] = Craft::t('vismaz', 'Open: {url}', ['url' => $url]);

        if ($url !== $syncUrl) {
            $lines[] = Craft::t('vismaz', 'Documents: {url}', ['url' => $syncUrl]);
        }

        $lines[] = '';
        $lines[] = Craft::t('vismaz', 'You get one message when this starts and one when it clears. Change who gets them in the Vismaz settings.');

        return [
            'subject' => '[' . $site . '] ' . Craft::t('vismaz', 'Visma: {title}', ['title' => $title]),
            'body' => implode("\n", $lines) . "\n",
            'title' => $title,
            'detail' => $recovered ? '' : $detail,
            'url' => $url,
            'syncUrl' => $syncUrl,
            'incident' => $incident,
            'recovered' => $recovered,
            'site' => $site,
        ];
    }

    /**
     * The webhook body in the receiver's own shape.
     *
     * @param array{subject:string,body:string,title:string,detail:string,url:string,syncUrl:string,incident:string,recovered:bool,site:string} $m
     * @return array<string,mixed>
     */
    public function payload(string $format, array $m): array
    {
        $text = $m['title'] . ($m['detail'] !== '' ? "\n" . $m['detail'] : '');

        return match ($format) {
            'teams' => [
                'type' => 'message',
                'attachments' => [[
                    'contentType' => 'application/vnd.microsoft.card.adaptive',
                    'contentUrl' => null,
                    'content' => [
                        '$schema' => 'http://adaptivecards.io/schemas/adaptive-card.json',
                        'type' => 'AdaptiveCard',
                        'version' => '1.4',
                        'body' => array_values(array_filter([
                            ['type' => 'TextBlock', 'size' => 'Large', 'weight' => 'Bolder', 'color' => $m['recovered'] ? 'Good' : 'Attention', 'text' => $m['title'], 'wrap' => true],
                            $m['detail'] !== '' ? ['type' => 'TextBlock', 'text' => $m['detail'], 'wrap' => true] : null,
                            ['type' => 'FactSet', 'facts' => [['title' => 'Site', 'value' => $m['site']]]],
                        ])),
                        'actions' => [['type' => 'Action.OpenUrl', 'title' => Craft::t('vismaz', 'Open in Craft'), 'url' => $m['url']]],
                    ],
                ]],
            ],
            'json' => [
                'event' => $m['recovered'] ? 'vismaz.alert.recovered' : 'vismaz.alert.opened',
                'incident' => $m['incident'],
                'site' => $m['site'],
                'title' => $m['title'],
                'detail' => $m['detail'],
                'url' => $m['url'],
                'syncUrl' => $m['syncUrl'],
                'at' => (new DateTime('now', new DateTimeZone('UTC')))->format(DATE_ATOM),
            ],
            default => [
                'text' => $text,
                'blocks' => [
                    ['type' => 'section', 'text' => ['type' => 'mrkdwn', 'text' => '*' . $m['title'] . '*' . ($m['detail'] !== '' ? "\n" . $m['detail'] : '')]],
                    ['type' => 'context', 'elements' => [['type' => 'mrkdwn', 'text' => $m['site'] . ' · <' . $m['url'] . '|' . Craft::t('vismaz', 'Open in Craft') . '>']]],
                ],
            ],
        };
    }

    /**
     * Where an alert webhook may be sent, or why it may not. The family SSRF rules, as in Erpy:
     *
     * 1. `http` and `https` only, with no credentials in the URL.
     * 2. Every address the host resolves to must be public ({@see Ip::resolvePublic()}).
     * 3. The send pins the connection to those addresses with `CURLOPT_RESOLVE`, so a second
     *    lookup at connect time cannot rebind the host somewhere private.
     * 4. Redirects are never followed.
     *
     * `allowPrivateAlertWebhookHosts` (config file only) skips 2 and 3; 1 and 4 still hold.
     *
     * @return array{host:string,port:int,addresses:string[]}|string the pinned target, or the refusal
     */
    public function webhookTarget(string $url): array|string
    {
        $parts = parse_url($url);
        $scheme = strtolower((string)(is_array($parts) ? ($parts['scheme'] ?? '') : ''));
        $host = (string)(is_array($parts) ? ($parts['host'] ?? '') : '');

        if (!is_array($parts) || !in_array($scheme, ['http', 'https'], true) || $host === '') {
            return Craft::t('vismaz', 'Only http:// and https:// webhook URLs are allowed.');
        }

        if (isset($parts['user']) || isset($parts['pass'])) {
            return Craft::t('vismaz', 'Webhook URLs may not carry a username or password.');
        }

        $port = (int)($parts['port'] ?? ($scheme === 'https' ? 443 : 80));

        if (Plugin::getInstance()->getSettings()->allowPrivateAlertWebhookHosts) {
            return ['host' => trim($host, '[]'), 'port' => $port, 'addresses' => []];
        }

        $addresses = Ip::resolvePublic($host);

        if ($addresses === []) {
            return Craft::t('vismaz', 'That host doesn’t resolve, or resolves to a private, loopback or link-local address. Alert webhooks only go to public addresses.');
        }

        return ['host' => trim($host, '[]'), 'port' => $port, 'addresses' => $addresses];
    }

    /**
     * POST the payload. True on a 2xx, otherwise the reason — never an exception.
     *
     * @param array<string,mixed> $payload
     */
    public function postWebhook(string $url, array $payload): bool|string
    {
        $target = $this->webhookTarget($url);

        if (is_string($target)) {
            return $target;
        }

        $body = (string)json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        $headers = ['Content-Type' => 'application/json', 'User-Agent' => 'Vismaz alerts'];
        $secret = trim((string)App::parseEnv(Plugin::getInstance()->getSettings()->alertWebhookSecret));

        if ($secret !== '' && !str_starts_with($secret, '$')) {
            $timestamp = (string)time();
            $headers['X-Vismaz-Timestamp'] = $timestamp;
            $headers['X-Vismaz-Signature'] = 'sha256=' . hash_hmac('sha256', $timestamp . '.' . $body, $secret);
        }

        $options = [
            'body' => $body,
            'headers' => $headers,
            'timeout' => 10,
            'connect_timeout' => 5,
            'allow_redirects' => false,
            'http_errors' => false,
        ];

        if ($target['addresses'] !== []) {
            // One entry per host:port, addresses comma-joined — one entry per address would leave
            // only the last one pinned.
            $options['curl'][CURLOPT_RESOLVE] = [sprintf(
                '%s:%d:%s',
                $target['host'],
                $target['port'],
                implode(',', array_map(static fn(string $ip) => str_contains($ip, ':') ? "[$ip]" : $ip, $target['addresses'])),
            )];
        }

        try {
            $client = $this->webhookClient ?? new Client(['handler' => HandlerStack::create(new CurlHandler())]);
            $response = $client->request('POST', $url, $options);
            $status = $response->getStatusCode();

            return $status >= 200 && $status < 300 ? true : 'HTTP ' . $status;
        } catch (Throwable $e) {
            // A Slack or Teams webhook URL is itself the credential; the reason ends up in logs.
            return str_replace($url, $target['host'], $e->getMessage());
        }
    }

    /**
     * Take anything secret out of a line that is about to leave the building.
     *
     * `Log` already redacts what it stores, but an alert quotes `lastError` strings that never
     * passed through it, and it goes somewhere — a mailbox, a chat channel — that outlives the
     * credential. So: the client secret and the current access and refresh tokens by value,
     * anything shaped like a credential by pattern, tags stripped, and a length cap so a stack
     * trace cannot ride along.
     */
    public function redact(string $text): string
    {
        $plugin = Plugin::getInstance();
        $secrets = [];

        try {
            $secrets[] = $plugin->getSettings()->getClientSecret();
            $secrets[] = (string)$plugin->getAuth()->getRefreshToken();
            $secrets[] = (string)$plugin->getAuth()->getStoredAccessToken();
        } catch (Throwable) {
            // A token row that cannot be decrypted still leaves the patterns below.
        }

        foreach ($secrets as $secret) {
            if (strlen($secret) >= 6) {
                $text = str_replace($secret, '••••', $text);
            }
        }

        $text = (string)preg_replace('/\b(Bearer|Basic|Token)\s+[A-Za-z0-9\-._~+\/=]{6,}/i', '$1 ••••', $text);
        $text = (string)preg_replace(
            '/(["\']?\b(?:password|passwd|pwd|secret|client_secret|api[_-]?key|apikey|access_token|refresh_token|token|signature|sig|code)\b["\']?\s*[:=]\s*["\']?)[^"\'&\s,;}]+/i',
            '$1••••',
            $text,
        );
        $text = trim((string)preg_replace('/\s+/', ' ', strip_tags($text)));

        return mb_strlen($text) > 500 ? mb_substr($text, 0, 499) . '…' : $text;
    }

    private function now(): string
    {
        return (string)Db::prepareDateForDb(new DateTime());
    }
}
