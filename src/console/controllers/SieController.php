<?php

namespace justinholtweb\vismaz\console\controllers;

use craft\console\Controller;
use craft\helpers\Console;
use craft\helpers\FileHelper;
use DateTimeImmutable;
use justinholtweb\vismaz\Plugin;
use Throwable;
use yii\console\ExitCode;

/**
 * `craft vismaz/sie/export --from=2026-08-01 --to=2026-08-31 --path=./aug.se`
 */
class SieController extends Controller
{
    public ?string $from = null;
    public ?string $to = null;

    /** Where to write. Defaults to the storage folder. */
    public ?string $path = null;

    /** Include orders already pushed to Visma. Off by default — exporting them too books twice. */
    public bool $includeSynced = false;

    public function options($actionID): array
    {
        return array_merge(parent::options($actionID), ['from', 'to', 'path', 'includeSynced']);
    }

    public function optionAliases(): array
    {
        return ['f' => 'from', 't' => 'to', 'p' => 'path'];
    }

    public function actionExport(): int
    {
        $plugin = Plugin::getInstance();

        $from = new DateTimeImmutable($this->from ?: 'first day of last month');
        $to = new DateTimeImmutable($this->to ?: 'last day of last month');

        try {
            $result = $plugin->getSie()->export($from, $to, $this->includeSynced);
        } catch (Throwable $e) {
            $this->stderr($e->getMessage() . "\n", Console::FG_RED);

            return ExitCode::UNSPECIFIED_ERROR;
        }

        if ($result['verifications'] === 0) {
            $this->stdout("No orders to export in that period.\n", Console::FG_GREY);

            return ExitCode::OK;
        }

        $path = $this->path ?: \Craft::$app->getPath()->getStoragePath() . DIRECTORY_SEPARATOR . $result['filename'];

        FileHelper::writeToFile($path, $result['contents']);

        $this->stdout(sprintf(
            "%d verification(s) from %d order(s) written to %s\n",
            $result['verifications'],
            $result['orders'],
            $path
        ), Console::FG_GREEN);

        return ExitCode::OK;
    }
}
