<?php

namespace justinholtweb\vismaz\controllers;

use Craft;
use craft\commerce\elements\Order;
use craft\db\Query;
use craft\helpers\AdminTable;
use craft\web\Controller;
use DateTimeImmutable;
use justinholtweb\vismaz\db\Table;
use justinholtweb\vismaz\models\Document;
use justinholtweb\vismaz\Plugin;
use Throwable;
use yii\web\NotFoundHttpException;
use yii\web\Response;

/**
 * The documents screen: what Vismaz has sent, what failed, and what would be sent.
 */
class DocumentsController extends Controller
{
    public function beforeAction($action): bool
    {
        if (!parent::beforeAction($action)) {
            return false;
        }

        $this->requirePermission('vismaz-viewDocuments');

        return true;
    }

    public function actionIndex(): Response
    {
        $plugin = Plugin::getInstance();
        $request = Craft::$app->getRequest();

        $status = $request->getParam('status');
        $type = $request->getParam('type');
        $page = max(1, (int)$request->getParam('page', 1));
        $limit = 50;

        $query = (new Query())
            ->from(Table::DOCUMENTS)
            ->orderBy(['dateCreated' => SORT_DESC, 'id' => SORT_DESC]);

        if ($status) {
            $query->andWhere(['status' => $status]);
        }

        if ($type) {
            $query->andWhere(['type' => $type]);
        }

        $total = (int)(clone $query)->count();
        $documents = $query->limit($limit)->offset(($page - 1) * $limit)->all();

        return $this->renderTemplate('vismaz/documents/_index', [
            'documents' => $documents,
            'total' => $total,
            'page' => $page,
            'pages' => (int)ceil($total / $limit),
            'status' => $status,
            'type' => $type,
            'connected' => $plugin->getAuth()->isConnected(),
        ]);
    }

    public function actionDetail(int $documentId): Response
    {
        $record = Plugin::getInstance()->getSync()->getRecordById($documentId);

        if ($record === null) {
            throw new NotFoundHttpException('Document not found.');
        }

        $orderIds = (new Query())
            ->select(['orderId'])
            ->from(Table::DOCUMENTORDERS)
            ->where(['documentId' => $documentId])
            ->column();

        return $this->renderTemplate('vismaz/documents/_detail', [
            'document' => $record,
            'orderIds' => $orderIds,
            'logEntries' => Plugin::getInstance()->getLog()->find(['documentId' => $documentId], 25),
        ]);
    }

    /**
     * Build a document without sending it.
     *
     * This runs the *same* builder the push runs, with remote resolution off so a preview never
     * writes anything to Visma. What is shown here is what would be posted.
     */
    public function actionPreview(): Response
    {
        $this->requireAcceptsJson();
        $this->requirePostRequest();

        $orderId = (int)Craft::$app->getRequest()->getRequiredBodyParam('orderId');
        $order = Order::find()->id($orderId)->one();

        if (!$order instanceof Order) {
            throw new NotFoundHttpException('Order not found.');
        }

        $plugin = Plugin::getInstance();

        try {
            $mode = Craft::$app->getRequest()->getBodyParam('mode', 'invoice');

            $document = match ($mode) {
                'refund' => $plugin->getDocuments()->buildCreditNote($order, false),
                default => $plugin->getDocuments()->buildInvoice($order, false),
            };

            if ($document === null) {
                return $this->asJson(['success' => false, 'message' => Craft::t('vismaz', 'Nothing has been refunded on this order.')]);
            }

            return $this->asJson([
                'success' => true,
                'sourceKey' => $document->sourceKey,
                'endpoint' => $document->getEndpoint(),
                'treatment' => $document->tax?->getLabel(),
                'reason' => $document->tax?->reason,
                'net' => $document->getNetTotal(),
                'vat' => $document->getVatTotal(),
                'gross' => $document->getGrossTotal(),
                'rounding' => $document->roundingAdjustment,
                'payload' => json_encode($document->toPayload(), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
            ]);
        } catch (Throwable $e) {
            return $this->asJson(['success' => false, 'message' => $e->getMessage()]);
        }
    }

    /**
     * Send an order now.
     */
    public function actionPush(): Response
    {
        $this->requirePermission('vismaz-pushDocuments');
        $this->requirePostRequest();

        $request = Craft::$app->getRequest();
        $orderId = (int)$request->getRequiredBodyParam('orderId');
        $mode = $request->getBodyParam('mode', 'invoice');

        $order = Order::find()->id($orderId)->one();

        if (!$order instanceof Order) {
            throw new NotFoundHttpException('Order not found.');
        }

        $plugin = Plugin::getInstance();

        try {
            $result = $mode === 'refund'
                ? $plugin->getSync()->syncRefund($order)
                : $plugin->getSync()->syncOrder($order);
        } catch (Throwable $e) {
            $result = ['status' => 'failed', 'document' => null, 'message' => $e->getMessage()];
        }

        $success = in_array($result['status'], ['sent', 'skipped'], true);
        $message = $result['message'] ?? Craft::t('vismaz', 'Sent to Visma.');

        if ($request->getAcceptsJson()) {
            return $this->asJson([
                'success' => $success,
                'status' => $result['status'],
                'message' => $message,
                'vismaNumber' => $result['document']->vismaNumber ?? null,
            ]);
        }

        $success
            ? Craft::$app->getSession()->setNotice($message)
            : Craft::$app->getSession()->setError($message);

        return $this->redirectToPostedUrl();
    }

    /**
     * Retry one failed document.
     */
    public function actionRetry(): Response
    {
        $this->requirePermission('vismaz-pushDocuments');
        $this->requirePostRequest();

        $documentId = (int)Craft::$app->getRequest()->getRequiredBodyParam('documentId');
        $plugin = Plugin::getInstance();
        $record = $plugin->getSync()->getRecordById($documentId);

        if ($record === null) {
            throw new NotFoundHttpException('Document not found.');
        }

        $document = $plugin->getSync()->rebuild($record);

        if ($document === null) {
            Craft::$app->getSession()->setError(Craft::t('vismaz', 'That document can no longer be rebuilt — its orders are gone or already accounted for.'));

            return $this->redirect('vismaz/documents/' . $documentId);
        }

        $result = $plugin->getSync()->push($document);

        $result['status'] === 'failed'
            ? Craft::$app->getSession()->setError($result['message'] ?? Craft::t('vismaz', 'Still failing.'))
            : Craft::$app->getSession()->setNotice(Craft::t('vismaz', 'Sent to Visma.'));

        return $this->redirect('vismaz/documents/' . $result['document']->id);
    }

    /**
     * Run a period's summary voucher on demand (Pro).
     */
    public function actionPushPeriod(): Response
    {
        $this->requirePermission('vismaz-pushDocuments');
        $this->requirePostRequest();

        $plugin = Plugin::getInstance();

        $request = Craft::$app->getRequest();
        $from = new DateTimeImmutable((string)$request->getRequiredBodyParam('from'));
        $to = new DateTimeImmutable((string)$request->getBodyParam('to') ?: $request->getRequiredBodyParam('from'));

        $result = $plugin->getSync()->syncPeriod($from, $to);

        $result['status'] === 'failed'
            ? Craft::$app->getSession()->setError($result['message'] ?? Craft::t('vismaz', 'The voucher could not be sent.'))
            : Craft::$app->getSession()->setNotice($result['message'] ?? Craft::t('vismaz', 'Voucher sent to Visma.'));

        return $this->redirect('vismaz/documents');
    }
}
