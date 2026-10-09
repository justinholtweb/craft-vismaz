<?php

namespace justinholtweb\vismaz\services;

use Craft;
use craft\commerce\elements\Order;
use craft\commerce\models\Transaction;
use craft\commerce\Plugin as Commerce;
use craft\commerce\records\Transaction as TransactionRecord;
use craft\db\Query;
use craft\helpers\Db;
use craft\helpers\StringHelper;
use DateTime;
use DateTimeZone;
use justinholtweb\vismaz\db\Table;
use justinholtweb\vismaz\errors\ApiException;
use justinholtweb\vismaz\helpers\Bas;
use justinholtweb\vismaz\helpers\Money;
use justinholtweb\vismaz\jobs\RegisterPaymentJob;
use justinholtweb\vismaz\models\Document;
use justinholtweb\vismaz\models\Settings;
use justinholtweb\vismaz\Plugin;
use justinholtweb\vismaz\records\PaymentRecord;
use Throwable;
use yii\base\Component;
use yii\db\IntegrityException;

/**
 * Registering Commerce payments against their Visma invoices.
 *
 * In invoice mode an order becomes a `CustomerInvoice`, and an invoice nobody marks paid sits in
 * Visma's receivables for ever — Visma's own reminders then chase customers who paid at checkout.
 * So each successful capture or purchase is registered against the invoice with
 * `POST /v2/customerinvoices/{invoiceId}/payments`.
 *
 * Endpoint and body (`InvoicePaymentApi`) read from Visma's published OpenAPI document, not
 * guessed: https://eaccountingapi.vismaonline.com/openapi/v2.json — browsable at
 * https://eaccountingapi.vismaonline.com/scalar/v2 under *CustomerInvoices → Post a customer
 * invoice payment*. `CompanyBankAccountId`, `PaymentDate`, `PaymentAmount`, `PaymentCurrency` and
 * `PaymentType` are required; the amount is in the invoice currency; the date may not be in the
 * future and is read in the company's time zone.
 *
 * **`register()` is the only place a payment row is written**, and one Commerce transaction is
 * one row: the unique index on `transactionId`, a mutex around the call and the sent check
 * together mean a transaction reaches Visma at most once, however many times the queue, the order
 * panel and the console ask.
 *
 * A payment that arrives before its invoice exists — the usual case, because the first
 * transaction is what completes the order — is parked as `waiting`, and the invoice push queues
 * it the moment the invoice is in Visma.
 */
class Payments extends Component
{
    public const STATUS_PENDING = 'pending';
    public const STATUS_WAITING = 'waiting';
    public const STATUS_SENT = 'sent';
    public const STATUS_FAILED = 'failed';
    public const STATUS_SKIPPED = 'skipped';

    /** Visma's `PaymentType` values. */
    public const TYPE_PARTIAL = 1;
    public const TYPE_COMPLETE = 2;

    /**
     * How far a payment may exceed what Visma has outstanding before it is refused as an
     * overpayment. Half a krona: an invoice settled to whole kronor can be up to 0.50 under the
     * order total, and the customer paid the order total.
     */
    public const ROUNDING_TOLERANCE = 0.51;

    private const MUTEX_TIMEOUT = 15;
    private const BANK_ACCOUNT_TTL = 300;

    /** @var array<int, array<string, mixed>>|null */
    private ?array $bankAccounts = null;
    private int $bankAccountsFetchedAt = 0;

    /**
     * Whether a transaction is money received: a successful capture or purchase. An authorization
     * is a promise, not a payment, and refunds are credit notes' business.
     */
    public function isPayment(Transaction $transaction): bool
    {
        return $transaction->status === TransactionRecord::STATUS_SUCCESS
            && in_array($transaction->type, [TransactionRecord::TYPE_CAPTURE, TransactionRecord::TYPE_PURCHASE], true);
    }

    /**
     * An order's payments, oldest first.
     *
     * @return Transaction[]
     */
    public function getPaymentTransactions(Order $order): array
    {
        $transactions = array_values(array_filter(
            Commerce::getInstance()->getTransactions()->getAllTransactionsByOrderId((int)$order->id),
            fn(Transaction $t): bool => $this->isPayment($t)
        ));

        usort($transactions, static fn(Transaction $a, Transaction $b): int => $a->id <=> $b->id);

        return $transactions;
    }

    /**
     * Queue a transaction's registration, if it is one Vismaz should register now. Called when
     * Commerce saves a transaction; never touches the network, so it cannot slow a checkout.
     */
    public function queueTransaction(Transaction $transaction): bool
    {
        $plugin = Plugin::getInstance();
        $settings = $plugin->getSettings();

        if (
            !$transaction->id
            || !$this->isPayment($transaction)
            || !$settings->syncPayments
            || $settings->documentMode !== Settings::MODE_INVOICE
            || !$plugin->getAuth()->isConnected()
        ) {
            return false;
        }

        Craft::$app->getQueue()->push(new RegisterPaymentJob(['transactionId' => (int)$transaction->id]));

        return true;
    }

    /**
     * Queue every payment on an order that is not yet in Visma. The invoice push calls this once
     * the invoice exists, which is what picks up payments made before it did.
     */
    public function queueForOrder(int $orderId): int
    {
        $settings = Plugin::getInstance()->getSettings();

        if (!$settings->syncPayments || $settings->documentMode !== Settings::MODE_INVOICE) {
            return 0;
        }

        $order = Order::find()->id($orderId)->status(null)->one();

        if (!$order instanceof Order) {
            return 0;
        }

        $sent = $this->sentTransactionIds($orderId);
        $queued = 0;

        foreach ($this->getPaymentTransactions($order) as $transaction) {
            if (in_array((int)$transaction->id, $sent, true)) {
                continue;
            }

            Craft::$app->getQueue()->push(new RegisterPaymentJob(['transactionId' => (int)$transaction->id]));
            $queued++;
        }

        return $queued;
    }

    /**
     * Register every payment on an order now — the order panel's button and the log's re-run.
     * Payments already in Visma are reported as skipped, not sent again.
     *
     * @return array{sent: int, waiting: int, failed: int, skipped: int, messages: string[]}
     */
    public function registerForOrder(Order $order): array
    {
        $summary = ['sent' => 0, 'waiting' => 0, 'failed' => 0, 'skipped' => 0, 'messages' => []];

        foreach ($this->getPaymentTransactions($order) as $transaction) {
            $result = $this->register($transaction);
            $key = match ($result['status']) {
                self::STATUS_SENT => 'sent',
                self::STATUS_WAITING => 'waiting',
                self::STATUS_FAILED => 'failed',
                default => 'skipped',
            };
            $summary[$key]++;

            if ($result['status'] === self::STATUS_FAILED && $result['message']) {
                $summary['messages'][] = $result['message'];
            }
        }

        return $summary;
    }

    /**
     * Register one Commerce transaction against its order's Visma invoice.
     *
     * `retryable` says whether trying again later could succeed (Visma unreachable, a 5xx) as
     * opposed to a refusal or a configuration gap that will answer the same way every time.
     *
     * @return array{status: string, payment: ?PaymentRecord, message: ?string, retryable: bool}
     */
    public function register(Transaction $transaction): array
    {
        $plugin = Plugin::getInstance();
        $settings = $plugin->getSettings();

        if (!$transaction->id || !$this->isPayment($transaction)) {
            return self::result(self::STATUS_SKIPPED, null, Craft::t('vismaz', 'Only a successful capture or purchase is a payment.'));
        }

        if ($settings->documentMode !== Settings::MODE_INVOICE) {
            return self::result(self::STATUS_SKIPPED, null, Craft::t('vismaz', 'Voucher mode books the settlement in the summary voucher; there is no invoice to pay.'));
        }

        if (!$settings->syncPayments) {
            return self::result(self::STATUS_SKIPPED, null, Craft::t('vismaz', 'Payment registration is off.'));
        }

        $order = $transaction->getOrder();

        if (!$order instanceof Order || !$order->id) {
            return self::result(self::STATUS_SKIPPED, null, Craft::t('vismaz', 'The transaction has no order.'));
        }

        $mutex = Craft::$app->getMutex();
        $lock = 'vismaz:payment:' . $transaction->id;

        if (!$mutex->acquire($lock, self::MUTEX_TIMEOUT)) {
            return self::result(self::STATUS_SKIPPED, null, Craft::t('vismaz', 'That payment is being registered by another process.'));
        }

        try {
            $record = $this->claim($transaction, $order);

            if ($record->status === self::STATUS_SENT) {
                return self::result(self::STATUS_SKIPPED, $record, Craft::t('vismaz', 'Already registered in Visma.'));
            }

            $invoice = $plugin->getSync()->findRecord(Document::TYPE_INVOICE, Documents::invoiceKey($order));

            // A mismatched invoice is still in Visma, and the money was still received.
            if (
                $invoice === null
                || !in_array($invoice->status, [Sync::STATUS_SENT, Sync::STATUS_MISMATCHED], true)
                || !$invoice->vismaId
            ) {
                $record->status = self::STATUS_WAITING;
                $record->lastError = null;
                $record->save(false);

                $message = Craft::t('vismaz', 'Payment received before the invoice is in Visma; it will be registered once the invoice is sent.');
                $plugin->getLog()->write('payment.waiting', ['message' => $message, 'orderId' => $order->id]);

                return self::result(self::STATUS_WAITING, $record, $message);
            }

            $record->documentId = $invoice->id;
            [$amount, $currency] = self::amountOf($transaction);

            if ($invoice->currency && strtoupper($currency) !== strtoupper((string)$invoice->currency)) {
                return $this->fail($record, Craft::t('vismaz', 'Paid in {paid} but invoiced in {invoiced}; Visma takes a payment in the invoice currency.', [
                    'paid' => $currency,
                    'invoiced' => $invoice->currency,
                ]), false);
            }

            $gatewayHandle = $record->gatewayHandle;
            $bankAccountId = $settings->getPaymentBankAccountId($gatewayHandle);

            if ($bankAccountId === null) {
                return $this->fail($record, Craft::t('vismaz', 'No Visma bank account is mapped for the “{gateway}” gateway, and there is no default. Set one under Payment method accounts in the Vismaz settings.', [
                    'gateway' => $gatewayHandle ?: '?',
                ]), false);
            }

            $record->attempts = (int)$record->attempts + 1;
            $record->status = self::STATUS_PENDING;
            $record->save(false);

            $api = $plugin->getApi();
            $context = ['documentId' => $invoice->id, 'orderId' => $order->id];

            try {
                $configured = $bankAccountId;
                $bankAccountId = $this->resolveBankAccountId($configured);

                if ($bankAccountId === null) {
                    return $this->fail($record, Craft::t('vismaz', '“{value}” is not a bank account in Visma. Give its Visma ID, or the ledger account number of an active bank account there — `craft vismaz/sync/bank-accounts` lists them.', [
                        'value' => $configured,
                    ]), false);
                }

                $record->bankAccountId = $bankAccountId;

                // Ask Visma what is still open first. This is the guard against the one way the
                // claim above cannot stop a double registration: a POST that reached Visma but
                // whose answer never came back, so the row says failed and a retry follows.
                $current = $api->request('GET', 'customerinvoices/' . rawurlencode((string)$invoice->vismaId), null, [], $context);
                $remaining = is_array($current) && is_numeric($current['RemainingAmountInvoiceCurrency'] ?? null)
                    ? (float)$current['RemainingAmountInvoiceCurrency']
                    : null;

                if ($remaining !== null && $amount - $remaining > self::ROUNDING_TOLERANCE) {
                    return $this->fail($record, Craft::t('vismaz', 'Visma shows {remaining} outstanding on invoice {number}; registering {amount} would overpay it. Check whether this payment is already in Visma.', [
                        'remaining' => Money::format($remaining, $currency),
                        'number' => $invoice->vismaNumber ?: $invoice->vismaId,
                        'amount' => Money::format($amount, $currency),
                    ]), false);
                }

                $payload = $this->buildPayload($transaction, $order, $bankAccountId, $remaining);

                $record->payload = json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
                $record->paymentType = $payload['PaymentType'];
                $record->reference = $payload['Reference'];
                $record->save(false);

                $response = $api->request('POST', 'customerinvoices/' . rawurlencode((string)$invoice->vismaId) . '/payments', $payload, [], $context);
            } catch (ApiException $e) {
                return $this->fail($record, $e->getMessage(), $e->isTransient());
            } catch (Throwable $e) {
                return $this->fail($record, $e->getMessage(), true);
            }

            // Visma answers with the payment it booked. It carries no id of its own; the bank
            // transaction it matched, when there is one, is the closest thing, and otherwise the
            // Reference sent is what finds it in Visma.
            $bankTransactionId = is_array($response) ? ($response['BankTransactionId'] ?? null) : null;

            $record->status = self::STATUS_SENT;
            $record->vismaReference = is_string($bankTransactionId) && $bankTransactionId !== ''
                ? $bankTransactionId
                : $payload['Reference'];
            $record->response = json_encode($response, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
            $record->lastError = null;
            $record->dateSent = Db::prepareDateForDb(new DateTime());
            $record->save(false);

            $plugin->getLog()->write('payment.register', [
                'message' => Craft::t('vismaz', 'Registered {amount} against invoice {number}.', [
                    'amount' => Money::format($amount, $currency),
                    'number' => $invoice->vismaNumber ?: $invoice->vismaId,
                ]),
            ] + $context);

            return self::result(self::STATUS_SENT, $record, null);
        } finally {
            $mutex->release($lock);
        }
    }

    /**
     * The `InvoicePaymentApi` body for a transaction.
     *
     * The payment is *complete* when it settles the order in Commerce's eyes — everything paid up
     * to and including it covers the order total — or covers what Visma still has open. Anything
     * else is partial and leaves the rest of the invoice open for the next payment.
     */
    public function buildPayload(Transaction $transaction, Order $order, string $bankAccountId, ?float $remaining = null): array
    {
        [$amount, $currency] = self::amountOf($transaction);

        $paidThrough = 0;

        foreach ($this->getPaymentTransactions($order) as $payment) {
            if ($payment->id <= $transaction->id) {
                $paidThrough += Money::toMinor((float)$payment->amount);
            }
        }

        $complete = $paidThrough >= Money::toMinor((float)$order->getTotalPrice())
            || ($remaining !== null && Money::toMinor($amount) >= Money::toMinor($remaining));

        return [
            'CompanyBankAccountId' => $bankAccountId,
            'PaymentDate' => self::paymentDate($transaction),
            'PaymentAmount' => round($amount, 2),
            'PaymentCurrency' => strtoupper($currency),
            'PaymentType' => $complete ? self::TYPE_COMPLETE : self::TYPE_PARTIAL,
            'Reference' => self::referenceFor($transaction, $order),
        ];
    }

    /**
     * A configured bank account as the GUID Visma's payment endpoint wants.
     *
     * Merchants know their bank account as `1930`, not as a GUID Visma's own screens never show,
     * so a ledger account number is accepted and looked up among Visma's active bank accounts.
     * Null when it matches none.
     *
     * @throws ApiException when Visma cannot be asked.
     */
    public function resolveBankAccountId(string $value): ?string
    {
        $value = trim($value);

        if (StringHelper::isUUID($value)) {
            return $value;
        }

        if (!Bas::isValidAccount($value)) {
            return null;
        }

        foreach ($this->getBankAccounts() as $account) {
            if (
                is_array($account)
                && (string)($account['LedgerAccountNumber'] ?? '') === $value
                && ($account['IsActive'] ?? true) !== false
                && !empty($account['Id'])
            ) {
                return (string)$account['Id'];
            }
        }

        return null;
    }

    /**
     * Visma's bank account register, held for a few minutes — a busy afternoon's payments should
     * not each ask for it, but an account added in Visma should not take a restart to appear.
     *
     * @return array<int, array<string, mixed>>
     */
    public function getBankAccounts(bool $refresh = false): array
    {
        if ($refresh || $this->bankAccounts === null || time() - $this->bankAccountsFetchedAt > self::BANK_ACCOUNT_TTL) {
            $this->bankAccounts = Plugin::getInstance()->getApi()->getAll('bankaccounts');
            $this->bankAccountsFetchedAt = time();
        }

        return $this->bankAccounts;
    }

    /**
     * Retry payments that failed or are still waiting for their invoice.
     *
     * @return array{attempted: int, sent: int, waiting: int, failed: int}
     */
    public function retryUnsent(int $limit = 100): array
    {
        $result = ['attempted' => 0, 'sent' => 0, 'waiting' => 0, 'failed' => 0];
        $transactions = Commerce::getInstance()->getTransactions();

        $ids = (new Query())
            ->select(['transactionId'])
            ->from(Table::PAYMENTS)
            ->where(['status' => [self::STATUS_FAILED, self::STATUS_WAITING, self::STATUS_PENDING]])
            ->orderBy(['dateCreated' => SORT_ASC])
            ->limit($limit)
            ->column();

        foreach ($ids as $id) {
            $transaction = $transactions->getTransactionById((int)$id);

            if ($transaction === null) {
                continue;
            }

            $result['attempted']++;
            $outcome = $this->register($transaction);

            match ($outcome['status']) {
                self::STATUS_SENT, self::STATUS_SKIPPED => $result['sent']++,
                self::STATUS_WAITING => $result['waiting']++,
                default => $result['failed']++,
            };
        }

        return $result;
    }

    /**
     * Everything Vismaz has done with an order's payments.
     *
     * @return array<int, array<string, mixed>>
     */
    public function getPaymentsForOrder(int $orderId): array
    {
        return (new Query())
            ->from(Table::PAYMENTS)
            ->where(['orderId' => $orderId])
            ->orderBy(['transactionId' => SORT_ASC])
            ->all();
    }

    /**
     * Payments registered against one invoice document.
     *
     * @return array<int, array<string, mixed>>
     */
    public function getPaymentsForDocument(int $documentId): array
    {
        return (new Query())
            ->from(Table::PAYMENTS)
            ->where(['documentId' => $documentId])
            ->orderBy(['transactionId' => SORT_ASC])
            ->all();
    }

    public function findByTransactionId(int $transactionId): ?PaymentRecord
    {
        /** @var PaymentRecord|null $record */
        $record = PaymentRecord::find()->where(['transactionId' => $transactionId])->one();

        return $record;
    }

    /**
     * Find or claim the row for a transaction — the same race-safe shape as `Sync::record()`.
     */
    private function claim(Transaction $transaction, Order $order): PaymentRecord
    {
        $record = $this->findByTransactionId((int)$transaction->id);

        if ($record === null) {
            $record = new PaymentRecord([
                'transactionId' => (int)$transaction->id,
                'orderId' => (int)$order->id,
                'status' => self::STATUS_PENDING,
            ]);

            try {
                $this->hydrate($record, $transaction);
                $record->save(false);
            } catch (IntegrityException) {
                // Lost the race. The winner's row is the real one.
                $record = $this->findByTransactionId((int)$transaction->id)
                    ?? throw new \RuntimeException('Could not claim a Vismaz payment row.');
            }

            return $record;
        }

        if ($record->status !== self::STATUS_SENT) {
            $this->hydrate($record, $transaction);
            $record->save(false);
        }

        return $record;
    }

    private function hydrate(PaymentRecord $record, Transaction $transaction): void
    {
        [$amount, $currency] = self::amountOf($transaction);

        $record->amount = $amount;
        $record->currency = strtoupper($currency);
        $record->paymentDate = self::paymentDate($transaction);

        try {
            $record->gatewayHandle = $transaction->getGateway()?->handle;
        } catch (Throwable) {
            $record->gatewayHandle = null;
        }
    }

    /**
     * @return array{status: string, payment: ?PaymentRecord, message: ?string, retryable: bool}
     */
    private function fail(PaymentRecord $record, string $message, bool $retryable): array
    {
        $record->status = self::STATUS_FAILED;
        $record->lastError = $message;
        $record->save(false);

        Plugin::getInstance()->getLog()->error('payment.register', $message, [
            'documentId' => $record->documentId,
            'orderId' => $record->orderId,
        ]);

        return self::result(self::STATUS_FAILED, $record, $message, $retryable);
    }

    /**
     * @return array{status: string, payment: ?PaymentRecord, message: ?string, retryable: bool}
     */
    private static function result(string $status, ?PaymentRecord $record, ?string $message, bool $retryable = false): array
    {
        return ['status' => $status, 'payment' => $record, 'message' => $message, 'retryable' => $retryable];
    }

    /**
     * Amount and currency as the customer paid them. The invoice is raised in the order's payment
     * currency, so that is the currency Visma expects the payment in.
     *
     * @return array{0: float, 1: string}
     */
    public static function amountOf(Transaction $transaction): array
    {
        if ($transaction->paymentCurrency) {
            return [(float)$transaction->paymentAmount, (string)$transaction->paymentCurrency];
        }

        return [(float)$transaction->amount, (string)($transaction->currency ?: 'SEK')];
    }

    /**
     * The transaction's date, as a calendar date in the site's time zone. Visma reads the date in
     * the company's time zone and refuses future dates, so a UTC date late in the evening would
     * land on the wrong day — or tomorrow.
     */
    public static function paymentDate(Transaction $transaction): string
    {
        $date = $transaction->dateCreated ? clone $transaction->dateCreated : new DateTime();

        return $date->setTimezone(new DateTimeZone(Craft::$app->getTimeZone()))->format('Y-m-d');
    }

    /**
     * The payment's Reference in Visma: the order and the gateway's own reference, so a
     * bookkeeper can find the transaction from either side. Visma caps it at 100 characters.
     */
    public static function referenceFor(Transaction $transaction, Order $order): string
    {
        $orderRef = (string)($order->reference ?: substr((string)$order->number, 0, 7));
        $paymentRef = (string)($transaction->reference ?: $transaction->hash);

        // Not translated: it is data in the merchant's books, and should not change language with
        // whichever user happened to run the queue.
        return mb_substr(trim('Order ' . $orderRef . ' / ' . $paymentRef), 0, 100);
    }

    /**
     * @return int[]
     */
    private function sentTransactionIds(int $orderId): array
    {
        return array_map('intval', (new Query())
            ->select(['transactionId'])
            ->from(Table::PAYMENTS)
            ->where(['orderId' => $orderId, 'status' => self::STATUS_SENT])
            ->column());
    }
}
