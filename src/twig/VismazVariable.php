<?php

namespace justinholtweb\vismaz\twig;

use craft\commerce\elements\Order;
use justinholtweb\vismaz\helpers\Bas;
use justinholtweb\vismaz\helpers\Money;
use justinholtweb\vismaz\models\TaxTreatment;
use justinholtweb\vismaz\Plugin;
use Throwable;

/**
 * `craft.vismaz` — read-only, for templates and the CP.
 */
class VismazVariable
{
    public function isConnected(): bool
    {
        return Plugin::getInstance()->getAuth()->isConnected();
    }

    public function connection(): ?array
    {
        $record = Plugin::getInstance()->getAuth()->getConnection();

        return $record === null ? null : [
            'companyName' => $record->companyName,
            'organisationNumber' => $record->organisationNumber,
            'environment' => $record->environment,
            'expiresAt' => $record->expiresAt,
        ];
    }

    /**
     * What Vismaz has sent for an order.
     */
    public function documentsForOrder(Order|int $order): array
    {
        $orderId = $order instanceof Order ? (int)$order->id : $order;

        return Plugin::getInstance()->getSync()->getDocumentsForOrder($orderId);
    }

    /**
     * How an order will be taxed, and why. Safe to call in a template — a failure answers null
     * rather than throwing into a page render.
     */
    public function treatment(Order $order): ?TaxTreatment
    {
        try {
            return Plugin::getInstance()->getTax()->treatOrder($order);
        } catch (Throwable) {
            return null;
        }
    }

    public function accountLabel(string $account): ?string
    {
        return Bas::label($account);
    }

    public function money(float $amount, string $currency = 'SEK'): string
    {
        return Money::format($amount, $currency);
    }

    public function redirectUri(): string
    {
        return Plugin::getInstance()->getAuth()->getRedirectUri();
    }
}
