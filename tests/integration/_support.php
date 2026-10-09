<?php
/**
 * Shared bootstrap for alerts.php and orders.php: Craft, the check runner, a captured mailer, the
 * Visma mock transport, a planted connection and order fixtures.
 *
 * Settings are only ever changed in memory here — nothing is written to project config — and
 * every fixture (orders, products, users, document/payment rows, the planted token, alert latches,
 * queued jobs) is registered for removal in a shutdown function, pass or fail.
 */

$root = getcwd();
require $root . '/bootstrap.php';

/** @var craft\console\Application $app */
$app = require CRAFT_VENDOR_PATH . '/craftcms/cms/bootstrap/console.php';

use craft\commerce\elements\Order;
use craft\commerce\elements\Product;
use craft\commerce\elements\Variant;
use craft\commerce\Plugin as Commerce;
use craft\db\Query;
use craft\elements\User;
use craft\helpers\Db;
use craft\helpers\StringHelper;
use craft\mail\Mailer;
use GuzzleHttp\Client;
use GuzzleHttp\Cookie\CookieJar;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
use GuzzleHttp\Psr7\Response as GuzzleResponse;
use justinholtweb\vismaz\db\Table;
use justinholtweb\vismaz\Plugin;
use justinholtweb\vismaz\services\Auth;
use yii\base\Event;
use yii\mail\BaseMailer;
use yii\mail\MailEvent;

$passed = 0;
$failed = 0;

function check(string $label, callable $test): void
{
    global $passed, $failed;

    try {
        $result = $test();

        if ($result === true) {
            $passed++;
            echo "  ✓ $label\n";

            return;
        }

        $failed++;
        echo "  ✗ $label\n    " . (is_string($result) ? $result : 'returned ' . var_export($result, true)) . "\n";
    } catch (Throwable $e) {
        $failed++;
        echo "  ✗ $label\n    " . get_class($e) . ': ' . $e->getMessage() . "\n    " . $e->getFile() . ':' . $e->getLine() . "\n";
    }
}

function section(string $title): void
{
    echo "\n$title\n";
}

function finish(): never
{
    global $passed, $failed;

    echo "\n$passed passed, $failed failed\n";
    exit($failed === 0 ? 0 : 1);
}

// craft-penny (a sibling in this shared harness) fatals every element save; detached in-process.
if (Craft::$app->getPlugins()->isPluginEnabled('penny')) {
    Event::off(craft\services\Elements::class, craft\services\Elements::EVENT_BEFORE_SAVE_ELEMENT);
}

$plugin = Plugin::getInstance();
$settings = $plugin->getSettings();
$originalSettings = $settings->toArray();
$suffix = bin2hex(random_bytes(3));
$cleanup = ['orders' => [], 'products' => [], 'users' => [], 'token' => false];

register_shutdown_function(function() use (&$cleanup, $plugin, $settings, $originalSettings) {
    $db = Craft::$app->getDb();
    $elements = Craft::$app->getElements();

    foreach ($cleanup['orders'] as $order) {
        try {
            $db->createCommand()->delete(Table::PAYMENTS, ['orderId' => $order->id])->execute();
            $db->createCommand()->delete(Table::LOG, ['orderId' => $order->id])->execute();
            $db->createCommand()->delete(Table::DOCUMENTORDERS, ['orderId' => $order->id])->execute();
            $db->createCommand()->delete(Table::DOCUMENTS, [
                'or',
                ['sourceKey' => 'order:' . $order->id],
                ['like', 'sourceKey', 'refund:' . $order->id . ':', false],
            ])->execute();
            $elements->deleteElement($order, true);
        } catch (Throwable $e) {
            echo "  ! could not delete order {$order->id}: {$e->getMessage()}\n";
        }
    }

    foreach (array_merge($cleanup['products'], $cleanup['users']) as $element) {
        try {
            $elements->deleteElement($element, true);
        } catch (Throwable $e) {
            echo "  ! could not delete {$element->id}: {$e->getMessage()}\n";
        }
    }

    // Rows this run made without an order (fixture vouchers), the latch, and anything queued.
    $db->createCommand()->delete(Table::DOCUMENTS, ['like', 'sourceKey', 'voucher:vz-test-%', false])->execute();
    $db->createCommand()->delete(Table::ALERTS)->execute();
    $db->createCommand()->delete(craft\db\Table::QUEUE, ['like', 'description', 'Visma', false])->execute();

    if ($cleanup['token']) {
        $db->createCommand()->delete(Table::TOKENS, ['environment' => $settings->environment])->execute();
    }

    $plugin->getApi()->setClient(null);
    $plugin->getAuth()->tokenClient = null;
    $settings->setAttributes($originalSettings, false);
});

// Completing a fixture order would otherwise queue a real push per order, and a saved transaction
// a real payment registration — which a CP request elsewhere in the shared harness could then run.
$settings->setAttributes([
    'autoPush' => false,
    'syncPayments' => false,
    'documentMode' => 'invoice',
    'syncStatusHandles' => [],
    'sendInvoiceFromVisma' => false,
], false);

// The real mailer on Symfony's null transport: the compose/send path runs in full, and the result
// does not depend on whether the harness's Mailpit is up. Everything sent is captured.
Craft::$app->getMailer()->setTransport(new Symfony\Component\Mailer\Transport\NullTransport());
$mail = [];
$mailFails = false;
Event::on(Mailer::class, BaseMailer::EVENT_BEFORE_SEND, function(MailEvent $e) use (&$mailFails) {
    if ($mailFails) {
        $e->isValid = false;
    }
});
Event::on(Mailer::class, BaseMailer::EVENT_AFTER_SEND, function(MailEvent $e) use (&$mail) {
    if ($e->isSuccessful) {
        $to = (array)$e->message->getTo();
        $mail[] = [
            'to' => array_map(static fn($k, $v) => is_string($k) ? $k : (string)$v, array_keys($to), $to),
            'subject' => (string)$e->message->getSubject(),
            // The decoded text part: the wire form is quoted-printable, which splits lines.
            'body' => (string)$e->message->getSymfonyEmail()->getTextBody(),
        ];
    }
});

// ---------------------------------------------------------------------------------------------
// Visma, mocked: the harness has no outbound network. One MockHandler serves both the API and the
// identity server's token endpoint, and the journal records every request in order.

$journal = [];

/**
 * @param array<int, GuzzleResponse|Throwable> $responses
 */
function mockVisma(array $responses): void
{
    global $journal, $plugin;

    $journal = [];
    $stack = HandlerStack::create(new MockHandler($responses));
    $stack->push(Middleware::history($journal));
    $client = new Client(['handler' => $stack]);

    $plugin->getApi()->setClient($client);
    $plugin->getAuth()->tokenClient = $client;
}

function vismaReply(int $status, mixed $body): GuzzleResponse
{
    return new GuzzleResponse($status, ['Content-Type' => 'application/json'], json_encode($body));
}

/**
 * @return string[] `METHOD path` for every request Vismaz made
 */
function sentRequests(): array
{
    global $journal;

    return array_map(static fn(array $e) => $e['request']->getMethod() . ' ' . $e['request']->getUri()->getPath(), $journal);
}

/**
 * Plant a connection (client credentials in memory, an encrypted token row), as a real connect
 * would leave it. Only when the harness has none of its own — a real connection is never touched.
 */
function connectFixture(string $accessToken = 'vz-fixture-access-token', int $expiresIn = 3600): void
{
    global $plugin, $settings, $cleanup;

    $settings->setAttributes([
        'clientId' => 'vz-fixture-client',
        'clientSecret' => 'vz-fixture-secret-abcdef',
    ], false);

    $existing = (new Query())->from(Table::TOKENS)->where(['environment' => $settings->environment])->one();

    if ($existing && !$cleanup['token']) {
        throw new RuntimeException('The harness has a real Visma connection; these checks will not overwrite it.');
    }

    (new ReflectionMethod(Auth::class, 'storeToken'))->invoke($plugin->getAuth(), [
        'access_token' => $accessToken,
        'refresh_token' => 'vz-fixture-refresh-123456',
        'expires_in' => $expiresIn,
    ], null);
    $cleanup['token'] = true;
    $plugin->getAuth()->getConnection(true);
}

function expireToken(): void
{
    global $plugin, $settings;

    Craft::$app->getDb()->createCommand()->update(Table::TOKENS, ['expiresAt' => Db::prepareDateForDb(new DateTime('-1 hour'))], ['environment' => $settings->environment])->execute();
    $plugin->getAuth()->getConnection(true);
}

function disconnectFixture(): void
{
    global $plugin, $settings, $cleanup;

    Craft::$app->getDb()->createCommand()->delete(Table::TOKENS, ['environment' => $settings->environment])->execute();
    $cleanup['token'] = false;
    $plugin->getAuth()->getConnection(true);
}

// ---------------------------------------------------------------------------------------------
// Commerce fixtures.

function makeVariant(float $price = 100.0): Variant
{
    global $cleanup, $suffix;

    $type = Commerce::getInstance()->getProductTypes()->getAllProductTypes()[0];
    $sku = 'vz-fx-' . $suffix . '-' . count($cleanup['products']);
    $product = new Product(['typeId' => $type->id, 'title' => "Vismaz fixture $sku", 'enabled' => true]);
    $variant = new Variant(['sku' => $sku, 'basePrice' => $price, 'isDefault' => true]);
    $product->setVariants([$variant]);
    Craft::$app->getElements()->saveElement($product) or throw new RuntimeException('Could not save product: ' . json_encode($product->getErrors()));
    $cleanup['products'][] = $product;

    return $product->getDefaultVariant();
}

function makeOrder(Variant $variant, bool $complete = true): Order
{
    global $cleanup;

    $commerce = Commerce::getInstance();
    $order = new Order();
    $order->storeId = $commerce->getStores()->getPrimaryStore()->id;
    $order->orderSiteId = Craft::$app->getSites()->getPrimarySite()->id;
    $order->number = $commerce->getCarts()->generateCartNumber();
    $order->setEmail('vismaz-fixture@example.com');
    Craft::$app->getElements()->saveElement($order, false) or throw new RuntimeException('Could not save order');
    $cleanup['orders'][] = $order;

    $order->setLineItems([$commerce->getLineItems()->createLineItem($order, $variant->id, [], 1)]);
    $address = ['fullName' => 'Dana Fixture', 'addressLine1' => 'Storgatan 1', 'locality' => 'Stockholm', 'postalCode' => '11122', 'countryCode' => 'SE'];
    $order->setShippingAddress($address);
    $order->setBillingAddress($address);
    Craft::$app->getElements()->saveElement($order, false) or throw new RuntimeException('Could not save order lines');

    if ($complete) {
        $order->markAsComplete();
    }

    return $order;
}

function reloadOrder(Order $order): Order
{
    return Order::find()->id($order->id)->status(null)->one();
}

/**
 * A document row as `Sync::record()` would leave it, written directly so a status can be set up
 * without a push. Pass `linkOrderId` for the join row a successful push writes.
 */
function documentRow(string $type, string $sourceKey, string $status, array $extra = [], ?int $linkOrderId = null): int
{
    $db = Craft::$app->getDb();
    $now = Db::prepareDateForDb(new DateTime());
    $db->createCommand()->insert(Table::DOCUMENTS, array_merge([
        'type' => $type,
        'sourceKey' => $sourceKey,
        'status' => $status,
        'currency' => 'SEK',
        'grossTotal' => 125,
        'attempts' => 1,
        'dateCreated' => $now,
        'dateUpdated' => $now,
        'uid' => StringHelper::UUID(),
    ], $extra))->execute();
    $id = (int)$db->getLastInsertID();

    if ($linkOrderId !== null) {
        $db->createCommand()->insert(Table::DOCUMENTORDERS, ['documentId' => $id, 'orderId' => $linkOrderId, 'dateCreated' => $now, 'dateUpdated' => $now, 'uid' => StringHelper::UUID()])->execute();
    }

    return $id;
}

function paymentRow(int $orderId, string $status, array $extra = []): int
{
    static $tx = 0;

    $db = Craft::$app->getDb();
    $now = Db::prepareDateForDb(new DateTime());
    $db->createCommand()->insert(Table::PAYMENTS, array_merge([
        // Fixture transaction ids far above anything Commerce will hand out in the harness.
        'transactionId' => 900000000 + $orderId * 10 + (++$tx % 10),
        'orderId' => $orderId,
        'status' => $status,
        'amount' => 125,
        'currency' => 'SEK',
        'attempts' => 1,
        'lastError' => $status === 'failed' ? 'No Visma bank account is mapped for the “dummy” gateway, and there is no default.' : null,
        'dateCreated' => $now,
        'dateUpdated' => $now,
        'uid' => StringHelper::UUID(),
    ], $extra))->execute();

    return (int)$db->getLastInsertID();
}

// ---------------------------------------------------------------------------------------------
// HTTP against the harness's own web server, as a real signed-in user.

function makeUser(string $handle, array $permissions): array
{
    global $cleanup, $suffix;

    $password = 'Vismaz-' . bin2hex(random_bytes(6));
    $user = new User(['username' => "vismaz-$handle-$suffix", 'email' => "vismaz-$handle-$suffix@example.com", 'newPassword' => $password]);
    Craft::$app->getElements()->saveElement($user, false) or throw new RuntimeException('Could not save user');
    Craft::$app->getUsers()->activateUser($user);
    Craft::$app->getUserPermissions()->saveUserPermissions($user->id, $permissions);
    $cleanup['users'][] = $user;

    return [$user, $password];
}

/**
 * A signed-in client. Returns `fn(action, params, method, withCsrf, json)`.
 */
function client(?string $username, ?string $password): Closure
{
    $http = new Client(['base_uri' => 'http://localhost/', 'cookies' => new CookieJar(), 'http_errors' => false, 'allow_redirects' => false, 'timeout' => 60]);
    $accept = ['Accept' => 'application/json'];
    $csrf = static fn() => (string)(json_decode((string)$http->get('index.php?p=admin/actions/users/session-info', ['headers' => $accept])->getBody(), true)['csrfTokenValue'] ?? '');

    if ($username !== null) {
        $response = $http->post('index.php?p=admin/actions/users/login', ['headers' => $accept, 'form_params' => ['loginName' => $username, 'password' => $password, 'CRAFT_CSRF_TOKEN' => $csrf()]]);
        $response->getStatusCode() === 200
            or throw new RuntimeException("Could not sign in as $username: " . $response->getStatusCode() . ' ' . substr((string)$response->getBody(), 0, 160));
    }

    return static function(string $action, array $params = [], string $method = 'POST', bool $withCsrf = true, bool $json = false) use ($http, $accept, $csrf) {
        $options = ['headers' => $accept];

        if ($method === 'POST' && $json) {
            $options['json'] = $params;
            $options['headers'] += $withCsrf ? ['X-CSRF-Token' => $csrf()] : [];
        } elseif ($method === 'POST') {
            $options['form_params'] = $params + ($withCsrf ? ['CRAFT_CSRF_TOKEN' => $csrf()] : []);
        }

        return $http->request($method, "index.php?p=admin/actions/$action", $options);
    };
}
