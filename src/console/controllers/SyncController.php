<?php

namespace justinholtweb\vismaz\console\controllers;

use craft\commerce\elements\Order;
use craft\console\Controller;
use craft\helpers\Console;
use DateTimeImmutable;
use justinholtweb\vismaz\helpers\Money;
use justinholtweb\vismaz\models\Settings;
use justinholtweb\vismaz\Plugin;
use Throwable;
use yii\console\ExitCode;

/**
 * `craft vismaz/sync/...`
 *
 * Craft lists plugin commands under `craft help`, not `craft help vismaz`, and they run as
 * `vismaz/sync/orders`.
 */
class SyncController extends Controller
{
    /** Do not send anything; print what would be sent. */
    public bool $dryRun = false;

    /** Start of the range, `Y-m-d`. Defaults to yesterday. */
    public ?string $from = null;

    /** End of the range, `Y-m-d`. Defaults to `from`. */
    public ?string $to = null;

    /** Maximum orders to process. */
    public int $limit = 500;

    public function options($actionID): array
    {
        return array_merge(parent::options($actionID), match ($actionID) {
            'orders' => ['dryRun', 'from', 'to', 'limit'],
            'voucher' => ['dryRun', 'from', 'to'],
            'retry' => ['limit'],
            default => [],
        });
    }

    public function optionAliases(): array
    {
        return ['d' => 'dryRun', 'f' => 'from', 't' => 'to', 'l' => 'limit'];
    }

    /**
     * Sync completed orders that are not yet in Visma.
     */
    public function actionOrders(): int
    {
        $plugin = Plugin::getInstance();

        if (!$this->guard()) {
            return ExitCode::CONFIG;
        }

        [$from, $to] = $this->range();
        $orders = $plugin->getSync()->findUnsyncedOrders($from, $to, $this->limit);
        $orders = array_values(array_filter($orders, fn(Order $o): bool => $plugin->getSync()->isEligible($o)));

        if ($orders === []) {
            $this->stdout("Nothing to sync.\n", Console::FG_GREY);

            return ExitCode::OK;
        }

        $this->stdout(sprintf("%d order(s) to sync.\n\n", count($orders)));

        if ($plugin->getSettings()->documentMode === Settings::MODE_VOUCHER) {
            $this->stdout("Voucher mode — run vismaz/sync/voucher instead.\n", Console::FG_YELLOW);

            return ExitCode::OK;
        }

        $sent = $failed = $skipped = 0;

        foreach ($orders as $order) {
            $label = sprintf('  %-14s ', $order->reference ?: ('#' . $order->id));

            try {
                if ($this->dryRun) {
                    // The same builder the push uses, with remote resolution off — so a dry run
                    // against production creates nothing.
                    $document = $plugin->getDocuments()->buildInvoice($order, false);

                    $this->stdout($label);
                    $this->stdout(sprintf(
                        "%s  net %s  vat %s  gross %s\n",
                        str_pad((string)$document->tax?->getLabel(), 22),
                        Money::format($document->getNetTotal(), $document->currency),
                        Money::format($document->getVatTotal(), $document->currency),
                        Money::format($document->getGrossTotal(), $document->currency),
                    ), Console::FG_GREY);

                    $skipped++;
                    continue;
                }

                $result = $plugin->getSync()->syncOrder($order);

                match ($result['status']) {
                    'sent' => [$sent++, $this->stdout($label . "sent\n", Console::FG_GREEN)],
                    'skipped' => [$skipped++, $this->stdout($label . ($result['message'] ?? "skipped") . "\n", Console::FG_GREY)],
                    default => [$failed++, $this->stdout($label . ($result['message'] ?? 'failed') . "\n", Console::FG_RED)],
                };
            } catch (Throwable $e) {
                $failed++;
                $this->stdout($label . $e->getMessage() . "\n", Console::FG_RED);
            }
        }

        $this->stdout(sprintf("\n%d sent, %d skipped, %d failed.\n", $sent, $skipped, $failed));

        return $failed === 0 ? ExitCode::OK : ExitCode::UNSPECIFIED_ERROR;
    }

    /**
     * Build and send the summary voucher for a period (Pro).
     */
    public function actionVoucher(): int
    {
        $plugin = Plugin::getInstance();

        if (!$this->guard()) {
            return ExitCode::CONFIG;
        }

        [$from, $to] = $this->range();

        if ($this->dryRun) {
            $orders = array_values(array_filter(
                $plugin->getSync()->findUnsyncedOrders($from, $to->setTime(23, 59, 59), 5000),
                fn(Order $o): bool => $plugin->getSync()->isEligible($o)
            ));

            if ($orders === []) {
                $this->stdout("No unsynced orders in that period.\n", Console::FG_GREY);

                return ExitCode::OK;
            }

            $document = $plugin->getDocuments()->buildVoucher($orders, $from, $to);

            $this->stdout($document->description . "\n\n");
            $this->stdout(sprintf("  %-8s %-40s %14s %14s\n", 'Account', '', 'Debit', 'Credit'));

            foreach ($document->lines as $line) {
                $this->stdout(sprintf(
                    "  %-8s %-40s %14s %14s\n",
                    $line->account,
                    mb_substr((string)$line->text, 0, 40),
                    $line->getDebit() ? number_format($line->getDebit(), 2, ',', ' ') : '',
                    $line->getCredit() ? number_format($line->getCredit(), 2, ',', ' ') : '',
                ));
            }

            $this->stdout(sprintf(
                "\n  %s\n",
                $document->balances() ? 'Balances.' : 'DOES NOT BALANCE.'
            ), $document->balances() ? Console::FG_GREEN : Console::FG_RED);

            return $document->balances() ? ExitCode::OK : ExitCode::UNSPECIFIED_ERROR;
        }

        $result = $plugin->getSync()->syncPeriod($from, $to);

        $this->stdout(($result['message'] ?? ucfirst($result['status'])) . "\n", match ($result['status']) {
            'sent' => Console::FG_GREEN,
            'failed' => Console::FG_RED,
            default => Console::FG_GREY,
        });

        return $result['status'] === 'failed' ? ExitCode::UNSPECIFIED_ERROR : ExitCode::OK;
    }

    /**
     * Retry every failed document.
     */
    public function actionRetry(): int
    {
        if (!$this->guard()) {
            return ExitCode::CONFIG;
        }

        $result = Plugin::getInstance()->getSync()->retryFailed($this->limit);

        $this->stdout(sprintf(
            "%d attempted, %d sent, %d still failing.\n",
            $result['attempted'],
            $result['sent'],
            $result['failed']
        ));

        return $result['failed'] === 0 ? ExitCode::OK : ExitCode::UNSPECIFIED_ERROR;
    }

    /**
     * @return array{0: DateTimeImmutable, 1: DateTimeImmutable}
     */
    private function range(): array
    {
        $from = new DateTimeImmutable($this->from ?: 'yesterday');
        $to = new DateTimeImmutable($this->to ?: ($this->from ?: 'yesterday'));

        return [$from->setTime(0, 0, 0), $to->setTime(0, 0, 0)];
    }

    private function guard(): bool
    {
        $plugin = Plugin::getInstance();

        if (!Plugin::commerceIsReady()) {
            $this->stderr("Craft Commerce is not installed or is disabled.\n", Console::FG_RED);

            return false;
        }

        // A dry run needs no connection at all — that is rather the point of it.
        if (!$this->dryRun && !$plugin->getAuth()->isConnected()) {
            $this->stderr("Vismaz is not connected to Visma.\n", Console::FG_RED);

            return false;
        }

        return true;
    }
}
