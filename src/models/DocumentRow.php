<?php

namespace justinholtweb\vismaz\models;

use craft\base\Model;
use justinholtweb\vismaz\helpers\Money;

/**
 * One line of a document — an invoice row, or one half of a voucher posting.
 *
 * Amounts are held net, with the VAT alongside, because that is the only form both destinations
 * can consume: a Visma invoice row wants unit price ex VAT, a voucher wants the net and the VAT
 * as separate postings to separate accounts.
 */
class DocumentRow extends Model
{
    public const TYPE_ITEM = 'item';
    public const TYPE_SHIPPING = 'shipping';
    public const TYPE_DISCOUNT = 'discount';
    public const TYPE_FEE = 'fee';
    public const TYPE_ROUNDING = 'rounding';
    public const TYPE_TEXT = 'text';

    public string $type = self::TYPE_ITEM;

    /** Free text shown on the invoice line. */
    public string $text = '';

    /** SKU, when the line came from a purchasable. */
    public ?string $articleNumber = null;

    /** Visma article GUID, once the article has been synced. */
    public ?string $articleId = null;

    public float $quantity = 1.0;

    /** Unit price excluding VAT. */
    public float $unitPrice = 0.0;

    /** Percentage discount on the line, 0–100. */
    public float $discountPercentage = 0.0;

    /** Net line total excluding VAT. */
    public float $netAmount = 0.0;

    /** VAT on the line. */
    public float $vatAmount = 0.0;

    /** How this line is taxed. Never null on a line that carries money. */
    public ?TaxTreatment $tax = null;

    /** Unit of measure, e.g. `st`. */
    public ?string $unit = null;

    /** Commerce line item id this came from, for tracing. */
    public ?int $lineItemId = null;

    public function getGrossAmount(): float
    {
        return Money::round($this->netAmount + $this->vatAmount);
    }

    /**
     * The row as Visma's `CustomerInvoiceRow` shape.
     *
     * Visma keys are PascalCase. A row is either an *article* row (it has `ArticleId` and Visma
     * takes the price and VAT from the article register) or a *text* row — mixing the two is the
     * usual cause of an invoice that imports with empty amounts.
     */
    public function toVismaRow(): array
    {
        if ($this->type === self::TYPE_TEXT) {
            return [
                'IsTextRow' => true,
                'Text' => $this->text,
            ];
        }

        $row = [
            'IsTextRow' => false,
            'Text' => $this->text,
            'Quantity' => Money::round($this->quantity),
            'UnitPrice' => Money::round($this->unitPrice),
            'VatPercent' => $this->tax->rate ?? 0.0,
        ];

        if ($this->articleId !== null) {
            $row['ArticleId'] = $this->articleId;
        }

        if ($this->discountPercentage > 0) {
            // Visma expresses the discount as a fraction, not a percentage.
            $row['DiscountPercentage'] = Money::round($this->discountPercentage / 100);
        }

        if ($this->unit !== null) {
            $row['UnitAbbreviation'] = $this->unit;
        }

        return $row;
    }
}
