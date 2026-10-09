<?php

namespace justinholtweb\vismaz\services;

use Craft;
use GuzzleHttp\Client;
use justinholtweb\vismaz\errors\ApiException;
use justinholtweb\vismaz\Plugin;
use Throwable;
use yii\base\Component;
use yii\base\Exception;

/**
 * The HTTP layer for the Visma eAccounting API.
 *
 * Everything that talks to Visma goes through `request()`, which owns four behaviours that must
 * not be reimplemented anywhere else:
 *
 *  - **Bearer auth**, with a single transparent retry after a refresh on a 401. A token can
 *    expire between the check and the call, and losing a whole sync run to a one-second race is
 *    not acceptable.
 *  - **429 backoff.** Visma allows 600 requests/minute per client per endpoint and answers
 *    `429` with application error `4010` and a "try again in N seconds" message. A sync of a
 *    day's orders will hit this, and hitting it is not an error — it is a wait.
 *  - **Logging**, so every request is answerable for afterwards.
 *  - **OData paging**, because `$top` caps out and the list endpoints will otherwise quietly
 *    return a first page that looks like the whole answer.
 */
class Api extends Component
{
    private const MAX_RETRIES = 3;
    private const PAGE_SIZE = 200;
    private const TIMEOUT = 30;

    /** Visma's application-level code for a rate limit. */
    private const ERROR_RATE_LIMITED = 4010;

    private ?Client $client = null;

    public function get(string $path, array $query = []): mixed
    {
        return $this->request('GET', $path, null, $query);
    }

    public function post(string $path, array $body): mixed
    {
        return $this->request('POST', $path, $body);
    }

    public function put(string $path, array $body): mixed
    {
        return $this->request('PUT', $path, $body);
    }

    public function delete(string $path): mixed
    {
        return $this->request('DELETE', $path);
    }

    /**
     * Fetch every page of a list endpoint.
     *
     * Visma answers list endpoints with `{Meta: {…}, Data: [...]}`. Reading `Data` and stopping
     * is the mistake this exists to prevent.
     */
    public function getAll(string $path, array $query = [], int $maxPages = 100): array
    {
        $results = [];
        $page = 1;

        do {
            $response = $this->get($path, $query + ['$top' => self::PAGE_SIZE, '$skip' => ($page - 1) * self::PAGE_SIZE]);
            $data = is_array($response) ? ($response['Data'] ?? $response) : [];

            if (!is_array($data) || $data === []) {
                break;
            }

            $results = array_merge($results, $data);
            $page++;
        } while (count($data) === self::PAGE_SIZE && $page <= $maxPages);

        return $results;
    }

    /**
     * A single request, with auth, retries, backoff and logging.
     *
     * @throws Exception on anything the caller cannot recover from — an `ApiException`, carrying
     * the HTTP status, once Visma has been asked.
     */
    public function request(string $method, string $path, ?array $body = null, array $query = [], array $context = []): mixed
    {
        $auth = Plugin::getInstance()->getAuth();
        $log = Plugin::getInstance()->getLog();
        $settings = Plugin::getInstance()->getSettings();

        $token = $auth->getAccessToken();

        if ($token === null) {
            throw new Exception(Craft::t('vismaz', 'Vismaz is not connected to Visma.'));
        }

        $url = $settings->getApiBaseUrl() . ltrim($path, '/');
        $attempt = 0;
        $refreshed = false;

        while (true) {
            $attempt++;
            $started = microtime(true);

            try {
                $response = $this->getClient()->request($method, $url, array_filter([
                    'headers' => [
                        'Authorization' => 'Bearer ' . $token,
                        'Accept' => 'application/json',
                        'Content-Type' => 'application/json',
                    ],
                    'query' => $query ?: null,
                    'json' => $body,
                    'http_errors' => false,
                ], static fn($v): bool => $v !== null));
            } catch (Throwable $e) {
                $log->error('api.request', $e->getMessage(), [
                    'method' => $method, 'url' => $url, 'requestBody' => $body,
                ] + $context);

                if ($attempt < self::MAX_RETRIES) {
                    $this->sleep($attempt);
                    continue;
                }

                throw new ApiException(Craft::t('vismaz', 'Could not reach Visma: {message}', ['message' => $e->getMessage()]), null, $e);
            }

            $status = $response->getStatusCode();
            $raw = (string)$response->getBody();
            $decoded = $raw === '' ? null : json_decode($raw, true);
            $durationMs = (int)round((microtime(true) - $started) * 1000);

            // A token can expire between the pre-flight check and the call landing. One
            // transparent refresh-and-retry, then give up — a second 401 is a real problem.
            if ($status === 401 && !$refreshed) {
                $refreshed = true;
                // Ask Auth for the token rather than reading the record: what is stored is
                // ciphertext, and only Auth holds the key.
                $auth->refresh(null, $token);
                $refreshedToken = $auth->getAccessToken();

                if ($refreshedToken !== null && $refreshedToken !== $token) {
                    $token = $refreshedToken;
                    continue;
                }
            }

            if ($status === 429 && $attempt < self::MAX_RETRIES) {
                $wait = $this->retryAfter($response->getHeaderLine('Retry-After'), $decoded, $attempt);

                $log->warning('api.ratelimit', Craft::t('vismaz', 'Rate limited by Visma; waiting {seconds}s.', ['seconds' => $wait]), [
                    'method' => $method, 'url' => $url, 'statusCode' => $status, 'durationMs' => $durationMs,
                ] + $context);

                sleep($wait);
                continue;
            }

            $log->write('api.request', [
                'method' => $method,
                'url' => $url,
                'statusCode' => $status,
                'durationMs' => $durationMs,
                'level' => $status < 400 ? Log::LEVEL_INFO : Log::LEVEL_ERROR,
                'requestBody' => $body,
                'responseBody' => $decoded ?? $raw,
                'message' => $status < 400 ? null : self::describeError($decoded, $status),
            ] + $context);

            if ($status === 401) {
                // Still refused after a refresh (or the refresh itself was refused): the token is
                // not the problem, the grant behind it is — revoked, or the Visma user changed
                // their password. Somebody has to reconnect, and Visma will not say so twice.
                Plugin::getInstance()->getAlerts()->noteAuthFailure(Craft::t('vismaz', 'Visma still answered 401 after the access token was renewed ({message}).', [
                    'message' => self::describeError($decoded, $status),
                ]));
            }

            if ($status >= 400) {
                throw new ApiException(self::describeError($decoded, $status), $status);
            }

            Plugin::getInstance()->getAlerts()->noteAuthSuccess();

            return $decoded;
        }
    }

    /**
     * Whether the connection works right now. Used by the "Test connection" button, so it
     * answers with a message rather than throwing.
     *
     * @return array{ok: bool, message: string, company: ?string}
     */
    public function test(): array
    {
        try {
            $settings = $this->get('companysettings');

            return [
                'ok' => true,
                'company' => $settings['Name'] ?? null,
                'message' => Craft::t('vismaz', 'Connected to {company}.', ['company' => $settings['Name'] ?? 'Visma']),
            ];
        } catch (Throwable $e) {
            return ['ok' => false, 'company' => null, 'message' => $e->getMessage()];
        }
    }

    /**
     * Swap the HTTP client. The integration checks hand it one built on a Guzzle `MockHandler`,
     * because the test harness has no route to Visma; null goes back to the real one.
     */
    public function setClient(?Client $client): void
    {
        $this->client = $client;
    }

    private function getClient(): Client
    {
        return $this->client ??= Craft::createGuzzleClient([
            'timeout' => self::TIMEOUT,
            'connect_timeout' => 10,
        ]);
    }

    /**
     * How long to wait after a 429.
     *
     * Visma's `Retry-After` is often absent, and the useful number is buried in the message
     * ("Try again in 55 seconds"). Falling back to exponential backoff when neither is readable.
     */
    private function retryAfter(string $header, mixed $decoded, int $attempt): int
    {
        if (is_numeric($header) && (int)$header > 0) {
            return min(120, (int)$header);
        }

        if (is_array($decoded)) {
            $message = (string)($decoded['DeveloperErrorMessage'] ?? $decoded['ErrorMessage'] ?? '');

            if (preg_match('/(\d+)\s*second/i', $message, $matches)) {
                return min(120, (int)$matches[1] + 1);
            }
        }

        return min(60, 2 ** $attempt * 5);
    }

    private function sleep(int $attempt): void
    {
        sleep(min(10, 2 ** $attempt));
    }

    /**
     * Turn a Visma error body into something a merchant can act on.
     *
     * Visma returns validation failures as an `ErrorMessages` array keyed by field; flattening
     * it is the difference between "HTTP 400" and "CustomerId is required".
     */
    public static function describeError(mixed $decoded, int $status): string
    {
        if (!is_array($decoded)) {
            return Craft::t('vismaz', 'Visma returned HTTP {status}.', ['status' => $status]);
        }

        if (!empty($decoded['ErrorMessages']) && is_array($decoded['ErrorMessages'])) {
            $parts = [];

            foreach ($decoded['ErrorMessages'] as $field => $messages) {
                $messages = is_array($messages) ? $messages : [$messages];

                foreach ($messages as $message) {
                    $parts[] = is_string($field) && !is_numeric($field)
                        ? sprintf('%s: %s', $field, $message)
                        : (string)$message;
                }
            }

            if ($parts) {
                return implode('; ', array_slice($parts, 0, 8));
            }
        }

        $message = $decoded['DeveloperErrorMessage']
            ?? $decoded['ErrorMessage']
            ?? $decoded['Message']
            ?? $decoded['error_description']
            ?? null;

        if ($message !== null) {
            $code = $decoded['ErrorCode'] ?? null;

            return $code === self::ERROR_RATE_LIMITED
                ? Craft::t('vismaz', 'Visma rate limit reached. {message}', ['message' => $message])
                : (string)$message;
        }

        return Craft::t('vismaz', 'Visma returned HTTP {status}.', ['status' => $status]);
    }
}
