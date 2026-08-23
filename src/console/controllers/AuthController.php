<?php

namespace justinholtweb\vismaz\console\controllers;

use craft\console\Controller;
use craft\helpers\Console;
use justinholtweb\vismaz\Plugin;
use yii\console\ExitCode;

/**
 * `craft vismaz/auth/status` — is the connection alive, and to which company?
 */
class AuthController extends Controller
{
    public function actionStatus(): int
    {
        $plugin = Plugin::getInstance();
        $settings = $plugin->getSettings();

        $this->stdout('Environment:  ' . $settings->environment . "\n");
        $this->stdout('API:          ' . $settings->getApiBaseUrl() . "\n");
        $this->stdout('Mode:         ' . $settings->documentMode . "\n");

        if (!$plugin->getAuth()->isConfigured()) {
            $this->stdout("Status:       no client ID or secret configured\n", Console::FG_YELLOW);

            return ExitCode::CONFIG;
        }

        $connection = $plugin->getAuth()->getConnection();

        if ($connection === null) {
            $this->stdout("Status:       not connected\n", Console::FG_YELLOW);

            return ExitCode::CONFIG;
        }

        $this->stdout('Company:      ' . ($connection->companyName ?: '(unknown)') . "\n");
        $this->stdout('Org. no:      ' . ($connection->organisationNumber ?: '(unknown)') . "\n");
        $this->stdout('Token expires: ' . ($connection->expiresAt ?: '(unknown)') . "\n");

        $test = $plugin->getApi()->test();

        $this->stdout('Status:       ' . $test['message'] . "\n", $test['ok'] ? Console::FG_GREEN : Console::FG_RED);

        return $test['ok'] ? ExitCode::OK : ExitCode::UNSPECIFIED_ERROR;
    }

    /**
     * Force a token refresh.
     */
    public function actionRefresh(): int
    {
        $record = Plugin::getInstance()->getAuth()->refresh();

        if ($record === null) {
            $this->stderr("Could not refresh the Visma token.\n", Console::FG_RED);

            return ExitCode::UNSPECIFIED_ERROR;
        }

        $this->stdout('Refreshed; expires ' . $record->expiresAt . "\n", Console::FG_GREEN);

        return ExitCode::OK;
    }
}
