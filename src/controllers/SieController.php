<?php

namespace justinholtweb\vismaz\controllers;

use Craft;
use craft\web\Controller;
use DateTimeImmutable;
use justinholtweb\vismaz\Plugin;
use Throwable;
use yii\web\ForbiddenHttpException;
use yii\web\Response;

/**
 * SIE 4 download.
 */
class SieController extends Controller
{
    public function beforeAction($action): bool
    {
        if (!parent::beforeAction($action)) {
            return false;
        }

        $this->requirePermission('vismaz-exportSie');

        if (!Plugin::getInstance()->isPro()) {
            throw new ForbiddenHttpException('SIE export needs Vismaz Pro.');
        }

        return true;
    }

    public function actionExport(): Response
    {
        $this->requirePostRequest();

        $request = Craft::$app->getRequest();
        $from = new DateTimeImmutable((string)$request->getRequiredBodyParam('from'));
        $to = new DateTimeImmutable((string)$request->getRequiredBodyParam('to'));
        $includeSynced = (bool)$request->getBodyParam('includeSynced', false);

        try {
            $result = Plugin::getInstance()->getSie()->export($from, $to, $includeSynced);
        } catch (Throwable $e) {
            Craft::$app->getSession()->setError($e->getMessage());

            return $this->redirect('vismaz/documents');
        }

        if ($result['verifications'] === 0) {
            Craft::$app->getSession()->setError(Craft::t('vismaz', 'No orders to export in that period.'));

            return $this->redirect('vismaz/documents');
        }

        // The file is already CP437 bytes; sending it as a string keeps it that way.
        return Craft::$app->getResponse()->sendContentAsFile(
            $result['contents'],
            $result['filename'],
            ['mimeType' => 'application/octet-stream']
        );
    }
}
