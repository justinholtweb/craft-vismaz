<?php

namespace justinholtweb\vismaz\jobs;

use Craft;
use craft\commerce\elements\Order;
use craft\queue\BaseJob;
use justinholtweb\vismaz\Plugin;
use justinholtweb\vismaz\services\Sync;
use Throwable;

/**
 * Push one order to Visma, off the request.
 *
 * Order completion queues this rather than pushing inline. A Visma outage, an expired refresh
 * token or a VIES timeout must never be able to stop a customer paying — and must never leave a
 * paid order unsaved because a third-party HTTP call threw inside the save.
 */
class PushDocumentJob extends BaseJob
{
    public int $orderId;

    /** `invoice` for the normal path, `refund` to raise a credit note. */
    public string $mode = 'invoice';

    public function execute($queue): void
    {
        $plugin = Plugin::getInstance();
        $order = Order::find()->id($this->orderId)->one();

        if (!$order instanceof Order) {
            return;
        }

        try {
            $result = $this->mode === 'refund'
                ? $plugin->getSync()->syncRefund($order)
                : $plugin->getSync()->syncOrder($order);

            // A failure is already recorded on the document row and in the log. Throwing here as
            // well would make Craft retry the job on its own schedule and race the retry command;
            // the document row is the single source of truth for what still needs doing.
            if ($result['status'] === Sync::STATUS_FAILED) {
                Craft::warning(sprintf(
                    'Vismaz could not sync order %d: %s',
                    $this->orderId,
                    $result['message'] ?? 'unknown error'
                ), 'vismaz');
            }
        } catch (Throwable $e) {
            $plugin->getLog()->error('job.push', $e->getMessage(), ['orderId' => $this->orderId]);
        }
    }

    protected function defaultDescription(): ?string
    {
        return Craft::t('vismaz', 'Sending order to Visma');
    }
}
