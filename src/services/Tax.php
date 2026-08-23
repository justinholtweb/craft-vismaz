<?php

namespace justinholtweb\vismaz\services;

use Craft;
use craft\commerce\elements\Order;
use craft\commerce\models\LineItem;
use craft\elements\Address;
use justinholtweb\vismaz\helpers\Bas;
use justinholtweb\vismaz\helpers\Money;
use justinholtweb\vismaz\helpers\Vies;
use justinholtweb\vismaz\models\TaxTreatment;
use justinholtweb\vismaz\Plugin;
use yii\base\Component;

/**
 * The one place a VAT treatment is decided.
 *
 * Invoice rows, voucher lines, the SIE file and the OSS report all ask this service the same
 * question and get the same answer, which is what stops a sale being reverse-charged on the
 * invoice and booked as domestic in the quarterly report.
 *
 * The decision, in order, for a seller in Sweden:
 *
 *  1. **Destination is home (SE).** Domestic VAT at whatever rate Commerce charged.
 *  2. **Destination is another EU country, buyer gave a validated VAT number.** Reverse charge:
 *     zero-rated, booked to the EU-exempt account, and the invoice must carry the wording.
 *  3. **Destination is another EU country, no VAT number, OSS on.** Destination-country VAT at
 *     the rate Commerce charged, tracked per country for the quarterly declaration.
 *  4. **Destination is outside the EU.** Export: zero-rated.
 *  5. Otherwise domestic, because taxing a sale you should not have is recoverable and
 *     zero-rating one you should have taxed is not.
 *
 * That last line is the whole disposition of this service: **when in doubt, charge the VAT.**
 */
class Tax extends Component
{
    /**
     * EU member states, by ISO-3166 alpha-2 as Craft stores country codes. Greece is `GR` here
     * and `EL` at VIES; `Vies::parse()` owns that translation.
     */
    public const EU_COUNTRIES = [
        'AT', 'BE', 'BG', 'CY', 'CZ', 'DE', 'DK', 'EE', 'ES', 'FI', 'FR', 'GR', 'HR', 'HU',
        'IE', 'IT', 'LT', 'LU', 'LV', 'MT', 'NL', 'PL', 'PT', 'RO', 'SE', 'SI', 'SK',
    ];

    /**
     * Treatment for a whole order, judged on the order's own totals.
     */
    public function treatOrder(Order $order): TaxTreatment
    {
        return $this->treat($order, $this->orderVatRate($order), $this->isShippingOnlyGoods($order));
    }

    /**
     * Treatment for one line item.
     */
    public function treatLineItem(Order $order, LineItem $lineItem): TaxTreatment
    {
        return $this->treat($order, $this->lineItemVatRate($lineItem), $this->isGoods($lineItem));
    }

    /**
     * The decision itself.
     *
     * @param float $chargedRate The VAT percentage Commerce actually charged.
     * @param bool $isGoods Goods and services book to different accounts across a border.
     */
    public function treat(Order $order, float $chargedRate, bool $isGoods = true): TaxTreatment
    {
        $plugin = Plugin::getInstance();
        $settings = $plugin->getSettings();

        $rate = Bas::normaliseRate($chargedRate);
        $home = strtoupper($settings->homeCountry);
        $country = strtoupper($this->destinationCountry($order) ?? $home);

        $treatment = new TaxTreatment([
            'rate' => $rate,
            'country' => $country,
            'kind' => TaxTreatment::KIND_DOMESTIC,
            'salesAccount' => $this->domesticAccount($rate),
            'vatAccount' => $this->vatAccount($rate),
        ]);

        // 1. Domestic.
        if ($country === $home) {
            $treatment->reason = Craft::t('vismaz', 'Delivered inside {country}.', ['country' => $home]);

            return $treatment;
        }

        $inEu = in_array($country, self::EU_COUNTRIES, true);
        $vatNumber = $this->vatNumberFor($order);

        // 2. EU B2B — reverse charge.
        if ($inEu && $settings->reverseChargeEnabled && $vatNumber !== null) {
            $validated = true;
            $reason = Craft::t('vismaz', 'EU business customer with a VAT number.');

            if ($settings->validateVatNumbers) {
                $check = Vies::check($vatNumber);
                $validated = $check['valid'];

                if (!$validated) {
                    // Not validated is not the same as invalid. Either way the sale stays taxed:
                    // zero-rating on the strength of a VIES timeout is the merchant's liability,
                    // not the buyer's.
                    $reason = $check['checked']
                        ? Craft::t('vismaz', 'VAT number {number} was rejected by VIES, so the sale stays taxed.', ['number' => $vatNumber])
                        : Craft::t('vismaz', 'VIES could not be reached, so the sale stays taxed.');
                }
            }

            if ($validated) {
                $treatment->kind = TaxTreatment::KIND_REVERSE_CHARGE;
                $treatment->rate = 0.0;
                $treatment->vatNumber = $vatNumber;
                $treatment->vatNumberValidated = $settings->validateVatNumbers;
                $treatment->salesAccount = $isGoods ? $settings->goodsEuExemptAccount : $settings->servicesEuAccount;
                // Reverse charge has no output VAT posting at all; the 2614-series accounts are
                // for *purchases* under reverse charge, not sales.
                $treatment->vatAccount = null;
                $treatment->invoiceNote = $settings->reverseChargeNote;
                $treatment->reason = $reason;

                return $treatment;
            }

            $treatment->vatNumber = $vatNumber;
            $treatment->reason = $reason;
        }

        // 3. EU B2C under OSS — destination VAT.
        if ($inEu && $settings->ossEnabled && $vatNumber === null) {
            $treatment->kind = TaxTreatment::KIND_OSS;
            $treatment->salesAccount = $this->domesticAccount($rate);
            $treatment->vatAccount = $this->vatAccount($rate);
            $treatment->reason = Craft::t(
                'vismaz',
                'EU consumer sale under OSS; {rate}% {country} VAT applies.',
                ['rate' => $rate, 'country' => $country]
            );

            return $treatment;
        }

        // 4. Outside the EU — export.
        if (!$inEu) {
            $treatment->kind = TaxTreatment::KIND_EXPORT;
            $treatment->rate = 0.0;
            $treatment->salesAccount = $isGoods ? $settings->goodsNonEuAccount : $settings->servicesNonEuAccount;
            $treatment->vatAccount = null;
            $treatment->reason = Craft::t('vismaz', 'Delivered outside the EU.');

            return $treatment;
        }

        // 5. EU, but nothing above applied.
        if ($rate > 0) {
            // VAT was charged, so this is a taxable EU sale. Goods have their own account for it.
            $treatment->salesAccount = $isGoods
                ? Bas::SALES_GOODS_EU_TAXABLE
                : $settings->servicesEuAccount;

            if ($treatment->reason === '') {
                $treatment->reason = Craft::t('vismaz', 'EU sale with {rate}% VAT charged.', ['rate' => $rate]);
            }

            return $treatment;
        }

        // No VAT was charged and no rule zero-rated it. That is a configuration gap, not a
        // treatment: it books to the exempt EU account and says plainly that nobody decided this,
        // because a silent zero-rate is exactly the kind of thing that surfaces at an audit.
        $treatment->kind = TaxTreatment::KIND_EXEMPT;
        $treatment->salesAccount = $isGoods ? $settings->goodsEuExemptAccount : $settings->servicesEuAccount;
        $treatment->vatAccount = null;

        if ($treatment->reason === '') {
            $treatment->reason = Craft::t(
                'vismaz',
                'EU sale to {country} with no VAT charged and no zero-rating rule that applies — check the tax setup.',
                ['country' => $country]
            );
        }

        return $treatment;
    }

    /**
     * The effective VAT rate on a line item, as a percentage.
     *
     * Derived from the money rather than read off the tax rate record on purpose: what belongs in
     * the books is the VAT that was actually charged, including whatever rounding, discounting
     * and included-tax arithmetic Commerce did on the way.
     */
    public function lineItemVatRate(LineItem $lineItem): float
    {
        $tax = $this->lineItemVatAmount($lineItem);
        $subtotal = (float)$lineItem->getSubtotal() + (float)$lineItem->getDiscount();
        $net = $subtotal;

        if (abs($net) < 0.0001) {
            return 0.0;
        }

        return Bas::normaliseRate(($tax / $net) * 100);
    }

    /**
     * VAT charged on a line item — both the added kind and the included kind.
     */
    public function lineItemVatAmount(LineItem $lineItem): float
    {
        return Money::round(
            (float)$lineItem->getTax()
            + (float)$lineItem->getTaxIncluded()
        );
    }

    /**
     * The dominant VAT rate across an order — the rate the largest share of its net sits at.
     *
     * A mixed-rate order (books at 6 %, a mug at 25 %) still has per-line treatments; this is only
     * for the order-level summary and the OSS report.
     */
    public function orderVatRate(Order $order): float
    {
        $byRate = [];

        foreach ($order->getLineItems() as $lineItem) {
            $rate = (string)$this->lineItemVatRate($lineItem);
            $byRate[$rate] = ($byRate[$rate] ?? 0) + (float)$lineItem->getSubtotal();
        }

        if ($byRate === []) {
            return 0.0;
        }

        arsort($byRate);

        return (float)array_key_first($byRate);
    }

    /**
     * Where the order is delivered. Shipping address wins; billing is the fallback, because a
     * digital order may have no shipping address at all.
     */
    public function destinationCountry(Order $order): ?string
    {
        foreach ([$order->getShippingAddress(), $order->getBillingAddress()] as $address) {
            if ($address instanceof Address && $address->countryCode) {
                return strtoupper($address->countryCode);
            }
        }

        return null;
    }

    /**
     * The buyer's EU VAT number, from whichever address field the merchant nominated.
     *
     * Returns null for anything that is not plausibly a VAT number, so a customer typing "n/a"
     * into the field cannot zero-rate their own order.
     */
    public function vatNumberFor(Order $order): ?string
    {
        $handle = Plugin::getInstance()->getSettings()->vatNumberFieldHandle;

        if ($handle === '') {
            return null;
        }

        foreach ([$order->getBillingAddress(), $order->getShippingAddress()] as $address) {
            if (!$address instanceof Address) {
                continue;
            }

            try {
                $value = $address->getFieldValue($handle);
            } catch (\Throwable) {
                $value = null;
            }

            $value = is_string($value) ? trim($value) : null;

            if ($value !== null && $value !== '' && Vies::parse($value) !== null) {
                return strtoupper(preg_replace('/[^A-Za-z0-9]/', '', $value) ?? '');
            }
        }

        return null;
    }

    /**
     * The organisation number, for the Visma customer record.
     */
    public function organisationNumberFor(Order $order): ?string
    {
        $handle = Plugin::getInstance()->getSettings()->organisationNumberFieldHandle;

        if ($handle === '') {
            return null;
        }

        foreach ([$order->getBillingAddress(), $order->getShippingAddress()] as $address) {
            if (!$address instanceof Address) {
                continue;
            }

            try {
                $value = $address->getFieldValue($handle);
            } catch (\Throwable) {
                $value = null;
            }

            if (is_string($value) && trim($value) !== '') {
                return trim($value);
            }
        }

        return null;
    }

    /**
     * Whether an order is B2B as far as the books are concerned.
     */
    public function isBusiness(Order $order): bool
    {
        return $this->vatNumberFor($order) !== null || $this->organisationNumberFor($order) !== null;
    }

    private function domesticAccount(float $rate): string
    {
        $settings = Plugin::getInstance()->getSettings();
        $key = rtrim(rtrim(number_format($rate, 2, '.', ''), '0'), '.');

        return (string)($settings->salesAccounts[$key] ?? Bas::domesticSalesAccount($rate));
    }

    private function vatAccount(float $rate): ?string
    {
        if ($rate <= 0) {
            return null;
        }

        $settings = Plugin::getInstance()->getSettings();
        $key = rtrim(rtrim(number_format($rate, 2, '.', ''), '0'), '.');

        return (string)($settings->vatAccounts[$key] ?? Bas::outputVatAccount($rate)) ?: null;
    }

    /**
     * Whether a line item is goods rather than a service. Commerce has no such flag, so this
     * reads the only signal it does have — a shippable purchasable is goods.
     */
    private function isGoods(LineItem $lineItem): bool
    {
        try {
            $purchasable = $lineItem->getPurchasable();

            return $purchasable === null || $purchasable->getIsShippable();
        } catch (\Throwable) {
            return true;
        }
    }

    private function isShippingOnlyGoods(Order $order): bool
    {
        foreach ($order->getLineItems() as $lineItem) {
            if ($this->isGoods($lineItem)) {
                return true;
            }
        }

        return false;
    }
}
