<?php

namespace justinholtweb\vismaz\controllers;

use Craft;
use craft\web\Controller;
use justinholtweb\vismaz\Plugin;
use yii\web\NotFoundHttpException;
use yii\web\Response;

/**
 * The connection log.
 */
class LogController extends Controller
{
    public function beforeAction($action): bool
    {
        if (!parent::beforeAction($action)) {
            return false;
        }

        $this->requirePermission('vismaz-viewLog');

        return true;
    }

    public function actionIndex(): Response
    {
        $request = Craft::$app->getRequest();
        $level = $request->getParam('level');
        $page = max(1, (int)$request->getParam('page', 1));
        $limit = 100;

        $log = Plugin::getInstance()->getLog();
        $criteria = array_filter(['level' => $level]);
        $total = $log->count($criteria);

        return $this->renderTemplate('vismaz/log/_index', [
            'entries' => $log->find($criteria, $limit, ($page - 1) * $limit),
            'total' => $total,
            'page' => $page,
            'pages' => (int)ceil($total / $limit),
            'level' => $level,
        ]);
    }

    public function actionDetail(int $entryId): Response
    {
        $entry = Plugin::getInstance()->getLog()->get($entryId);

        if ($entry === null) {
            throw new NotFoundHttpException('Log entry not found.');
        }

        return $this->renderTemplate('vismaz/log/_detail', ['entry' => $entry]);
    }

    public function actionClear(): Response
    {
        $this->requirePostRequest();
        $this->requireAdmin();

        $count = Plugin::getInstance()->getLog()->clear();
        Craft::$app->getSession()->setNotice(Craft::t('vismaz', '{count} log entries cleared.', ['count' => $count]));

        return $this->redirect('vismaz/log');
    }
}
