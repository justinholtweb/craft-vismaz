<?php

namespace justinholtweb\vismaz\models;

use craft\base\Model;
use craft\helpers\App;
use justinholtweb\vismaz\helpers\Bas;

/**
 * Vismaz settings.
 *
 * Nothing here is ever marked `required`. Craft validates plugin settings wholesale, so a single
 * `required` credential means a fresh install cannot save *any* setting until that credential
 * exists — and the credential is the last thing a merchant has, not the first.
 */
class Settings extends Model
{
    public const MODE_INVOICE = 'invoice';
    public const MODE_VOUCHER = 'voucher';

    public const ENV_PRODUCTION = 'production';
    public const ENV_SANDBOX = 'sandbox';

    public const PERIOD_DAILY = 'daily';
    public const PERIOD_WEEKLY = 'weekly';
    public const PERIOD_MONTHLY = 'monthly';

    // Connection
    // -------------------------------------------------------------------------

    /** OAuth2 client id issued by the Visma developer portal. Env-parseable. */
    public string $clientId = '';

    /** OAuth2 client secret. Env-parseable. */
    public string $clientSecret = '';

    /** `production` or `sandbox` — they are different hosts for both identity and the API. */
    public string $environment = self::ENV_SANDBOX;

    // What gets posted
    // -------------------------------------------------------------------------

    /** `invoice` (one document per order) or `voucher` (periodic summary journal, Pro). */
    public string $documentMode = self::MODE_INVOICE;

    /** Push automatically when an order completes (Pro). Off means manual or console only. */
    public bool $autoPush = false;

    /** Commerce order status handles to sync. Empty means every completed order. */
    public array $syncStatusHandles = [];

    /** Aggregation period for voucher mode. */
    public string $voucherPeriod = self::PERIOD_DAILY;

    /** Voucher series letter in Visma. */
    public string $voucherSeries = 'A';

    /** Create a credit note in Visma when an order is refunded (Pro). */
    public bool $syncRefunds = true;

    /** Send the invoice to the customer from Visma rather than only creating it. */
    public bool $sendInvoiceFromVisma = false;

    // Customers and articles
    // -------------------------------------------------------------------------

    /** Create/update a Visma customer per order (invoice mode needs one). */
    public bool $syncCustomers = true;

    /** Create/update a Visma article per purchased variant. */
    public bool $syncArticles = true;

    /**
     * Reuse a single "webshop customer" in Visma instead of one per buyer. High-volume B2C
     * shops usually want this — a Visma customer register with 40,000 one-time buyers in it is
     * not useful to anybody.
     */
    public bool $useAggregateCustomer = false;

    /** Customer number in Visma to aggregate onto when the above is on. */
    public string $aggregateCustomerNumber = '';

    /** Handle of the Craft address field holding an organisation number. */
    public string $organisationNumberFieldHandle = '';

    /** Handle of the Craft address field holding an EU VAT number. */
    public string $vatNumberFieldHandle = '';

    /** Default payment terms, in days, on created invoices. */
    public int $paymentTermsDays = 30;

    // Swedish VAT
    // -------------------------------------------------------------------------

    /** The merchant's own country. Everything else is judged relative to it. */
    public string $homeCountry = 'SE';

    /** Apply reverse charge to EU B2B sales with a VAT number (Pro). */
    public bool $reverseChargeEnabled = true;

    /** Validate that VAT number against VIES before zero-rating (Pro). */
    public bool $validateVatNumbers = true;

    /** Apply destination VAT to EU B2C sales under the OSS scheme (Pro). */
    public bool $ossEnabled = false;

    /** Settle invoice totals to whole kronor and post the difference to the rounding account. */
    public bool $roundToWholeKronor = true;

    /**
     * Text stamped on a reverse-charged invoice. Swedish law wants the invoice to say so; the
     * buyer's own tax authority wants it in a language they read, hence both.
     */
    public string $reverseChargeNote = 'Omvänd betalningsskyldighet. Reverse charge, article 196 VAT directive.';

    // Ledger accounts (voucher mode + SIE)
    // -------------------------------------------------------------------------

    /** Sales accounts per VAT rate, keyed by rate as a string. */
    public array $salesAccounts = [
        '25' => Bas::SALES_SE_25,
        '12' => Bas::SALES_SE_12,
        '6' => Bas::SALES_SE_6,
        '0' => Bas::SALES_SE_EXEMPT,
    ];

    /** Output VAT accounts per rate. */
    public array $vatAccounts = [
        '25' => Bas::VAT_OUT_25,
        '12' => Bas::VAT_OUT_12,
        '6' => Bas::VAT_OUT_6,
    ];

    public string $goodsEuExemptAccount = Bas::SALES_GOODS_EU_EXEMPT;
    public string $goodsNonEuAccount = Bas::SALES_GOODS_NON_EU;
    public string $servicesEuAccount = Bas::SALES_SERVICES_EU;
    public string $servicesNonEuAccount = Bas::SALES_SERVICES_NON_EU;
    public string $freightAccount = Bas::FREIGHT;
    public string $discountAccount = Bas::DISCOUNTS;
    public string $roundingAccount = Bas::ROUNDING;
    public string $receivablesAccount = Bas::RECEIVABLES;
    public string $feeAccount = Bas::BANK_CHARGES;

    /**
     * Commerce payment gateway handle → [account, feeAccount, feePercent, feeFixed].
     *
     * This is the setting that decides whether a merchant's books reconcile. Klarna, Swish, card
     * and invoice do not settle to the same account, and the PSP's cut is a cost posting in its
     * own right rather than a discount on revenue.
     */
    public array $paymentAccounts = [];

    /** Account used when a gateway has no mapping of its own. */
    public string $defaultPaymentAccount = Bas::CARD_RECEIVABLES;

    // Housekeeping
    // -------------------------------------------------------------------------

    /** Days of connection log to keep. 0 keeps everything. */
    public int $logRetentionDays = 30;

    /** Record full request and response bodies in the log. */
    public bool $logPayloads = true;

    // Resolved accessors
    // -------------------------------------------------------------------------

    public function getClientId(): string
    {
        return trim((string)App::parseEnv($this->clientId));
    }

    public function getClientSecret(): string
    {
        return trim((string)App::parseEnv($this->clientSecret));
    }

    public function isSandbox(): bool
    {
        return $this->environment !== self::ENV_PRODUCTION;
    }

    public function getIdentityBaseUrl(): string
    {
        return $this->isSandbox()
            ? 'https://identity-sandbox.test.vismaonline.com'
            : 'https://identity.vismaonline.com';
    }

    public function getApiBaseUrl(): string
    {
        return $this->isSandbox()
            ? 'https://eaccountingapi-sandbox.test.vismaonline.com/v2/'
            : 'https://eaccountingapi.vismaonline.com/v2/';
    }

    /**
     * Ledger account for a gateway, falling back to the default.
     */
    public function getPaymentAccount(?string $gatewayHandle): string
    {
        $mapped = $this->paymentAccounts[$gatewayHandle] ?? null;

        if (is_array($mapped) && !empty($mapped['account'])) {
            return (string)$mapped['account'];
        }

        return $this->defaultPaymentAccount;
    }

    /**
     * Fee configuration for a gateway, or null when it charges nothing worth booking.
     *
     * @return array{account: string, percent: float, fixed: float}|null
     */
    public function getPaymentFee(?string $gatewayHandle): ?array
    {
        $mapped = $this->paymentAccounts[$gatewayHandle] ?? null;

        if (!is_array($mapped)) {
            return null;
        }

        $percent = (float)($mapped['feePercent'] ?? 0);
        $fixed = (float)($mapped['feeFixed'] ?? 0);

        if ($percent <= 0 && $fixed <= 0) {
            return null;
        }

        return [
            'account' => (string)(($mapped['feeAccount'] ?? '') ?: $this->feeAccount),
            'percent' => $percent,
            'fixed' => $fixed,
        ];
    }

    /**
     * @inheritdoc
     */
    public function rules(): array
    {
        return [
            [['clientId', 'clientSecret', 'aggregateCustomerNumber', 'reverseChargeNote'], 'trim'],
            [['environment'], 'in', 'range' => [self::ENV_PRODUCTION, self::ENV_SANDBOX]],
            [['documentMode'], 'in', 'range' => [self::MODE_INVOICE, self::MODE_VOUCHER]],
            [['voucherPeriod'], 'in', 'range' => [self::PERIOD_DAILY, self::PERIOD_WEEKLY, self::PERIOD_MONTHLY]],
            [['paymentTermsDays'], 'integer', 'min' => 0, 'max' => 365],
            [['logRetentionDays'], 'integer', 'min' => 0],
            [['homeCountry'], 'match', 'pattern' => '/^[A-Z]{2}$/'],
            [['voucherSeries'], 'match', 'pattern' => '/^[A-Z]{1,4}$/'],
            [
                [
                    'goodsEuExemptAccount', 'goodsNonEuAccount', 'servicesEuAccount',
                    'servicesNonEuAccount', 'freightAccount', 'discountAccount', 'roundingAccount',
                    'receivablesAccount', 'feeAccount', 'defaultPaymentAccount',
                ],
                'validateAccount',
            ],
            [['salesAccounts', 'vatAccounts'], 'validateAccountMap'],
        ];
    }

    /**
     * A BAS account is four digits opening 1–8. Empty is allowed: the setting may simply not be
     * reached yet on a half-configured install.
     */
    public function validateAccount(string $attribute): void
    {
        $value = trim((string)$this->$attribute);

        if ($value !== '' && !Bas::isValidAccount($value)) {
            $this->addError($attribute, \Craft::t('vismaz', '“{value}” is not a BAS account number.', ['value' => $value]));
        }
    }

    public function validateAccountMap(string $attribute): void
    {
        foreach ((array)$this->$attribute as $rate => $account) {
            $account = trim((string)$account);

            if ($account !== '' && !Bas::isValidAccount($account)) {
                $this->addError($attribute, \Craft::t('vismaz', '“{value}” is not a BAS account number.', ['value' => $account]));
            }
        }
    }
}
