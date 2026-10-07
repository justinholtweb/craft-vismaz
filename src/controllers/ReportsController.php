<?php

namespace justinholtweb\vismaz\controllers;

use Craft;
use craft\commerce\elements\Order;
use craft\web\Controller;
use DateTimeImmutable;
use justinholtweb\vismaz\helpers\Money;
use justinholtweb\vismaz\models\TaxTreatment;
use justinholtweb\vismaz\Plugin;
use yii\web\Response;

/**
 * The OSS report.
 *
 * A merchant selling B2C into the EU has to file a quarterly One Stop Shop declaration broken
 * down by destination country and VAT rate. Visma will not do that from a pile of invoices, and
 * doing it by hand out of an order export is where the mistakes come from — so Vismaz reports it
 * from the same `Tax::treat()` decisions it books with, which means the report and the ledger
 * cannot disagree.
 */
class ReportsController extends Controller
{
    public function beforeAction($action): bool
    {
        if (!parent::beforeAction($action)) {
            return false;
        }

        $this->requirePermission('vismaz-viewDocuments');

        return true;
    }

    public function actionOss(): Response
    {
        $request = Craft::$app->getRequest();
        $plugin = Plugin::getInstance();

        [$defaultFrom, $defaultTo] = self::currentQuarter();

        $from = new DateTimeImmutable((string)($request->getParam('from') ?: $defaultFrom->format('Y-m-d')));
        $to = (new DateTimeImmutable((string)($request->getParam('to') ?: $defaultTo->format('Y-m-d'))))->setTime(23, 59, 59);

        /** @var Order[] $orders */
        $orders = Order::find()
            ->isCompleted(true)
            ->dateOrdered(['and', '>= ' . $from->format('Y-m-d H:i:s'), '<= ' . $to->format('Y-m-d H:i:s')])
            ->limit(5000)
            ->all();

        $rows = [];
        $grandNet = 0.0;
        $grandVat = 0.0;

        foreach ($orders as $order) {
            if (!$plugin->getSync()->isEligible($order)) {
                continue;
            }

            foreach ($order->getLineItems() as $lineItem) {
                $treatment = $plugin->getTax()->treatLineItem($order, $lineItem);

                if ($treatment->kind !== TaxTreatment::KIND_OSS) {
                    continue;
                }

                $key = $treatment->country . ':' . $treatment->rate;
                $net = Money::round((float)$lineItem->getSubtotal() + (float)$lineItem->getDiscount());
                $vat = $plugin->getTax()->lineItemVatAmount($lineItem);

                $rows[$key] ??= [
                    'country' => $treatment->country,
                    'rate' => $treatment->rate,
                    'net' => 0.0,
                    'vat' => 0.0,
                    'orders' => [],
                ];

                $rows[$key]['net'] = Money::round($rows[$key]['net'] + $net);
                $rows[$key]['vat'] = Money::round($rows[$key]['vat'] + $vat);
                $rows[$key]['orders'][$order->id] = true;

                $grandNet = Money::round($grandNet + $net);
                $grandVat = Money::round($grandVat + $vat);
            }
        }

        foreach ($rows as $key => $row) {
            $rows[$key]['orderCount'] = count($row['orders']);
            unset($rows[$key]['orders']);
        }

        ksort($rows);

        return $this->renderTemplate('vismaz/reports/_oss', [
            'rows' => array_values($rows),
            'from' => $from,
            'to' => $to,
            'grandNet' => $grandNet,
            'grandVat' => $grandVat,
            'ossEnabled' => $plugin->getSettings()->ossEnabled,
        ]);
    }

    /**
     * @return array{0: DateTimeImmutable, 1: DateTimeImmutable}
     */
    private static function currentQuarter(): array
    {
        $now = new DateTimeImmutable();
        $quarter = (int)ceil((int)$now->format('n') / 3);
        $start = $now->setDate((int)$now->format('Y'), (($quarter - 1) * 3) + 1, 1)->setTime(0, 0, 0);

        return [$start, $start->modify('+3 months -1 day')];
    }
}
