<?php

namespace justinholtweb\vismaz\models;

use craft\base\Model;
use craft\helpers\App;
use craft\helpers\StringHelper;
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

    /**
     * Register each Commerce payment against its Visma invoice (invoice mode). Without it every
     * invoice sits in Visma's receivables as unpaid, and Visma's own reminders chase customers who
     * have already paid.
     */
    public bool $syncPayments = true;

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
     * Commerce payment gateway handle → [account, feeAccount, feePercent, feeFixed, bankAccountId].
     *
     * This is the setting that decides whether a merchant's books reconcile. Klarna, Swish, card
     * and invoice do not settle to the same account, and the PSP's cut is a cost posting in its
     * own right rather than a discount on revenue.
     *
     * `bankAccountId` is the Visma bank account an invoice payment is registered to: its GUID, or
     * the ledger account number of a bank account in Visma (`1930`), which `Payments` resolves to
     * the GUID. Env-parseable. Visma's payment endpoint takes a bank account from its own register
     * rather than a ledger account, hence a column of its own.
     *
     * Always keyed by gateway handle: the settings form posts an editable table's rows as a list,
     * and `setAttributes()` re-keys them.
     */
    public array $paymentAccounts = [];

    /** Account used when a gateway has no mapping of its own. */
    public string $defaultPaymentAccount = Bas::CARD_RECEIVABLES;

    /** Visma bank account (GUID or its ledger account number) for invoice payments when a gateway has none. Env-parseable. */
    public string $defaultBankAccountId = '';

    // Housekeeping
    // -------------------------------------------------------------------------

    /** Days of connection log to keep. 0 keeps everything. */
    public int $logRetentionDays = 30;

    /** Record full request and response bodies in the log. */
    public bool $logPayloads = true;

    // Alerts
    // -------------------------------------------------------------------------
    // Nothing here is `required` either: an empty recipient list and an empty webhook URL simply
    // mean nobody is told, and a fresh install must still be able to save every other setting.

    /** Comma- or newline-separated addresses, or an `$ENV` reference that resolves to them. */
    public string $alertRecipients = '';

    /** A Slack or Teams incoming-webhook URL (or `$ENV`). Sent through the SSRF guard. */
    public string $alertWebhookUrl = '';

    /** `slack`, `teams` or `json` — the shape of the webhook body. */
    public string $alertWebhookFormat = 'slack';

    /** Optional. When set, the webhook carries an `X-Vismaz-Signature` HMAC of its body. */
    public string $alertWebhookSecret = '';

    /** Orders, credit notes or vouchers Visma refused, or that could not be sent. */
    public bool $alertOnFailures = true;

    /**
     * This many failures inside the window opens the incident (documents and payments count
     * separately). One by default: every failure is an order that is not in the books.
     */
    public int $alertFailureThreshold = 1;

    /** The window failures and mismatches are counted in, in minutes. */
    public int $alertWindowMinutes = 60;

    /** A document Visma booked at a different total from the one sent. */
    public bool $alertOnMismatch = true;

    /** A Commerce payment that could not be registered against its Visma invoice. */
    public bool $alertOnPayments = true;

    /** Visma refusing the refresh token, or a 401 that refreshing did not fix. */
    public bool $alertOnAuthFailure = true;

    /**
     * Hours after which work that should have happened and has not is an incident: a completed
     * order with no invoice while automatic sending is on, a send stuck in `pending`, a payment
     * whose job never ran. Usually a queue that is not running. 0 turns the check off.
     */
    public int $alertStallHours = 6;

    /** An incident that reopens this soon after its recovery message waits out the rest. */
    public int $alertCooldownMinutes = 60;

    /**
     * Config-file only: let the alert webhook reach private, loopback and link-local hosts (a
     * self-hosted Mattermost on the LAN). The scheme and no-redirect rules still hold.
     */
    public bool $allowPrivateAlertWebhookHosts = false;

    // Resolved accessors
    // -------------------------------------------------------------------------

    public function init(): void
    {
        parent::init();
        $this->paymentAccounts = self::normalisePaymentAccounts($this->paymentAccounts);
    }

    /**
     * @inheritdoc
     */
    public function setAttributes($values, $safeOnly = true): void
    {
        parent::setAttributes($values, $safeOnly);
        $this->paymentAccounts = self::normalisePaymentAccounts($this->paymentAccounts);
    }

    /**
     * Key payment-account rows by gateway handle.
     *
     * The settings form's editable table posts rows as `[0 => ['gateway' => 'stripe', …]]`, while
     * every reader looks a gateway up by handle. Before 5.1.0 the rows were stored as posted, so a
     * mapping made in the CP never matched and every gateway settled to the default account.
     * Rows already keyed by handle (config files, earlier saves) pass through unchanged.
     */
    public static function normalisePaymentAccounts(mixed $rows): array
    {
        $normalised = [];

        foreach ((array)$rows as $key => $row) {
            if (!is_array($row)) {
                continue;
            }

            $handle = trim((string)($row['gateway'] ?? ''));

            if ($handle === '' && is_string($key) && !preg_match('/^new\d+$/', $key)) {
                $handle = $key;
            }

            if ($handle === '') {
                continue;
            }

            unset($row['gateway']);
            $normalised[$handle] = $row;
        }

        return $normalised;
    }

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
     * The Visma bank account an invoice payment through this gateway is registered to, falling
     * back to the default. Null when neither is set — a payment is then refused with a message
     * rather than guessed onto some account.
     */
    public function getPaymentBankAccountId(?string $gatewayHandle): ?string
    {
        $mapped = $this->paymentAccounts[$gatewayHandle] ?? null;
        $raw = is_array($mapped) ? trim((string)($mapped['bankAccountId'] ?? '')) : '';

        if ($raw === '') {
            $raw = trim($this->defaultBankAccountId);
        }

        $value = trim((string)App::parseEnv($raw));

        return $value === '' ? null : $value;
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
            [['defaultBankAccountId'], 'trim'],
            [['defaultBankAccountId'], 'validateBankAccountId'],
            [['paymentAccounts'], 'validatePaymentAccounts'],
            [['alertOnFailures', 'alertOnMismatch', 'alertOnPayments', 'alertOnAuthFailure', 'allowPrivateAlertWebhookHosts'], 'boolean'],
            [['alertFailureThreshold'], 'integer', 'min' => 1, 'max' => 10000],
            [['alertWindowMinutes'], 'integer', 'min' => 5, 'max' => 10080],
            [['alertStallHours'], 'integer', 'min' => 0, 'max' => 720],
            [['alertCooldownMinutes'], 'integer', 'min' => 0, 'max' => 10080],
            [['alertWebhookFormat'], 'in', 'range' => ['slack', 'teams', 'json']],
            [['alertRecipients', 'alertWebhookUrl', 'alertWebhookSecret'], 'trim'],
            [['alertRecipients', 'alertWebhookUrl', 'alertWebhookSecret'], 'string', 'max' => 2000],
            [['alertRecipients'], 'validateRecipients'],
            [['alertWebhookUrl'], 'validateWebhookUrl'],
        ];
    }

    /**
     * Every address must be one, when there are any. An `$ENV` reference that is not set yet is
     * allowed — a staging site legitimately has no recipients.
     */
    public function validateRecipients(string $attribute): void
    {
        foreach ($this->recipientList(false) as $address) {
            if (filter_var($address, FILTER_VALIDATE_EMAIL) === false) {
                $this->addError($attribute, \Craft::t('vismaz', '“{address}” is not an email address.', ['address' => $address]));
            }
        }
    }

    /**
     * Only the shape is checked here. Where the host resolves is checked at send time, every time,
     * because DNS can change between a save and a send.
     */
    public function validateWebhookUrl(string $attribute): void
    {
        $url = trim((string)App::parseEnv($this->alertWebhookUrl));

        if ($url === '' || str_starts_with($url, '$')) {
            return;
        }

        $scheme = strtolower((string)parse_url($url, PHP_URL_SCHEME));

        if (!in_array($scheme, ['http', 'https'], true) || !parse_url($url, PHP_URL_HOST)) {
            $this->addError($attribute, \Craft::t('vismaz', 'Only http:// and https:// webhook URLs are allowed.'));
        }
    }

    /**
     * The alert recipients, with `$ENV` resolved.
     *
     * @return string[]
     */
    public function recipientList(bool $validOnly = true): array
    {
        $raw = trim((string)App::parseEnv($this->alertRecipients));

        if ($raw === '' || str_starts_with($raw, '$')) {
            return [];
        }

        $list = array_values(array_unique(array_filter(array_map('trim', preg_split('/[\s,;]+/', $raw) ?: []))));

        return $validOnly
            ? array_values(array_filter($list, static fn(string $a) => filter_var($a, FILTER_VALIDATE_EMAIL) !== false))
            : $list;
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

    /**
     * A Visma bank account is given as its GUID or its ledger account number. An
     * environment-variable reference is left alone: it may well not be set where the settings are
     * being saved.
     */
    public function validateBankAccountId(string $attribute): void
    {
        if (!self::isValidBankAccountReference((string)$this->$attribute)) {
            $this->addError($attribute, \Craft::t('vismaz', '“{value}” is neither a Visma bank account ID nor a ledger account number.', ['value' => $this->$attribute]));
        }
    }

    public function validatePaymentAccounts(string $attribute): void
    {
        foreach ((array)$this->$attribute as $mapping) {
            if (!is_array($mapping)) {
                continue;
            }

            foreach (['account', 'feeAccount'] as $key) {
                $account = trim((string)($mapping[$key] ?? ''));

                if ($account !== '' && !Bas::isValidAccount($account)) {
                    $this->addError($attribute, \Craft::t('vismaz', '“{value}” is not a BAS account number.', ['value' => $account]));
                }
            }

            $bankAccountId = (string)($mapping['bankAccountId'] ?? '');

            if (!self::isValidBankAccountReference($bankAccountId)) {
                $this->addError($attribute, \Craft::t('vismaz', '“{value}” is neither a Visma bank account ID nor a ledger account number.', ['value' => $bankAccountId]));
            }
        }
    }

    private static function isValidBankAccountReference(string $value): bool
    {
        $value = trim($value);

        return $value === ''
            || str_starts_with($value, '$')
            || str_starts_with($value, '@')
            || StringHelper::isUUID($value)
            || Bas::isValidAccount($value);
    }
}
