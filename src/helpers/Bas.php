<?php

namespace justinholtweb\vismaz\helpers;

/**
 * The slice of the Swedish BAS chart of accounts Vismaz needs.
 *
 * Every one of these is a *default* the merchant can override in settings — a shop's accountant
 * may well have moved things — but the defaults have to be right, because a wrong account number
 * does not fail loudly, it quietly misfiles a year of revenue.
 *
 * Two pairs here are the reverse of the obvious guess and are the reason this list exists as
 * named constants rather than inline literals:
 *
 *  - `3305` is services sold *outside* the EU; `3308` is services to another EU country.
 *  - `3520` is invoiced *freight*; `3540` is the invoicing fee.
 */
abstract class Bas
{
    // Sales, domestic (kontogrupp 30)
    public const SALES_SE_25 = '3001';
    public const SALES_SE_12 = '3002';
    public const SALES_SE_6 = '3003';
    public const SALES_SE_EXEMPT = '3004';

    // Sales, goods across a border (kontogrupp 31)
    public const SALES_GOODS_NON_EU = '3105';
    public const SALES_GOODS_EU_TAXABLE = '3106';
    public const SALES_GOODS_EU_EXEMPT = '3108';

    // Sales, services across a border (kontogrupp 33)
    public const SALES_SERVICES_NON_EU = '3305';
    public const SALES_SERVICES_EU = '3308';

    // Invoiced costs (kontogrupp 35)
    public const FREIGHT = '3520';
    public const CUSTOMS = '3530';
    public const INVOICE_FEE = '3540';
    public const OTHER_INVOICED = '3590';

    // Income corrections (kontogrupp 37)
    public const ROUNDING = '3740';
    public const DISCOUNTS = '3730';

    // Receivables and bank (kontoklass 1)
    public const RECEIVABLES = '1510';
    public const CARD_RECEIVABLES = '1580';
    public const BANK = '1930';

    // Output VAT (kontogrupp 26)
    public const VAT_OUT_25 = '2610';
    public const VAT_OUT_12 = '2620';
    public const VAT_OUT_6 = '2630';

    // Output VAT under reverse charge
    public const VAT_OUT_RC_25 = '2614';
    public const VAT_OUT_RC_12 = '2624';
    public const VAT_OUT_RC_6 = '2634';

    // Costs
    public const BANK_CHARGES = '6570';

    /**
     * Human labels, in Swedish, as the chart actually spells them. Shown next to the account
     * fields in settings so a merchant can check the number against their own books.
     */
    public const LABELS = [
        self::SALES_SE_25 => 'Försäljning varor inom Sverige, 25 % moms',
        self::SALES_SE_12 => 'Försäljning varor inom Sverige, 12 % moms',
        self::SALES_SE_6 => 'Försäljning varor inom Sverige, 6 % moms',
        self::SALES_SE_EXEMPT => 'Försäljning varor inom Sverige, momsfri',
        self::SALES_GOODS_NON_EU => 'Försäljning varor till land utanför EU',
        self::SALES_GOODS_EU_TAXABLE => 'Försäljning varor till annat EU-land, momspliktig',
        self::SALES_GOODS_EU_EXEMPT => 'Försäljning varor till annat EU-land, momsfri',
        self::SALES_SERVICES_NON_EU => 'Försäljning tjänster till land utanför EU',
        self::SALES_SERVICES_EU => 'Försäljning tjänster till annat EU-land',
        self::FREIGHT => 'Fakturerade frakter',
        self::CUSTOMS => 'Fakturerade tull- och speditionskostnader m.m.',
        self::INVOICE_FEE => 'Faktureringsavgifter',
        self::OTHER_INVOICED => 'Övriga fakturerade kostnader',
        self::ROUNDING => 'Öres- och kronutjämning',
        self::DISCOUNTS => 'Lämnade rabatter',
        self::RECEIVABLES => 'Kundfordringar',
        self::CARD_RECEIVABLES => 'Fordringar för kontokort och kuponger',
        self::BANK => 'Företagskonto / affärskonto',
        self::VAT_OUT_25 => 'Utgående moms, 25 %',
        self::VAT_OUT_12 => 'Utgående moms, 12 %',
        self::VAT_OUT_6 => 'Utgående moms, 6 %',
        self::VAT_OUT_RC_25 => 'Utgående moms omvänd skattskyldighet, 25 %',
        self::VAT_OUT_RC_12 => 'Utgående moms omvänd skattskyldighet, 12 %',
        self::VAT_OUT_RC_6 => 'Utgående moms omvänd skattskyldighet, 6 %',
        self::BANK_CHARGES => 'Bankkostnader',
    ];

    /**
     * The Swedish VAT rates, highest first. Matching walks this list, so order matters.
     */
    public const SE_VAT_RATES = [25.0, 12.0, 6.0, 0.0];

    /**
     * Domestic sales account for a VAT rate.
     */
    public static function domesticSalesAccount(float $rate): string
    {
        return match (self::normaliseRate($rate)) {
            25.0 => self::SALES_SE_25,
            12.0 => self::SALES_SE_12,
            6.0 => self::SALES_SE_6,
            default => self::SALES_SE_EXEMPT,
        };
    }

    /**
     * Output VAT account for a rate, optionally the reverse-charge variant.
     */
    public static function outputVatAccount(float $rate, bool $reverseCharge = false): ?string
    {
        return match (self::normaliseRate($rate)) {
            25.0 => $reverseCharge ? self::VAT_OUT_RC_25 : self::VAT_OUT_25,
            12.0 => $reverseCharge ? self::VAT_OUT_RC_12 : self::VAT_OUT_12,
            6.0 => $reverseCharge ? self::VAT_OUT_RC_6 : self::VAT_OUT_6,
            default => null,
        };
    }

    public static function label(string $account): ?string
    {
        return self::LABELS[$account] ?? null;
    }

    /**
     * Snap a computed rate onto the nearest Swedish statutory rate.
     *
     * Commerce stores tax rates as fractions and floating-point arithmetic on an order total can
     * land on 24.999999999999996; without this, such an order books to the exempt account.
     */
    public static function normaliseRate(float $rate): float
    {
        foreach (self::SE_VAT_RATES as $known) {
            if (abs($rate - $known) < 0.01) {
                return $known;
            }
        }

        return round($rate, 2);
    }

    /**
     * Whether an account number is syntactically a BAS account.
     */
    public static function isValidAccount(string $account): bool
    {
        return (bool)preg_match('/^[1-8]\d{3}$/', $account);
    }
}
