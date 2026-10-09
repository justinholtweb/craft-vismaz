<?php

namespace justinholtweb\vismaz\controllers;

use Craft;
use craft\commerce\elements\Order;
use craft\web\Controller;
use justinholtweb\vismaz\Plugin;
use Throwable;
use yii\web\NotFoundHttpException;
use yii\web\Response;

/**
 * Registering an order's payments against its Visma invoice on demand — the order panel's button
 * and the log's re-run. The work is `Payments::registerForOrder()`; a payment already in Visma is
 * skipped, so pressing this twice registers nothing twice.
 */
class PaymentsController extends Controller
{
    public function beforeAction($action): bool
    {
        if (!parent::beforeAction($action)) {
            return false;
        }

        $this->requirePermission('vismaz-pushDocuments');

        return true;
    }

    public function actionRegister(): Response
    {
        $this->requirePostRequest();

        $request = Craft::$app->getRequest();
        $orderId = (int)$request->getRequiredBodyParam('orderId');
        $order = Order::find()->id($orderId)->status(null)->one();

        if (!$order instanceof Order) {
            throw new NotFoundHttpException('Order not found.');
        }

        try {
            $summary = Plugin::getInstance()->getPayments()->registerForOrder($order);
            $total = $summary['sent'] + $summary['waiting'] + $summary['failed'] + $summary['skipped'];
            $success = $summary['failed'] === 0;
            $message = $total === 0
                ? Craft::t('vismaz', 'This order has no payments to register.')
                : Craft::t('vismaz', '{sent} registered, {skipped} already in Visma, {waiting} waiting for the invoice, {failed} failed.', array_diff_key($summary, ['messages' => true]));

            if ($summary['messages']) {
                $message .= ' ' . implode(' ', array_unique($summary['messages']));
            }
        } catch (Throwable $e) {
            $success = false;
            $message = $e->getMessage();
        }

        if ($request->getAcceptsJson()) {
            return $this->asJson(['success' => $success, 'message' => $message]);
        }

        $success
            ? Craft::$app->getSession()->setNotice($message)
            : Craft::$app->getSession()->setError($message);

        return $this->redirectToPostedUrl();
    }
}
