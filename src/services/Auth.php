<?php

namespace justinholtweb\vismaz\services;

use Craft;
use craft\helpers\Db;
use craft\helpers\StringHelper;
use craft\helpers\UrlHelper;
use DateTime;
use DateTimeImmutable;
use GuzzleHttp\ClientInterface;
use justinholtweb\vismaz\Plugin;
use justinholtweb\vismaz\records\TokenRecord;
use Throwable;
use yii\base\Component;
use yii\base\Exception;

/**
 * OAuth2 against Visma's identity server.
 *
 * Authorization code flow. Three things about it are worth knowing before changing anything here:
 *
 *  - **`offline_access` is not optional.** Without it Visma issues no refresh token at all, and
 *    the connection dies silently one hour after it is made.
 *  - **`prompt=select_account` matters.** Visma remembers the last company a user picked, so
 *    without it a merchant with a bookkeeping company and a trading company reconnects the wrong
 *    one and posts a year of revenue into the wrong books.
 *  - **The refresh token rotates.** Every refresh returns a new one, and the old one stops
 *    working. Two concurrent refreshes would leave one of them holding a dead token, so the
 *    refresh runs under a mutex.
 */
class Auth extends Component
{
    public const SCOPES = 'ea:api ea:sales ea:accounting offline_access';

    private const STATE_CACHE_PREFIX = 'vismaz:oauth:state:';
    private const STATE_TTL = 900;
    private const MUTEX_TIMEOUT = 10;

    /** Refresh this many seconds before the token actually expires. */
    private const REFRESH_SKEW = 120;

    /**
     * The HTTP client for the token endpoint. Null builds Craft's own per call; the integration
     * checks put a Guzzle `MockHandler` client here, because the harness has no route to Visma.
     */
    public ?ClientInterface $tokenClient = null;

    /**
     * Where Visma sends the merchant back to. Must be registered with the client id exactly.
     */
    public function getRedirectUri(): string
    {
        return UrlHelper::actionUrl('vismaz/oauth/callback');
    }

    /**
     * Build the URL the merchant is sent to, and remember the state we expect back.
     */
    public function getAuthorizationUrl(): string
    {
        $settings = Plugin::getInstance()->getSettings();
        $state = StringHelper::UUID();

        Craft::$app->getCache()->set(
            self::STATE_CACHE_PREFIX . $state,
            ['environment' => $settings->environment, 'userId' => Craft::$app->getUser()->getId()],
            self::STATE_TTL
        );

        return $settings->getIdentityBaseUrl() . '/connect/authorize?' . http_build_query([
            'client_id' => $settings->getClientId(),
            'redirect_uri' => $this->getRedirectUri(),
            'response_type' => 'code',
            'scope' => self::SCOPES,
            'state' => $state,
            // Without this Visma silently reuses the last company the user logged into.
            'prompt' => 'select_account',
        ]);
    }

    /**
     * Consume the state we issued. Single-use: a replayed callback finds nothing.
     */
    public function consumeState(string $state): ?array
    {
        $key = self::STATE_CACHE_PREFIX . $state;
        $value = Craft::$app->getCache()->get($key);

        if (!is_array($value)) {
            return null;
        }

        Craft::$app->getCache()->delete($key);

        return $value;
    }

    /**
     * Exchange an authorization code for tokens and store the connection.
     */
    public function exchangeCode(string $code, ?int $userId = null): TokenRecord
    {
        $payload = $this->requestToken([
            'grant_type' => 'authorization_code',
            'code' => $code,
            'redirect_uri' => $this->getRedirectUri(),
        ]);

        $record = $this->storeToken($payload, $userId);
        $this->hydrateCompany($record);

        return $record;
    }

    /**
     * The current, usable access token — refreshing it first if it is at or near expiry.
     *
     * Returns null when there is no connection at all, which callers treat as "not configured"
     * rather than as an error.
     */
    public function getAccessToken(): ?string
    {
        $record = $this->getConnection();

        if ($record === null) {
            return null;
        }

        if (!$this->isExpiring($record)) {
            return self::decrypt($record->accessToken);
        }

        return self::decrypt($this->refresh($record)?->accessToken);
    }

    /**
     * The stored access token, in plaintext, without refreshing it — for redacting it out of an
     * alert, where a refresh (a network call that rotates the refresh token) would be absurd.
     */
    public function getStoredAccessToken(): ?string
    {
        return self::decrypt($this->getConnection()?->accessToken);
    }

    /**
     * The stored refresh token, in plaintext.
     */
    public function getRefreshToken(): ?string
    {
        return self::decrypt($this->getConnection()?->refreshToken);
    }

    /**
     * Refresh under a mutex, re-reading the row once the lock is held.
     *
     * Two requests racing here would both post the same refresh token; Visma rotates it, so the
     * loser would store a token that was already dead. Holding the lock and then re-checking
     * means the second one finds the work already done.
     *
     * `$rejected` is the access token Visma just answered 401 to. Its expiry time says nothing
     * then — Visma has stopped honouring it — so the refresh goes ahead unless the row already
     * holds a *different* token, which means another process refreshed while this one waited.
     */
    public function refresh(?TokenRecord $record = null, ?string $rejected = null): ?TokenRecord
    {
        $record ??= $this->getConnection();

        if ($record === null || !$record->refreshToken) {
            return null;
        }

        $mutex = Craft::$app->getMutex();
        $lockName = 'vismaz:refresh:' . $record->environment;

        if (!$mutex->acquire($lockName, self::MUTEX_TIMEOUT)) {
            Plugin::getInstance()->getLog()->warning('auth.refresh', 'Timed out waiting to refresh the Visma token.');

            return $record;
        }

        try {
            $fresh = $this->getConnection(true);

            if (
                $fresh !== null
                && !$this->isExpiring($fresh)
                && ($rejected === null || self::decrypt($fresh->accessToken) !== $rejected)
            ) {
                return $fresh;
            }

            $payload = $this->requestToken([
                'grant_type' => 'refresh_token',
                'refresh_token' => (string)self::decrypt($fresh->refreshToken ?? $record->refreshToken),
            ]);

            return $this->storeToken($payload, $record->connectedBy);
        } catch (Throwable $e) {
            Plugin::getInstance()->getLog()->error('auth.refresh', $e->getMessage());

            return null;
        } finally {
            $mutex->release($lockName);
        }
    }

    /**
     * Forget the connection for the current environment, revoking the refresh token first so it
     * cannot outlive the disconnect.
     */
    public function disconnect(): void
    {
        $record = $this->getConnection();

        if ($record === null) {
            return;
        }

        $settings = Plugin::getInstance()->getSettings();

        try {
            if ($record->refreshToken) {
                Craft::createGuzzleClient(['timeout' => 10])->post(
                    $settings->getIdentityBaseUrl() . '/connect/revocation',
                    [
                        'auth' => [$settings->getClientId(), $settings->getClientSecret()],
                        'form_params' => ['token' => (string)self::decrypt($record->refreshToken), 'token_type_hint' => 'refresh_token'],
                        'http_errors' => false,
                    ]
                );
            }
        } catch (Throwable $e) {
            // A revocation that fails still has to result in a local disconnect, otherwise the
            // merchant is stuck connected to a company they cannot reach.
            Plugin::getInstance()->getLog()->warning('auth.revoke', $e->getMessage());
        }

        $record->delete();
        $this->connection = false;

        Plugin::getInstance()->getLog()->write('auth.disconnect', ['message' => 'Disconnected from Visma.']);
    }

    /**
     * The stored connection for the active environment, or null.
     */
    private TokenRecord|false|null $connection = null;

    public function getConnection(bool $refresh = false): ?TokenRecord
    {
        if (!$refresh && $this->connection !== null) {
            return $this->connection ?: null;
        }

        $environment = Plugin::getInstance()->getSettings()->environment;

        /** @var TokenRecord|null $record */
        $record = TokenRecord::find()->where(['environment' => $environment])->one();
        $this->connection = $record ?? false;

        return $record;
    }

    public function isConnected(): bool
    {
        return $this->getConnection() !== null;
    }

    /**
     * Whether settings hold enough to attempt a connection at all.
     */
    public function isConfigured(): bool
    {
        $settings = Plugin::getInstance()->getSettings();

        return $settings->getClientId() !== '' && $settings->getClientSecret() !== '';
    }

    private function isExpiring(TokenRecord $record): bool
    {
        if (!$record->accessToken || !$record->expiresAt) {
            return true;
        }

        // Stored by `Db::prepareDateForDb()`, so the bare string is UTC. Read without a zone it is
        // taken as the site's own: Stockholm then refreshed on every request (the token looked an
        // hour or two old already), and a site west of UTC kept a dead token for hours past its
        // expiry — a 401 on every call that the refresh below never fixed.
        return (new DateTimeImmutable($record->expiresAt, new \DateTimeZone('UTC')))->getTimestamp() - self::REFRESH_SKEW <= time();
    }

    /**
     * POST to the token endpoint. Visma wants the client credentials as HTTP Basic, not as form
     * fields.
     *
     * @throws Exception
     */
    private function requestToken(array $params): array
    {
        $settings = Plugin::getInstance()->getSettings();

        if (!$this->isConfigured()) {
            throw new Exception(Craft::t('vismaz', 'Vismaz has no Visma client ID or secret configured.'));
        }

        $started = microtime(true);

        $response = ($this->tokenClient ?? Craft::createGuzzleClient(['timeout' => 20]))->request(
            'POST',
            $settings->getIdentityBaseUrl() . '/connect/token',
            [
                'auth' => [$settings->getClientId(), $settings->getClientSecret()],
                'form_params' => $params,
                'http_errors' => false,
            ]
        );

        $status = $response->getStatusCode();
        $body = json_decode((string)$response->getBody(), true);

        Plugin::getInstance()->getLog()->write('auth.token', [
            'method' => 'POST',
            'url' => $settings->getIdentityBaseUrl() . '/connect/token',
            'statusCode' => $status,
            'durationMs' => (int)round((microtime(true) - $started) * 1000),
            'level' => $status === 200 ? Log::LEVEL_INFO : Log::LEVEL_ERROR,
            'requestBody' => ['grant_type' => $params['grant_type']],
            'responseBody' => is_array($body) ? $body : (string)$response->getBody(),
            'message' => $status === 200 ? 'Token granted (' . $params['grant_type'] . ').' : 'Token request failed.',
        ]);

        if ($status !== 200 || !is_array($body) || empty($body['access_token'])) {
            $reason = is_array($body)
                ? ($body['error_description'] ?? $body['error'] ?? 'HTTP ' . $status)
                : 'HTTP ' . $status;

            // A refresh token Visma will not honour is the connection dying: two years old, or
            // the Visma user changed their password. Only a 4xx says that — a 5xx is Visma having
            // a bad minute, and a network failure never gets this far. A refused *authorization
            // code* is somebody at the Connect button, who can see the error for themselves.
            if ($params['grant_type'] === 'refresh_token' && $status >= 400 && $status < 500) {
                Plugin::getInstance()->getAlerts()->noteAuthFailure(Craft::t('vismaz', 'Visma refused to renew the connection ({reason}).', ['reason' => $reason]));
            }

            throw new Exception(Craft::t('vismaz', 'Visma refused to issue an access token ({reason}).', ['reason' => $reason]));
        }

        return $body;
    }

    private function storeToken(array $payload, ?int $userId): TokenRecord
    {
        $environment = Plugin::getInstance()->getSettings()->environment;

        /** @var TokenRecord|null $record */
        $record = TokenRecord::find()->where(['environment' => $environment])->one();
        $record ??= new TokenRecord(['environment' => $environment]);

        $record->accessToken = self::encrypt($payload['access_token']);

        // A refresh response that omits the refresh token means "keep the one you have".
        if (!empty($payload['refresh_token'])) {
            $record->refreshToken = self::encrypt($payload['refresh_token']);
        }

        $record->expiresAt = Db::prepareDateForDb(
            (new DateTime())->modify('+' . (int)($payload['expires_in'] ?? 3600) . ' seconds')
        );
        $record->scope = $payload['scope'] ?? self::SCOPES;

        if ($userId !== null) {
            $record->connectedBy = $userId;
        }

        $record->save(false);
        $this->connection = $record;

        return $record;
    }

    /**
     * Encrypt a token for storage.
     *
     * `Security::encryptByKey()` returns **raw binary**, and writing that straight into a text
     * column on a utf8mb4 connection fails with `SQLSTATE[22007] Invalid datetime format: 1366
     * Incorrect string value` — an error that names a datetime problem for a token column. Base64
     * both ways. A refresh token is good for two years; it does not belong in the database in
     * plaintext.
     */
    private static function encrypt(string $value): string
    {
        return base64_encode(Craft::$app->getSecurity()->encryptByKey($value));
    }

    /**
     * Decrypt a stored token, or null if it cannot be read.
     *
     * A failure here means the security key changed — the connection is gone and the merchant has
     * to reconnect, which is better than a confusing 401 loop against Visma.
     */
    private static function decrypt(?string $value): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }

        $raw = base64_decode($value, true);

        if ($raw === false) {
            return null;
        }

        try {
            $plain = Craft::$app->getSecurity()->decryptByKey($raw);
        } catch (Throwable) {
            return null;
        }

        return $plain === false ? null : $plain;
    }

    /**
     * Ask Visma which company this token is for, so the CP can show it.
     *
     * Best-effort: a connection that works but cannot name itself is still a connection.
     */
    public function hydrateCompany(TokenRecord $record): void
    {
        try {
            $settings = Plugin::getInstance()->getApi()->get('companysettings');

            if (is_array($settings)) {
                $record->companyId = $settings['Id'] ?? null;
                $record->companyName = $settings['Name'] ?? null;
                $record->organisationNumber = $settings['CorporateIdentityNumber'] ?? null;
                $record->save(false);
            }
        } catch (Throwable $e) {
            Plugin::getInstance()->getLog()->warning('auth.company', $e->getMessage());
        }
    }
}
