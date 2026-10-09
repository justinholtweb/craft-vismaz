<?php

namespace justinholtweb\vismaz\elements\actions;

use Craft;
use craft\base\ElementAction;
use craft\commerce\elements\Order;
use craft\elements\db\ElementQueryInterface;
use craft\helpers\Queue;
use justinholtweb\vismaz\jobs\PushDocumentJob;
use justinholtweb\vismaz\Plugin;

/**
 * "Send to Visma" on the Orders index: queue a push for every selected, completed order.
 *
 * Always through the queue — a hundred orders inline is a request that times out halfway. The job
 * runs the same `Sync::syncOrder()` as the order screen's button, so the sync-status rules still
 * apply, and a document already in Visma (sent *or* mismatched) is reported, never posted again:
 * selecting an order that is already there is harmless. A failed one is rebuilt from the order as
 * it is now and retried.
 */
class SendToVisma extends ElementAction
{
    /**
     * @inheritdoc
     */
    public function getTriggerLabel(): string
    {
        return Craft::t('vismaz', 'Send to Visma');
    }

    /**
     * @inheritdoc
     */
    public function performAction(ElementQueryInterface $query): bool
    {
        // The action is only offered to people who may send, but the request can be made by
        // anyone who can see the index.
        if (!Craft::$app->getUser()->checkPermission('vismaz-pushDocuments')) {
            $this->setMessage(Craft::t('vismaz', 'You are not allowed to send orders to Visma.'));

            return false;
        }

        if (!Plugin::getInstance()->getAuth()->isConnected()) {
            $this->setMessage(Craft::t('vismaz', 'Vismaz is not connected to Visma.'));

            return false;
        }

        $ids = (clone $query)->status(null)->ids();
        // A cart is not a sale; there is nothing to invoice.
        $completed = $ids === [] ? [] : Order::find()->id($ids)->status(null)->isCompleted(true)->ids();

        foreach ($completed as $id) {
            Queue::push(new PushDocumentJob(['orderId' => (int)$id]));
        }

        $queued = count($completed);
        $skipped = count($ids) - $queued;

        $this->setMessage($skipped > 0
            ? Craft::t('vismaz', '{queued} orders queued for Visma; {skipped} incomplete orders skipped.', ['queued' => $queued, 'skipped' => $skipped])
            : Craft::t('vismaz', '{queued} orders queued for Visma.', ['queued' => $queued]));

        return $queued > 0;
    }
}
