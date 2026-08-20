<?php

namespace justinholtweb\vismaz\console\controllers;

use craft\console\Controller;
use craft\helpers\Console;
use justinholtweb\vismaz\Plugin;
use yii\console\ExitCode;

/**
 * `craft vismaz/log/prune`
 */
class LogController extends Controller
{
    /** Override the retention setting for this run. */
    public ?int $days = null;

    public function options($actionID): array
    {
        return array_merge(parent::options($actionID), ['days']);
    }

    public function actionPrune(): int
    {
        $count = Plugin::getInstance()->getLog()->prune($this->days);

        $this->stdout(sprintf("%d log entries removed.\n", $count), Console::FG_GREEN);

        return ExitCode::OK;
    }
}
