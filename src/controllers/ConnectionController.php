<?php

namespace justinholtweb\vismaz\controllers;

use craft\web\Controller;
use justinholtweb\vismaz\Plugin;
use yii\web\Response;

/**
 * The Connection screen: connect, test and disconnect Visma outside the plugin settings page.
 *
 * Plugin settings are project config — admin-only, and read-only wherever `allowAdminChanges` is
 * off. The connection is not: its token is stored in the database. So connecting gets a screen of
 * its own that works in production, behind its own permission.
 */
class ConnectionController extends Controller
{
    public function actionIndex(): Response
    {
        $this->requirePermission(Plugin::PERMISSION_MANAGE_CONNECTION);

        $plugin = Plugin::getInstance();

        return $this->renderTemplate('vismaz/connection/index', [
            'settings' => $plugin->getSettings(),
            'auth' => $plugin->getAuth(),
            'connection' => $plugin->getAuth()->getConnection(),
        ]);
    }
}
