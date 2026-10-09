<?php

namespace justinholtweb\vismaz\jobs;

use Craft;
use craft\commerce\Plugin as Commerce;
use craft\queue\BaseJob;
use justinholtweb\vismaz\Plugin;
use justinholtweb\vismaz\services\Payments;
use yii\base\Exception;
use yii\queue\RetryableJobInterface;

/**
 * Register one Commerce payment against its Visma invoice, off the request.
 *
 * Unlike `PushDocumentJob`, this one lets the queue retry: a payment row is claimed under a mutex
 * and skipped once sent, so a retry racing the order panel or the console cannot register it
 * twice. Only failures that could go differently next time are thrown for a retry — Visma
 * unreachable or a 5xx. A refusal or a missing bank-account mapping is recorded on the row and in
 * the log, and left for a person.
 */
class RegisterPaymentJob extends BaseJob implements RetryableJobInterface
{
    public const MAX_ATTEMPTS = 5;

    public int $transactionId;

    public function execute($queue): void
    {
        $transaction = Commerce::getInstance()->getTransactions()->getTransactionById($this->transactionId);

        if ($transaction === null) {
            return;
        }

        $result = Plugin::getInstance()->getPayments()->register($transaction);

        if ($result['status'] === Payments::STATUS_FAILED && $result['retryable']) {
            throw new Exception(sprintf(
                'Vismaz could not register transaction %d: %s',
                $this->transactionId,
                $result['message'] ?? 'unknown error'
            ));
        }
    }

    public function getTtr(): int
    {
        // Two requests, each with Api's own 429 waits (up to two minutes apiece).
        return 600;
    }

    public function canRetry($attempt, $error): bool
    {
        return $attempt < self::MAX_ATTEMPTS;
    }

    protected function defaultDescription(): ?string
    {
        return Craft::t('vismaz', 'Registering a payment in Visma');
    }
}
