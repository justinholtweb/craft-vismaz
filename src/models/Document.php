<?php

namespace justinholtweb\vismaz\models;

use craft\base\Model;
use DateTimeInterface;
use justinholtweb\vismaz\helpers\Money;

/**
 * What Vismaz is going to put into Visma.
 *
 * This is the single shape produced by `services\Documents` and consumed by everything else —
 * the CP preview, the console dry run, the API push and the SIE writer. Because they all read
 * *this*, a preview is not an approximation of the push; it is the push.
 */
class Document extends Model
{
    public const TYPE_INVOICE = 'invoice';
    public const TYPE_CREDITNOTE = 'creditnote';
    public const TYPE_VOUCHER = 'voucher';

    public string $type = self::TYPE_INVOICE;

    /** Idempotency key. Unique per type; the database index on it is the real guarantee. */
    public string $sourceKey = '';

    /** Commerce order ids this document covers. A voucher covers many. */
    public array $orderIds = [];

    public ?DateTimeInterface $date = null;
    public ?DateTimeInterface $dueDate = null;

    public string $currency = 'SEK';

    /** Exchange rate to the company's own currency, when they differ. */
    public float $exchangeRate = 1.0;

    /** Invoice rows (invoice/credit note). @var DocumentRow[] */
    public array $rows = [];

    /** Journal postings (voucher). @var VoucherLine[] */
    public array $lines = [];

    /** Visma customer GUID, for invoices. */
    public ?string $customerId = null;

    public ?string $customerNumber = null;
    public ?string $yourReference = null;
    public ?string $ourReference = null;
    public ?string $orderReference = null;

    /** Free text printed on the document, e.g. the reverse-charge note. */
    public ?string $notes = null;

    /** The dominant tax treatment, for reporting. Per-row treatments live on the rows. */
    public ?TaxTreatment $tax = null;

    /** Öresavrundning applied to the total, if any. */
    public float $roundingAdjustment = 0.0;

    /** Human summary shown in the CP preview. */
    public string $description = '';

    public function getNetTotal(): float
    {
        if ($this->type === self::TYPE_VOUCHER) {
            return Money::sum(array_map(
                static fn(VoucherLine $l): float => $l->amount > 0 ? $l->amount : 0.0,
                $this->lines
            ));
        }

        return Money::sum(array_map(static fn(DocumentRow $r): float => $r->netAmount, $this->rows));
    }

    public function getVatTotal(): float
    {
        return Money::sum(array_map(static fn(DocumentRow $r): float => $r->vatAmount, $this->rows));
    }

    public function getGrossTotal(): float
    {
        return Money::round($this->getNetTotal() + $this->getVatTotal());
    }

    /**
     * Whether a voucher's postings balance. An unbalanced voucher is rejected by Visma with a
     * message that does not say which line is wrong, so it is caught here instead.
     */
    public function balances(): bool
    {
        if ($this->type !== self::TYPE_VOUCHER) {
            return true;
        }

        return Money::balances(array_map(static fn(VoucherLine $l): float => $l->amount, $this->lines));
    }

    /**
     * The payload as Visma expects it, ready to be posted.
     */
    public function toPayload(): array
    {
        return $this->type === self::TYPE_VOUCHER
            ? $this->toVoucherPayload()
            : $this->toInvoicePayload();
    }

    /**
     * Visma's endpoint for this document type.
     */
    public function getEndpoint(): string
    {
        return match ($this->type) {
            self::TYPE_VOUCHER => 'vouchers',
            self::TYPE_CREDITNOTE => 'customerinvoices',
            default => 'customerinvoices',
        };
    }

    private function toInvoicePayload(): array
    {
        $payload = [
            'CustomerId' => $this->customerId,
            'InvoiceDate' => $this->date?->format('Y-m-d'),
            'DueDate' => $this->dueDate?->format('Y-m-d'),
            'CurrencyCode' => $this->currency,
            'Rows' => array_map(static fn(DocumentRow $r): array => $r->toVismaRow(), $this->rows),
        ];

        if ($this->exchangeRate !== 1.0) {
            $payload['CurrencyRate'] = $this->exchangeRate;
        }

        if ($this->yourReference !== null) {
            $payload['YourReference'] = $this->yourReference;
        }

        if ($this->ourReference !== null) {
            $payload['OurReference'] = $this->ourReference;
        }

        if ($this->orderReference !== null) {
            $payload['InvoiceCustomerName'] = $this->orderReference;
        }

        if ($this->notes !== null) {
            $payload['Notes'] = $this->notes;
        }

        if ($this->type === self::TYPE_CREDITNOTE) {
            $payload['CreatedFromOrderId'] = null;
            $payload['IsCreditInvoice'] = true;
        }

        return array_filter($payload, static fn($v): bool => $v !== null);
    }

    private function toVoucherPayload(): array
    {
        return array_filter([
            'VoucherDate' => $this->date?->format('Y-m-d'),
            'VoucherText' => $this->description !== '' ? $this->description : null,
            'Rows' => array_map(static fn(VoucherLine $l): array => $l->toVismaRow(), $this->lines),
            'NumberSeries' => null,
        ], static fn($v): bool => $v !== null);
    }
}
