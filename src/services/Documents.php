<?php

namespace justinholtweb\vismaz\services;

use Craft;
use craft\commerce\elements\Order;
use craft\commerce\models\LineItem;
use DateTime;
use DateTimeImmutable;
use DateTimeInterface;
use justinholtweb\vismaz\helpers\Bas;
use justinholtweb\vismaz\helpers\Money;
use justinholtweb\vismaz\models\Document;
use justinholtweb\vismaz\models\DocumentRow;
use justinholtweb\vismaz\models\Settings;
use justinholtweb\vismaz\models\TaxTreatment;
use justinholtweb\vismaz\models\VoucherLine;
use justinholtweb\vismaz\Plugin;
use yii\base\Component;
use yii\base\InvalidArgumentException;

/**
 * The only place a Commerce order becomes Visma data.
 *
 * The CP preview, the console dry run, the real push and the SIE writer all call in here and all
 * receive the same `Document`. That is deliberate and load-bearing: a preview that is generated
 * by different code from the push is a preview a merchant cannot trust, and this integration is
 * only worth having if the merchant can check it before it touches their books.
 *
 * Nothing in here performs a write, of any kind, to anywhere. Building a document is pure, which
 * is what makes the dry run safe to point at production.
 */
class Documents extends Component
{
    /**
     * One order → one customer invoice.
     *
     * @param bool $resolveRemote Whether to look up (and create) Visma customers and articles.
     *                            False for a preview, so a preview never writes to Visma.
     */
    public function buildInvoice(Order $order, bool $resolveRemote = true): Document
    {
        $plugin = Plugin::getInstance();
        $settings = $plugin->getSettings();
        $tax = $plugin->getTax();

        $document = new Document([
            'type' => Document::TYPE_INVOICE,
            'sourceKey' => self::invoiceKey($order),
            'orderIds' => [$order->id],
            'date' => $this->documentDate($order),
            'currency' => $order->paymentCurrency ?: $order->currency ?: 'SEK',
            'orderReference' => (string)$order->reference,
            'yourReference' => $order->getEmail() ?: null,
            'ourReference' => (string)$order->reference,
            'description' => Craft::t('vismaz', 'Order {reference}', ['reference' => $order->reference]),
        ]);

        $document->dueDate = (clone $document->date)->modify('+' . max(0, $settings->paymentTermsDays) . ' days');
        $document->tax = $tax->treatOrder($order);

        if ($resolveRemote) {
            $document->customerId = $plugin->getCustomers()->resolveForOrder($order);
        }

        $notes = [];

        foreach ($order->getLineItems() as $lineItem) {
            $row = $this->buildLineItemRow($order, $lineItem, $resolveRemote);
            $document->rows[] = $row;

            if ($row->tax?->invoiceNote !== null) {
                $notes[$row->tax->invoiceNote] = true;
            }
        }

        foreach ($this->buildAdjustmentRows($order, $document->tax) as $row) {
            $document->rows[] = $row;
        }

        // Öresavrundning. A Swedish invoice settles to whole kronor and the difference is a real
        // posting to 3740, not a discrepancy to be hidden in the last line's price.
        if ($settings->roundToWholeKronor) {
            [, $adjustment] = Money::roundToWholeKronor($document->getGrossTotal());

            if (abs($adjustment) >= 0.01) {
                $document->roundingAdjustment = $adjustment;
                $document->rows[] = new DocumentRow([
                    'type' => DocumentRow::TYPE_ROUNDING,
                    'text' => Craft::t('vismaz', 'Öresavrundning'),
                    'quantity' => 1.0,
                    'unitPrice' => $adjustment,
                    'netAmount' => $adjustment,
                    'vatAmount' => 0.0,
                    'tax' => new TaxTreatment([
                        'kind' => TaxTreatment::KIND_EXEMPT,
                        'rate' => 0.0,
                        'salesAccount' => $settings->roundingAccount,
                        'vatAccount' => null,
                        'reason' => Craft::t('vismaz', 'Rounding to whole kronor.'),
                    ]),
                ]);
            }
        }

        if ($notes !== []) {
            $document->notes = implode("\n", array_keys($notes));
        }

        return $document;
    }

    /**
     * One order → a credit note, for the amount actually refunded.
     *
     * Keyed on the refund transactions rather than the order, so a second partial refund is a
     * second credit note and a retry of the first is not.
     */
    public function buildCreditNote(Order $order, bool $resolveRemote = true): ?Document
    {
        $refunded = $this->refundedAmount($order);

        if ($refunded <= 0) {
            return null;
        }

        $plugin = Plugin::getInstance();
        $tax = $plugin->getTax();
        $treatment = $tax->treatOrder($order);

        $document = new Document([
            'type' => Document::TYPE_CREDITNOTE,
            'sourceKey' => self::creditNoteKey($order),
            'orderIds' => [$order->id],
            'date' => new DateTimeImmutable(),
            'currency' => $order->paymentCurrency ?: $order->currency ?: 'SEK',
            'orderReference' => (string)$order->reference,
            'ourReference' => (string)$order->reference,
            'tax' => $treatment,
            'description' => Craft::t('vismaz', 'Refund for order {reference}', ['reference' => $order->reference]),
        ]);

        $document->dueDate = $document->date;

        if ($resolveRemote) {
            $document->customerId = $plugin->getCustomers()->resolveForOrder($order);
        }

        // A refund is credited at the order's dominant rate. Refunds in Commerce are amounts, not
        // line items, so there is nothing finer-grained to be faithful to.
        $rate = $treatment->rate;
        $net = Money::netFromGross($refunded, $rate);

        $document->rows[] = new DocumentRow([
            'type' => DocumentRow::TYPE_ITEM,
            'text' => Craft::t('vismaz', 'Refund, order {reference}', ['reference' => $order->reference]),
            'quantity' => 1.0,
            'unitPrice' => $net,
            'netAmount' => $net,
            'vatAmount' => Money::round($refunded - $net),
            'tax' => $treatment,
        ]);

        if ($treatment->invoiceNote !== null) {
            $document->notes = $treatment->invoiceNote;
        }

        return $document;
    }

    /**
     * Many orders → one summary voucher.
     *
     * This is what a Swedish accountant actually wants out of a webshop: 900 orders in a day
     * become one verification with a handful of postings, not 900 invoices in the customer
     * ledger. Revenue is grouped by ledger account (which already encodes the VAT treatment),
     * VAT by rate, and settlement by payment method.
     *
     * @param Order[] $orders
     */
    public function buildVoucher(array $orders, DateTimeInterface $periodStart, DateTimeInterface $periodEnd): Document
    {
        if ($orders === []) {
            throw new InvalidArgumentException('A summary voucher needs at least one order.');
        }

        $plugin = Plugin::getInstance();
        $settings = $plugin->getSettings();
        $tax = $plugin->getTax();

        $document = new Document([
            'type' => Document::TYPE_VOUCHER,
            'sourceKey' => self::voucherKey($periodStart, $periodEnd),
            'orderIds' => array_values(array_filter(array_map(static fn(Order $o): ?int => $o->id, $orders))),
            'date' => $periodEnd,
            'currency' => $settings->homeCountry === 'SE' ? 'SEK' : ($orders[0]->currency ?: 'SEK'),
            'description' => Craft::t('vismaz', 'Webshop sales {from} – {to} ({count} orders)', [
                'from' => $periodStart->format('Y-m-d'),
                'to' => $periodEnd->format('Y-m-d'),
                'count' => count($orders),
            ]),
        ]);

        /** @var array<string, float> $sales      account → net revenue (credit) */
        $sales = [];
        /** @var array<string, float> $vat        account → output VAT (credit) */
        $vat = [];
        /** @var array<string, float> $settlement account → cash in (debit) */
        $settlement = [];
        /** @var array<string, float> $fees       account → PSP cost (debit) */
        $fees = [];

        $roundingTotal = 0.0;
        $dominant = null;

        foreach ($orders as $order) {
            $dominant ??= $tax->treatOrder($order);

            foreach ($order->getLineItems() as $lineItem) {
                $row = $this->buildLineItemRow($order, $lineItem, false);
                $account = $row->tax?->salesAccount ?: Bas::SALES_SE_EXEMPT;

                $sales[$account] = Money::round(($sales[$account] ?? 0) + $row->netAmount);

                if ($row->vatAmount != 0.0 && $row->tax?->vatAccount) {
                    $vat[$row->tax->vatAccount] = Money::round(($vat[$row->tax->vatAccount] ?? 0) + $row->vatAmount);
                }
            }

            foreach ($this->buildAdjustmentRows($order, $tax->treatOrder($order)) as $row) {
                $account = $row->type === DocumentRow::TYPE_SHIPPING
                    ? $settings->freightAccount
                    : ($row->type === DocumentRow::TYPE_DISCOUNT ? $settings->discountAccount : ($row->tax?->salesAccount ?: Bas::SALES_SE_EXEMPT));

                $sales[$account] = Money::round(($sales[$account] ?? 0) + $row->netAmount);

                if ($row->vatAmount != 0.0 && $row->tax?->vatAccount) {
                    $vat[$row->tax->vatAccount] = Money::round(($vat[$row->tax->vatAccount] ?? 0) + $row->vatAmount);
                }
            }

            $paid = Money::round((float)$order->getTotalPaid());

            if ($settings->roundToWholeKronor) {
                [, $adjustment] = Money::roundToWholeKronor((float)$order->getTotal());
                $roundingTotal = Money::round($roundingTotal + $adjustment);
            }

            // Settlement, split by how it was actually paid. This is the setting that decides
            // whether the merchant's books reconcile against their PSP statements.
            foreach ($this->settlementFor($order, $paid) as $account => $amount) {
                $settlement[$account] = Money::round(($settlement[$account] ?? 0) + $amount);
            }

            foreach ($this->feesFor($order) as $account => $amount) {
                $fees[$account] = Money::round(($fees[$account] ?? 0) + $amount);
            }
        }

        $document->tax = $dominant;

        // Debits positive, credits negative.
        foreach ($settlement as $account => $amount) {
            $document->lines[] = new VoucherLine([
                'account' => (string)$account,
                'amount' => $amount,
                'text' => Craft::t('vismaz', 'Settlement'),
            ]);
        }

        foreach ($fees as $account => $amount) {
            $document->lines[] = new VoucherLine([
                'account' => (string)$account,
                'amount' => $amount,
                'text' => Craft::t('vismaz', 'Payment fees'),
            ]);
        }

        foreach ($sales as $account => $amount) {
            $document->lines[] = new VoucherLine([
                'account' => (string)$account,
                'amount' => -$amount,
                'text' => Bas::label((string)$account) ?? Craft::t('vismaz', 'Sales'),
            ]);
        }

        foreach ($vat as $account => $amount) {
            $document->lines[] = new VoucherLine([
                'account' => (string)$account,
                'amount' => -$amount,
                'text' => Bas::label((string)$account) ?? Craft::t('vismaz', 'Output VAT'),
            ]);
        }

        if (abs($roundingTotal) >= 0.01) {
            $document->roundingAdjustment = $roundingTotal;
            $document->lines[] = new VoucherLine([
                'account' => $settings->roundingAccount,
                'amount' => -$roundingTotal,
                'text' => Craft::t('vismaz', 'Öresavrundning'),
            ]);
        }

        // Whatever is left over is the difference between what was booked and what was banked —
        // most often a part-paid order. It goes to receivables so the entry balances, and it
        // balances *honestly*: no plug to a suspense account, and nothing silently dropped.
        $residual = Money::sum(array_map(static fn(VoucherLine $l): float => $l->amount, $document->lines));

        if (abs($residual) >= 0.01) {
            $document->lines[] = new VoucherLine([
                'account' => $settings->receivablesAccount,
                'amount' => -$residual,
                'text' => Craft::t('vismaz', 'Outstanding at period end'),
            ]);
        }

        return $document;
    }

    /**
     * One line item → one invoice row.
     */
    public function buildLineItemRow(Order $order, LineItem $lineItem, bool $resolveRemote = true): DocumentRow
    {
        $plugin = Plugin::getInstance();
        $treatment = $plugin->getTax()->treatLineItem($order, $lineItem);

        // Net of the line, after discounts, before VAT.
        $net = Money::round(
            (float)$lineItem->getSubtotal()
            + (float)$lineItem->getDiscount()
        );

        $vatAmount = $treatment->isZeroRated() ? 0.0 : $plugin->getTax()->lineItemVatAmount($lineItem);
        $quantity = (float)$lineItem->qty ?: 1.0;

        $row = new DocumentRow([
            'type' => DocumentRow::TYPE_ITEM,
            'text' => mb_substr((string)($lineItem->getDescription() ?: $lineItem->getSku()), 0, 200),
            'articleNumber' => $lineItem->getSku() ?: null,
            'quantity' => $quantity,
            'unitPrice' => Money::round($net / $quantity),
            'netAmount' => $net,
            'vatAmount' => $vatAmount,
            'tax' => $treatment,
            'lineItemId' => $lineItem->id,
        ]);

        if ($resolveRemote) {
            $row->articleId = $plugin->getArticles()->resolveForLineItem($lineItem);
        }

        return $row;
    }

    /**
     * Shipping and order-level discounts as their own rows.
     *
     * They are separate rows rather than folded into the items because they book to different
     * accounts — freight to 3520, discounts to 3730 — and folding them in makes both accounts
     * permanently wrong.
     *
     * @return DocumentRow[]
     */
    public function buildAdjustmentRows(Order $order, ?TaxTreatment $orderTreatment = null): array
    {
        $plugin = Plugin::getInstance();
        $settings = $plugin->getSettings();
        $treatment = $orderTreatment ?? $plugin->getTax()->treatOrder($order);
        $rows = [];

        $shipping = Money::round((float)$order->getTotalShippingCost());

        if (abs($shipping) >= 0.01) {
            $rate = $treatment->isZeroRated() ? 0.0 : $treatment->rate;
            $net = Money::netFromGross($shipping, $this->shippingIsGross($order) ? $rate : 0.0);
            $net = $this->shippingIsGross($order) ? $net : $shipping;

            $shippingTreatment = clone $treatment;
            $shippingTreatment->salesAccount = $settings->freightAccount;

            $rows[] = new DocumentRow([
                'type' => DocumentRow::TYPE_SHIPPING,
                'text' => Craft::t('vismaz', 'Shipping'),
                'quantity' => 1.0,
                'unitPrice' => $net,
                'netAmount' => $net,
                'vatAmount' => Money::round($net * ($rate / 100)),
                'tax' => $shippingTreatment,
            ]);
        }

        // Order-level discounts only. Line-level discounts are already inside each line's net,
        // and counting them here as well would double them.
        $discount = Money::round((float)$order->getTotalDiscount());
        $lineDiscount = 0.0;

        foreach ($order->getLineItems() as $lineItem) {
            $lineDiscount = Money::round($lineDiscount + (float)$lineItem->getDiscount());
        }

        $orderDiscount = Money::round($discount - $lineDiscount);

        if (abs($orderDiscount) >= 0.01) {
            $rate = $treatment->isZeroRated() ? 0.0 : $treatment->rate;
            $net = Money::netFromGross($orderDiscount, $rate);

            $discountTreatment = clone $treatment;
            $discountTreatment->salesAccount = $settings->discountAccount;

            $rows[] = new DocumentRow([
                'type' => DocumentRow::TYPE_DISCOUNT,
                'text' => Craft::t('vismaz', 'Discount'),
                'quantity' => 1.0,
                'unitPrice' => $net,
                'netAmount' => $net,
                'vatAmount' => Money::round($orderDiscount - $net),
                'tax' => $discountTreatment,
            ]);
        }

        return $rows;
    }

    /**
     * Which account the money landed in, per gateway.
     *
     * @return array<string, float>
     */
    public function settlementFor(Order $order, float $paid): array
    {
        if (abs($paid) < 0.01) {
            return [];
        }

        $settings = Plugin::getInstance()->getSettings();
        $gateway = null;

        try {
            $gateway = $order->getGateway()?->handle;
        } catch (\Throwable) {
            // A gateway can be deleted after the order was paid; the default account still holds.
        }

        return [$settings->getPaymentAccount($gateway) => Money::round($paid)];
    }

    /**
     * The PSP's cut, as its own cost posting.
     *
     * @return array<string, float>
     */
    public function feesFor(Order $order): array
    {
        $settings = Plugin::getInstance()->getSettings();

        try {
            $gateway = $order->getGateway()?->handle;
        } catch (\Throwable) {
            return [];
        }

        $fee = $settings->getPaymentFee($gateway);

        if ($fee === null) {
            return [];
        }

        $total = (float)$order->getTotalPaid();
        $amount = Money::round(($total * ($fee['percent'] / 100)) + $fee['fixed']);

        if (abs($amount) < 0.01) {
            return [];
        }

        // A fee is a cost (debit) and it reduces what actually settles, so the settlement side
        // has to be credited by the same amount for the entry to stay honest.
        return [$fee['account'] => $amount, $settings->getPaymentAccount($gateway) => -$amount];
    }

    /**
     * Total refunded on an order, from its transactions.
     */
    public function refundedAmount(Order $order): float
    {
        $total = 0.0;

        foreach ($order->getTransactions() as $transaction) {
            if ($transaction->type === 'refund' && $transaction->status === 'success') {
                $total += (float)$transaction->amount;
            }
        }

        return Money::round($total);
    }

    /**
     * Whether Commerce's shipping adjustment already includes VAT.
     */
    private function shippingIsGross(Order $order): bool
    {
        foreach ($order->getAdjustments() as $adjustment) {
            if ($adjustment->type === 'tax' && $adjustment->included) {
                return true;
            }
        }

        return false;
    }

    private function documentDate(Order $order): DateTimeImmutable
    {
        $date = $order->dateOrdered ?? $order->dateCreated ?? new DateTime();

        return DateTimeImmutable::createFromInterface($date);
    }

    // Idempotency keys
    // -------------------------------------------------------------------------

    public static function invoiceKey(Order $order): string
    {
        return 'order:' . $order->id;
    }

    /**
     * Keyed on the refund transactions, so a *second* partial refund makes a second credit note
     * while a retry of the first does not.
     */
    public static function creditNoteKey(Order $order): string
    {
        $parts = [];

        foreach ($order->getTransactions() as $transaction) {
            if ($transaction->type === 'refund' && $transaction->status === 'success') {
                $parts[] = $transaction->id . ':' . $transaction->amount;
            }
        }

        sort($parts);

        return 'refund:' . $order->id . ':' . substr(sha1(implode('|', $parts)), 0, 16);
    }

    public static function voucherKey(DateTimeInterface $start, DateTimeInterface $end): string
    {
        return 'voucher:' . $start->format('Y-m-d') . ':' . $end->format('Y-m-d');
    }

    /**
     * The period an order falls into, per the aggregation setting.
     *
     * @return array{0: DateTimeImmutable, 1: DateTimeImmutable}
     */
    public static function periodFor(DateTimeInterface $date, string $period): array
    {
        $day = DateTimeImmutable::createFromInterface($date)->setTime(0, 0, 0);

        return match ($period) {
            Settings::PERIOD_MONTHLY => [$day->modify('first day of this month'), $day->modify('last day of this month')],
            Settings::PERIOD_WEEKLY => [$day->modify('monday this week'), $day->modify('sunday this week')],
            default => [$day, $day],
        };
    }
}
