<?php

namespace justinholtweb\vismaz\models;

use craft\base\Model;

/**
 * The single answer to "how is this sale taxed, and where does it book?".
 *
 * Produced only by `services\Tax::treat()`. Invoice rows, voucher lines, the SIE writer and the
 * OSS report all read this same object, which is what stops a sale being reverse-charged on the
 * invoice and domestic in the quarterly report.
 */
class TaxTreatment extends Model
{
    public const KIND_DOMESTIC = 'domestic';
    public const KIND_REVERSE_CHARGE = 'reverseCharge';
    public const KIND_OSS = 'oss';
    public const KIND_EXPORT = 'export';
    public const KIND_EXEMPT = 'exempt';

    /** One of the KIND_* constants. */
    public string $kind = self::KIND_DOMESTIC;

    /** The VAT percentage actually charged. Zero for reverse charge and export. */
    public float $rate = 0.0;

    /** Destination country, ISO-3166 alpha-2. */
    public string $country = 'SE';

    /** Ledger account the net revenue books to. */
    public string $salesAccount = '';

    /** Ledger account the output VAT books to, or null when there is none. */
    public ?string $vatAccount = null;

    /** Buyer's VAT number, when one applies to the treatment. */
    public ?string $vatNumber = null;

    /** Whether that number was confirmed against VIES (as opposed to merely present). */
    public bool $vatNumberValidated = false;

    /** Note that must appear on the invoice, e.g. the reverse-charge wording. */
    public ?string $invoiceNote = null;

    /** Why this treatment was chosen — shown in the CP so a merchant can audit a decision. */
    public string $reason = '';

    public function isZeroRated(): bool
    {
        return $this->rate <= 0.0;
    }

    public function isReverseCharge(): bool
    {
        return $this->kind === self::KIND_REVERSE_CHARGE;
    }

    /**
     * Human label for the CP and the log.
     */
    public function getLabel(): string
    {
        return match ($this->kind) {
            self::KIND_REVERSE_CHARGE => \Craft::t('vismaz', 'Reverse charge'),
            self::KIND_OSS => \Craft::t('vismaz', 'OSS ({country} {rate}%)', ['country' => $this->country, 'rate' => $this->rate]),
            self::KIND_EXPORT => \Craft::t('vismaz', 'Export, outside EU'),
            self::KIND_EXEMPT => \Craft::t('vismaz', 'Exempt'),
            default => \Craft::t('vismaz', 'Domestic {rate}%', ['rate' => $this->rate]),
        };
    }
}
