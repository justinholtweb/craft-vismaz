<?php

namespace justinholtweb\vismaz\helpers;

use Craft;
use GuzzleHttp\Exception\GuzzleException;
use Throwable;

/**
 * EU VAT number validation against the Commission's VIES service.
 *
 * VIES is free, needs no key — and is unreliable in a way that matters: individual member states
 * take their own registry offline for maintenance, and the service answers for the *service*, not
 * the number. So every lookup here is cached, and every failure **fails open**: an unreachable
 * VIES must never stop an order syncing, and must certainly never stop a customer checking out.
 *
 * Failing open means an unverified number is treated as *not* validated, which keeps the sale
 * domestic and taxed. Erring the other way would zero-rate a sale on the strength of a timeout.
 */
abstract class Vies
{
    public const ENDPOINT = 'https://ec.europa.eu/taxation_customs/vies/rest-api/ms/%s/vat/%s';

    private const CACHE_KEY_PREFIX = 'vismaz:vies:';
    private const CACHE_TTL_VALID = 2592000;   // 30 days — registrations rarely lapse
    private const CACHE_TTL_INVALID = 86400;   // 1 day — a typo gets corrected sooner than that

    /**
     * EU member state codes VIES answers for. Northern Ireland trades under `XI`.
     */
    public const MEMBER_STATES = [
        'AT', 'BE', 'BG', 'CY', 'CZ', 'DE', 'DK', 'EE', 'EL', 'ES', 'FI', 'FR', 'HR', 'HU',
        'IE', 'IT', 'LT', 'LU', 'LV', 'MT', 'NL', 'PL', 'PT', 'RO', 'SE', 'SI', 'SK', 'XI',
    ];

    /**
     * Split a VAT number into its country prefix and body.
     *
     * Greece is the trap: its ISO country code is `GR` but its VAT prefix is `EL`, and VIES only
     * answers to `EL`.
     *
     * @return array{0: string, 1: string}|null
     */
    public static function parse(string $vatNumber): ?array
    {
        $normalised = strtoupper(preg_replace('/[^A-Za-z0-9]/', '', $vatNumber) ?? '');

        if (strlen($normalised) < 4) {
            return null;
        }

        $country = substr($normalised, 0, 2);
        $body = substr($normalised, 2);

        if ($country === 'GR') {
            $country = 'EL';
        }

        if (!in_array($country, self::MEMBER_STATES, true) || $body === '') {
            return null;
        }

        return [$country, $body];
    }

    /**
     * Whether a VAT number is registered and active.
     *
     * @return array{valid: bool, name: ?string, address: ?string, checked: bool, error: ?string}
     */
    public static function check(string $vatNumber): array
    {
        $miss = static fn(?string $error): array => [
            'valid' => false, 'name' => null, 'address' => null, 'checked' => false, 'error' => $error,
        ];

        $parsed = self::parse($vatNumber);

        if ($parsed === null) {
            return ['valid' => false, 'name' => null, 'address' => null, 'checked' => true, 'error' => 'Not a recognisable EU VAT number.'];
        }

        [$country, $number] = $parsed;
        $cacheKey = self::CACHE_KEY_PREFIX . $country . $number;
        $cache = Craft::$app->getCache();
        $cached = $cache->get($cacheKey);

        if (is_array($cached)) {
            return $cached;
        }

        try {
            $response = Craft::createGuzzleClient(['timeout' => 8, 'connect_timeout' => 4])
                ->get(sprintf(self::ENDPOINT, $country, $number), [
                    'headers' => ['Accept' => 'application/json'],
                    'http_errors' => false,
                ]);

            if ($response->getStatusCode() !== 200) {
                return $miss('VIES returned HTTP ' . $response->getStatusCode());
            }

            $payload = json_decode((string)$response->getBody(), true);

            if (!is_array($payload) || !array_key_exists('isValid', $payload)) {
                return $miss('VIES returned an unreadable response.');
            }

            $result = [
                'valid' => (bool)$payload['isValid'],
                // VIES only discloses the trader's name and address if the member state allows
                // it; both come back as "---" otherwise, which is worse than nothing.
                'name' => self::clean($payload['name'] ?? null),
                'address' => self::clean($payload['address'] ?? null),
                'checked' => true,
                'error' => null,
            ];

            $cache->set($cacheKey, $result, $result['valid'] ? self::CACHE_TTL_VALID : self::CACHE_TTL_INVALID);

            return $result;
        } catch (GuzzleException|Throwable $e) {
            // Deliberately not cached: a timeout says nothing about the number.
            return $miss($e->getMessage());
        }
    }

    private static function clean(?string $value): ?string
    {
        $value = trim((string)$value);

        return ($value === '' || $value === '---') ? null : $value;
    }
}
