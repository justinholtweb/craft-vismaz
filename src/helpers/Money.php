<?php

namespace justinholtweb\vismaz\helpers;

/**
 * Money arithmetic for the ledger.
 *
 * Everything here works in minor units (öre) internally. Commerce hands out floats, and summing
 * a hundred float line items and comparing the result to a float order total is how a voucher
 * ends up one öre out of balance and Visma rejects the whole thing.
 */
abstract class Money
{
    /**
     * Float kronor to integer öre.
     */
    public static function toMinor(float|int|string $amount): int
    {
        return (int)round(((float)$amount) * 100);
    }

    /**
     * Integer öre back to kronor, rounded to two places for the wire.
     */
    public static function toMajor(int $minor): float
    {
        return round($minor / 100, 2);
    }

    /**
     * Round to two decimals, half away from zero — the behaviour VAT arithmetic assumes.
     */
    public static function round(float $amount): float
    {
        return round($amount, 2);
    }

    /**
     * Swedish öresavrundning: settle a total to whole kronor.
     *
     * Returns `[roundedTotal, adjustment]` in kronor, where the adjustment is what has to be
     * posted to 3740 for the entry to balance. A positive adjustment is income (the customer
     * paid up), a negative one is an income reduction.
     */
    public static function roundToWholeKronor(float $total): array
    {
        $minor = self::toMinor($total);
        $wholeMinor = (int)(round($minor / 100) * 100);

        return [self::toMajor($wholeMinor), self::toMajor($wholeMinor - $minor)];
    }

    /**
     * Net amount from a gross amount at a VAT rate given as a percentage.
     */
    public static function netFromGross(float $gross, float $vatRate): float
    {
        if ($vatRate <= 0.0) {
            return self::round($gross);
        }

        return self::round($gross / (1 + ($vatRate / 100)));
    }

    /**
     * VAT portion of a gross amount.
     */
    public static function vatFromGross(float $gross, float $vatRate): float
    {
        return self::round($gross - self::netFromGross($gross, $vatRate));
    }

    /**
     * Whether a set of signed amounts sums to zero, within a one-öre tolerance.
     *
     * @param float[] $amounts
     */
    public static function balances(array $amounts): bool
    {
        $sum = 0;

        foreach ($amounts as $amount) {
            $sum += self::toMinor($amount);
        }

        return abs($sum) <= 1;
    }

    /**
     * Exact signed sum of a list of amounts, done in öre so it cannot drift.
     *
     * @param float[] $amounts
     */
    public static function sum(array $amounts): float
    {
        $sum = 0;

        foreach ($amounts as $amount) {
            $sum += self::toMinor($amount);
        }

        return self::toMajor($sum);
    }

    /**
     * Format for display, Swedish style: space thousands separator, comma decimal.
     */
    public static function format(float $amount, string $currency = 'SEK'): string
    {
        return number_format($amount, 2, ',', "\u{00a0}") . "\u{00a0}" . $currency;
    }
}
