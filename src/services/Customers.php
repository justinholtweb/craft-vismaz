<?php

namespace justinholtweb\vismaz\services;

use Craft;
use craft\commerce\elements\Order;
use craft\elements\Address;
use craft\helpers\Db;
use DateTime;
use justinholtweb\vismaz\Plugin;
use justinholtweb\vismaz\records\EntityRecord;
use Throwable;
use yii\base\Component;

/**
 * Commerce buyers → Visma customers.
 *
 * A Visma invoice needs a `CustomerId`, so this runs ahead of every invoice push. Two shapes are
 * supported and the choice is the merchant's:
 *
 *  - **One Visma customer per buyer**, which is what a B2B shop wants and what makes Visma's own
 *    customer statements and reminders work.
 *  - **A single aggregate "webshop customer"**, which is what a high-volume B2C shop wants: a
 *    Visma customer register holding 40,000 one-time buyers is not a customer register, it is a
 *    performance problem with a GDPR liability attached.
 */
class Customers extends Component
{
    public const ENTITY_TYPE = 'customer';

    /**
     * The Visma customer GUID to invoice for this order, creating or updating as needed.
     */
    public function resolveForOrder(Order $order): ?string
    {
        $plugin = Plugin::getInstance();
        $settings = $plugin->getSettings();

        if ($settings->useAggregateCustomer) {
            return $this->resolveAggregate();
        }

        if (!$settings->syncCustomers) {
            return null;
        }

        $payload = $this->buildPayload($order);
        $localId = $this->localIdFor($order);
        $hash = sha1(json_encode($payload) ?: '');

        $existing = $this->findMapping($localId);

        if ($existing !== null && $existing->vismaId) {
            // Only spend a request when something actually changed. Re-pushing an unchanged
            // customer on every order is how a busy shop discovers the 600/minute rate limit.
            if ($existing->contentHash !== $hash) {
                try {
                    $plugin->getApi()->put('customers/' . $existing->vismaId, ['Id' => $existing->vismaId] + $payload);
                    $this->storeMapping($localId, $existing->vismaId, $payload['CustomerNumber'] ?? null, $hash);
                } catch (Throwable $e) {
                    // A customer that cannot be updated is still a customer that can be invoiced.
                    $plugin->getLog()->warning('customer.update', $e->getMessage(), ['orderId' => $order->id]);
                }
            }

            return $existing->vismaId;
        }

        $created = $plugin->getApi()->post('customers', $payload);
        $vismaId = is_array($created) ? ($created['Id'] ?? null) : null;

        if ($vismaId !== null) {
            $this->storeMapping($localId, $vismaId, $created['CustomerNumber'] ?? null, $hash);
        }

        return $vismaId;
    }

    /**
     * The shared webshop customer, looked up by customer number and created once.
     */
    public function resolveAggregate(): ?string
    {
        $plugin = Plugin::getInstance();
        $number = trim($plugin->getSettings()->aggregateCustomerNumber);

        if ($number === '') {
            return null;
        }

        $localId = 'aggregate:' . $number;
        $existing = $this->findMapping($localId);

        if ($existing !== null && $existing->vismaId) {
            return $existing->vismaId;
        }

        // It may already exist in Visma from a previous install or from the merchant's own hand.
        $matches = $plugin->getApi()->getAll('customers', ['$filter' => sprintf("CustomerNumber eq '%s'", self::escapeOData($number))], 1);

        if (!empty($matches[0]['Id'])) {
            $this->storeMapping($localId, $matches[0]['Id'], $number, null);

            return $matches[0]['Id'];
        }

        $created = $plugin->getApi()->post('customers', [
            'CustomerNumber' => $number,
            'Name' => Craft::t('vismaz', 'Webshop customers'),
            'IsPrivatePerson' => false,
            'IsActive' => true,
            'InvoiceCity' => '',
            'InvoiceCountryCode' => $plugin->getSettings()->homeCountry,
            'TermsOfPaymentId' => null,
        ]);

        $vismaId = is_array($created) ? ($created['Id'] ?? null) : null;

        if ($vismaId !== null) {
            $this->storeMapping($localId, $vismaId, $number, null);
        }

        return $vismaId;
    }

    /**
     * Visma's `Customer` shape for an order's buyer.
     */
    public function buildPayload(Order $order): array
    {
        $tax = Plugin::getInstance()->getTax();
        $settings = Plugin::getInstance()->getSettings();

        $billing = $order->getBillingAddress();
        $shipping = $order->getShippingAddress();
        $address = $billing instanceof Address ? $billing : $shipping;

        $organisationNumber = $tax->organisationNumberFor($order);
        $vatNumber = $tax->vatNumberFor($order);
        $isBusiness = $organisationNumber !== null || $vatNumber !== null;

        $payload = [
            'Name' => $this->nameFor($order, $address, $isBusiness),
            'EmailAddress' => $order->getEmail() ?: null,
            'IsPrivatePerson' => !$isBusiness,
            'IsActive' => true,
            'CustomerNumber' => $this->customerNumberFor($order),
            'CorporateIdentityNumber' => $organisationNumber,
            'VatNumber' => $vatNumber,
            'TermsOfPaymentId' => null,
            'InvoiceCountryCode' => $address->countryCode ?? $settings->homeCountry,
        ];

        if ($address instanceof Address) {
            $payload += [
                'InvoiceAddress1' => $address->addressLine1 ?: null,
                'InvoiceAddress2' => $address->addressLine2 ?: null,
                'InvoicePostalCode' => $address->postalCode ?: null,
                'InvoiceCity' => $address->locality ?: null,
            ];
        }

        if ($shipping instanceof Address && $shipping !== $address) {
            $payload += [
                'DeliveryAddress1' => $shipping->addressLine1 ?: null,
                'DeliveryAddress2' => $shipping->addressLine2 ?: null,
                'DeliveryPostalCode' => $shipping->postalCode ?: null,
                'DeliveryCity' => $shipping->locality ?: null,
                'DeliveryCountryCode' => $shipping->countryCode ?: null,
            ];
        }

        return array_filter($payload, static fn($v): bool => $v !== null);
    }

    /**
     * Local identity for the mapping table: the Craft user when there is one, otherwise the email
     * address, so a guest who orders twice is one customer rather than two.
     */
    public function localIdFor(Order $order): string
    {
        if ($order->customerId) {
            return 'user:' . $order->customerId;
        }

        return 'email:' . strtolower(trim((string)$order->getEmail()));
    }

    public function findMapping(string $localId): ?EntityRecord
    {
        /** @var EntityRecord|null $record */
        $record = EntityRecord::find()
            ->where(['entityType' => self::ENTITY_TYPE, 'localId' => $localId])
            ->one();

        return $record;
    }

    public function storeMapping(string $localId, ?string $vismaId, ?string $number, ?string $hash): void
    {
        $record = $this->findMapping($localId) ?? new EntityRecord([
            'entityType' => self::ENTITY_TYPE,
            'localId' => $localId,
        ]);

        $record->vismaId = $vismaId;
        $record->vismaNumber = $number;
        $record->contentHash = $hash;
        $record->dateSynced = Db::prepareDateForDb(new DateTime());
        $record->save(false);
    }

    private function nameFor(Order $order, ?Address $address, bool $isBusiness): string
    {
        if ($isBusiness && $address?->organization) {
            return $address->organization;
        }

        $name = trim((string)($address?->fullName ?: ''));

        if ($name !== '') {
            return $name;
        }

        return (string)($order->getEmail() ?: Craft::t('vismaz', 'Guest'));
    }

    private function customerNumberFor(Order $order): ?string
    {
        // Let Visma allocate its own numbering for guests; only registered users get a stable,
        // predictable number that survives a re-sync.
        return $order->customerId ? 'CR-' . $order->customerId : null;
    }

    /**
     * OData string literals escape a single quote by doubling it. Without this a customer number
     * containing an apostrophe is a query syntax error at best.
     */
    public static function escapeOData(string $value): string
    {
        return str_replace("'", "''", $value);
    }
}
