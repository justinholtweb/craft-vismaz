<?php

namespace justinholtweb\vismaz\services;

use Craft;
use craft\commerce\elements\Order;
use DateTimeImmutable;
use DateTimeInterface;
use justinholtweb\vismaz\helpers\Bas;
use justinholtweb\vismaz\helpers\Sie4;
use justinholtweb\vismaz\models\Document;
use justinholtweb\vismaz\models\VoucherLine;
use justinholtweb\vismaz\Plugin;
use yii\base\Component;

/**
 * SIE 4 export.
 *
 * A large share of Swedish merchants run *Visma Administration*, a desktop product with no public
 * API. They cannot be pushed to — but every Swedish accounting package on the market imports SIE,
 * so the same journal Vismaz would have posted as a voucher gets written to a `.se` file instead.
 *
 * The file is built from the same `Documents::buildVoucher()` output as the API push, so a
 * merchant who exports a period and a merchant who pushes it get the same books.
 */
class Sie extends Component
{
    /**
     * Export a date range as a SIE 4 file.
     *
     * Each period inside the range becomes its own verification, which is what an accountant
     * expects: one verification per day's trading, not one for the quarter.
     *
     * @param bool $includeSynced Include orders already pushed to Visma. Off by default, because
     *                            exporting them too is how a merchant books a month twice.
     * @return array{contents: string, filename: string, verifications: int, orders: int}
     */
    public function export(
        DateTimeInterface $from,
        DateTimeInterface $to,
        bool $includeSynced = false,
        ?string $companyName = null,
    ): array {
        $plugin = Plugin::getInstance();
        $settings = $plugin->getSettings();
        $connection = $plugin->getAuth()->getConnection();

        $start = DateTimeImmutable::createFromInterface($from)->setTime(0, 0, 0);
        $end = DateTimeImmutable::createFromInterface($to)->setTime(23, 59, 59);

        $writer = new Sie4(
            $companyName ?: ($connection?->companyName ?: Craft::$app->getSystemName()),
            $connection?->organisationNumber,
            $start->modify('first day of january this year'),
            $start->modify('last day of december this year'),
        );

        $writer->header();

        $orders = $includeSynced
            ? $this->findOrdersInRange($start, $end)
            : $plugin->getSync()->findUnsyncedOrders($start, $end, 5000);

        $orders = array_values(array_filter($orders, fn(Order $o): bool => $plugin->getSync()->isEligible($o)));

        $verifications = 0;
        $number = 1;

        foreach ($this->groupByPeriod($orders, $settings->voucherPeriod) as $key => $group) {
            [$periodStart, $periodEnd] = $group['period'];

            $document = $plugin->getDocuments()->buildVoucher($group['orders'], $periodStart, $periodEnd);

            if (!$document->balances()) {
                $plugin->getLog()->warning('sie.export', Craft::t(
                    'vismaz',
                    'Skipped an unbalanced verification for {period}.',
                    ['period' => $key]
                ));

                continue;
            }

            $writer->verification(
                $periodEnd,
                $document->description,
                array_map(static fn(VoucherLine $line): array => $line->toSieTransaction(), $document->lines),
                $settings->voucherSeries,
                $number++,
            );

            $verifications++;
        }

        // Declare the accounts the merchant configured even if nothing posted to them this run,
        // so the importer does not invent names for accounts it has never seen.
        foreach ($this->configuredAccounts() as $account) {
            $writer->account($account);
        }

        return [
            'contents' => $writer->toString(),
            'filename' => sprintf('vismaz-%s-%s.se', $start->format('Ymd'), $end->format('Ymd')),
            'verifications' => $verifications,
            'orders' => count($orders),
        ];
    }

    /**
     * Group orders into the configured aggregation period.
     *
     * @param Order[] $orders
     * @return array<string, array{period: array{0: DateTimeImmutable, 1: DateTimeImmutable}, orders: Order[]}>
     */
    public function groupByPeriod(array $orders, string $period): array
    {
        $groups = [];

        foreach ($orders as $order) {
            $date = $order->dateOrdered ?? $order->dateCreated;

            if ($date === null) {
                continue;
            }

            [$start, $end] = Documents::periodFor($date, $period);
            $key = $start->format('Y-m-d') . ':' . $end->format('Y-m-d');

            $groups[$key] ??= ['period' => [$start, $end], 'orders' => []];
            $groups[$key]['orders'][] = $order;
        }

        ksort($groups);

        return $groups;
    }

    /**
     * @return Order[]
     */
    private function findOrdersInRange(DateTimeInterface $from, DateTimeInterface $to): array
    {
        /** @var Order[] $orders */
        $orders = Order::find()
            ->isCompleted(true)
            ->dateOrdered(['and', '>= ' . $from->format('Y-m-d H:i:s'), '<= ' . $to->format('Y-m-d H:i:s')])
            ->limit(5000)
            ->orderBy(['commerce_orders.dateOrdered' => SORT_ASC])
            ->all();

        return $orders;
    }

    /**
     * @return string[]
     */
    private function configuredAccounts(): array
    {
        $settings = Plugin::getInstance()->getSettings();

        $accounts = array_merge(
            array_values($settings->salesAccounts),
            array_values($settings->vatAccounts),
            [
                $settings->goodsEuExemptAccount,
                $settings->goodsNonEuAccount,
                $settings->servicesEuAccount,
                $settings->servicesNonEuAccount,
                $settings->freightAccount,
                $settings->discountAccount,
                $settings->roundingAccount,
                $settings->receivablesAccount,
                $settings->feeAccount,
                $settings->defaultPaymentAccount,
            ]
        );

        foreach ($settings->paymentAccounts as $mapping) {
            if (is_array($mapping)) {
                $accounts[] = $mapping['account'] ?? null;
                $accounts[] = $mapping['feeAccount'] ?? null;
            }
        }

        return array_values(array_unique(array_filter(
            array_map(static fn($a): string => trim((string)$a), $accounts),
            static fn(string $a): bool => Bas::isValidAccount($a)
        )));
    }
}
