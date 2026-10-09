<?php
/**
 * Failure alerts — Vismaz's port of the connector-family pattern (reference: craft-erpy 0f8ea45,
 * by way of craft-zo bf07a0f).
 *
 *     docker exec -w /var/www/html ddev-plugin-testing-web php /var/www/craft-vismaz/tests/integration/alerts.php
 *
 * Drives the real latch, the real mailer and the real webhook code: failed pushes, mismatched
 * documents, failed payment registrations, a refused refresh token, a 401 that refreshing does not
 * fix and a stalled queue each open exactly one incident, send exactly one alert, and send exactly
 * one recovery. Visma and the webhook receiver are Guzzle MockHandlers (the harness has no
 * outbound network); the webhook still passes the SSRF guard for real. Settings stay in memory.
 */

require __DIR__ . '/_support.php';

use craft\commerce\db\Table as CommerceTable;
use craft\commerce\Plugin as Commerce;
use craft\db\Query;
use craft\elements\User;
use craft\helpers\Db;
use craft\web\View;
use GuzzleHttp\Client;
use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
use GuzzleHttp\Psr7\Request as Psr7Request;
use GuzzleHttp\Psr7\Response as Psr7Response;
use justinholtweb\vismaz\db\Table;
use justinholtweb\vismaz\events\AlertEvent;
use justinholtweb\vismaz\models\Settings;
use justinholtweb\vismaz\services\Alerts;
use justinholtweb\vismaz\widgets\HealthWidget;

$alerts = $plugin->getAlerts();
$db = Craft::$app->getDb();

// The webhook receiver.
$history = [];
$hookMock = new MockHandler();
$hookStack = HandlerStack::create($hookMock);
$hookStack->push(Middleware::history($history));
$alerts->webhookClient = new Client(['handler' => $hookStack]);

$reset = function(array $overrides = []) use ($settings, &$mail, &$history, $hookMock) {
    $settings->setAttributes(array_merge([
        'alertRecipients' => 'ops@example.test, books@example.test',
        'alertWebhookUrl' => '',
        'alertWebhookSecret' => '',
        'alertWebhookFormat' => 'slack',
        'alertOnFailures' => true,
        'alertFailureThreshold' => 2,
        'alertWindowMinutes' => 60,
        'alertOnMismatch' => true,
        'alertOnPayments' => true,
        'alertOnAuthFailure' => true,
        'alertStallHours' => 0,
        'alertCooldownMinutes' => 0,
        'allowPrivateAlertWebhookHosts' => false,
    ], $overrides), false);
    $mail = [];
    $history = [];
    $hookMock->reset();
};

$latch = fn(string $incident) => (new Query())->from(Table::ALERTS)->where(['incident' => $incident])->one() ?: [];
$ago = fn(string $modify) => Db::prepareDateForDb((new DateTime())->modify($modify));
$variant = makeVariant();

// Every document and payment row this run makes belongs to one of these orders, so "age every
// failure" never touches anything else in the harness.
$fixtureOrders = [];
$order = function() use ($variant, &$fixtureOrders) {
    $o = makeOrder($variant);
    $fixtureOrders[] = (int)$o->id;

    return $o;
};
$ageDocuments = function(string $modify, ?string $status = null) use ($db, $ago, &$fixtureOrders) {
    $keys = array_map(static fn(int $id) => 'order:' . $id, $fixtureOrders);
    $db->createCommand()->update(Table::DOCUMENTS, ['dateUpdated' => $ago($modify)], array_filter(['sourceKey' => $keys, 'status' => $status]))->execute();
};
$agePayments = function(string $modify) use ($db, $ago, &$fixtureOrders) {
    $db->createCommand()->update(Table::PAYMENTS, ['dateUpdated' => $ago($modify)], ['orderId' => $fixtureOrders])->execute();
};
$failedDocument = function(string $error = 'CustomerId: The customer does not exist.') use ($order) {
    return documentRow('invoice', 'order:' . $order()->id, 'failed', ['lastError' => $error]);
};

$db->createCommand()->delete(Table::ALERTS)->execute();

// Anything already failed in the harness would count towards this run's windows.
$foreign = (int)(new Query())->from(Table::DOCUMENTS)->where(['status' => ['failed', 'mismatched', 'pending']])->count()
    + (int)(new Query())->from(Table::PAYMENTS)->where(['status' => ['failed', 'pending', 'waiting']])->count();

if ($foreign > 0) {
    echo "  ! the harness already holds $foreign failed/pending Vismaz rows; ageing them out of the window for this run\n";
    $db->createCommand()->update(Table::DOCUMENTS, ['dateUpdated' => $ago('-2 days')], ['status' => ['failed', 'mismatched']])->execute();
    $db->createCommand()->update(Table::PAYMENTS, ['dateUpdated' => $ago('-2 days')], ['status' => 'failed'])->execute();
}

// ---------------------------------------------------------------------------------------------
section('Settings');

check('a fresh install saves with no recipients and no webhook (nothing is required)', function() use ($originalSettings) {
    $s = new Settings(array_merge($originalSettings, ['alertRecipients' => '', 'alertWebhookUrl' => '']));

    return $s->validate() ?: json_encode($s->getErrors());
});

check('a bad address is refused, and named', function() use ($originalSettings) {
    $s = new Settings(array_merge($originalSettings, ['alertRecipients' => 'ops@example.test, not-an-address']));

    return !$s->validate() && str_contains(implode(' ', $s->getErrors('alertRecipients')), 'not-an-address') ?: json_encode($s->getErrors());
});

check('an unset $ENV reference is allowed and means nobody', function() use ($originalSettings) {
    $s = new Settings(array_merge($originalSettings, ['alertRecipients' => '$VZ_ALERTS_NOT_SET', 'alertWebhookUrl' => '$VZ_HOOK_NOT_SET']));

    return $s->validate() && $s->recipientList() === [] ?: json_encode($s->getErrors());
});

check('a non-http webhook URL is refused at save', function() use ($originalSettings) {
    $s = new Settings(array_merge($originalSettings, ['alertWebhookUrl' => 'ftp://hooks.example.test/x']));

    return !$s->validate() && $s->hasErrors('alertWebhookUrl') ?: 'accepted';
});

check('recipients split on commas, semicolons and newlines, de-duplicated', function() {
    $s = new Settings(['alertRecipients' => "a@example.test; b@example.test\nc@example.test, a@example.test"]);

    return $s->recipientList() === ['a@example.test', 'b@example.test', 'c@example.test'] ?: json_encode($s->recipientList());
});

check('the alert settings are attributes, so they persist', function() use ($settings) {
    $missing = array_diff(['alertRecipients', 'alertWebhookUrl', 'alertWebhookFormat', 'alertWebhookSecret', 'alertOnFailures', 'alertFailureThreshold', 'alertWindowMinutes', 'alertOnMismatch', 'alertOnPayments', 'alertOnAuthFailure', 'alertStallHours', 'alertCooldownMinutes'], array_keys($settings->toArray()));

    return $missing === [] ?: implode(', ', $missing);
});

check('stall hours of 0 are allowed (off); a negative window is not', function() use ($originalSettings) {
    $off = new Settings(array_merge($originalSettings, ['alertStallHours' => 0]));
    $bad = new Settings(array_merge($originalSettings, ['alertWindowMinutes' => -5]));

    return $off->validate() && !$bad->validate() ?: json_encode([$off->getErrors(), $bad->getErrors()]);
});

// ---------------------------------------------------------------------------------------------
section('Not connected');

check('an install that is not connected checks nothing and records nothing', function() use ($alerts, $plugin) {
    $connected = $plugin->getAuth()->isConnected();

    return $connected || ($alerts->check() === [] && !(new Query())->from(Table::ALERTS)->exists()) ?: 'checked anyway';
});

connectFixture();
$reset();

// ---------------------------------------------------------------------------------------------
section('The SSRF guard on the webhook');

foreach ([
    'http://127.0.0.1/hook' => 'loopback',
    'http://169.254.169.254/latest/meta-data/' => 'the cloud metadata service',
    'http://10.1.2.3/hook' => 'a private address',
    'http://[::1]/hook' => 'IPv6 loopback',
    'http://[::ffff:127.0.0.1]/hook' => 'IPv4-mapped loopback',
    'http://100.64.0.1/hook' => 'carrier-grade NAT',
    'ftp://93.184.215.14/hook' => 'a non-http scheme',
    'https://user:pass@93.184.215.14/hook' => 'credentials in the URL',
] as $url => $what) {
    check("refuses $what", function() use ($alerts, $url) {
        return is_string($alerts->webhookTarget($url)) ?: 'allowed';
    });
}

check('a public address is allowed, and pinned', function() use ($alerts) {
    $t = $alerts->webhookTarget('https://93.184.215.14/hook');

    return is_array($t) && $t['addresses'] === ['93.184.215.14'] && $t['port'] === 443 ?: json_encode($t);
});

check('a refused URL is never requested', function() use ($alerts, &$history) {
    $result = $alerts->postWebhook('http://127.0.0.1:8080/hook', ['text' => 'x']);

    return is_string($result) && $history === [] ?: 'requested: ' . count($history);
});

check('the send pins the address, refuses redirects and does not throw on a 4xx', function() use ($alerts, $hookMock, &$history) {
    $hookMock->append(new Psr7Response(404));
    $result = $alerts->postWebhook('https://93.184.215.14/hook', ['text' => 'x']);
    $options = $history[0]['options'] ?? [];
    $pin = $options['curl'][CURLOPT_RESOLVE][0] ?? '';

    return $result === 'HTTP 404' && $pin === '93.184.215.14:443:93.184.215.14' && ($options['allow_redirects'] ?? null) === false
        ?: json_encode(['result' => $result, 'pin' => $pin]);
});

check('allowPrivateAlertWebhookHosts lets a LAN host through, unpinned', function() use ($alerts, $settings) {
    $settings->allowPrivateAlertWebhookHosts = true;
    $t = $alerts->webhookTarget('http://10.1.2.3/hook');
    $settings->allowPrivateAlertWebhookHosts = false;

    return is_array($t) && $t['addresses'] === [] ?: json_encode($t);
});

check('the IP helper is the family copy, unchanged apart from the namespace', function() {
    $mine = (string)file_get_contents(dirname(__DIR__, 2) . '/src/helpers/Ip.php');
    // Everything from the class down, with the plugin's own name taken out of the prose.
    $body = static fn(string $src) => str_replace(['Vismaz', 'Zo'], 'PLUGIN', substr($src, (int)strpos($src, 'class Ip')));
    $zo = '/var/www/craft-zo/src/helpers/Ip.php';

    return !is_file($zo) || $body($mine) === $body((string)file_get_contents($zo)) ?: 'the copy has drifted from craft-zo';
});

$reset();

// ---------------------------------------------------------------------------------------------
section('Redaction');

check('the client secret, refresh token and access token are taken out by value', function() use ($alerts) {
    $out = $alerts->redact('Visma said: bad secret vz-fixture-secret-abcdef for vz-fixture-refresh-123456 and vz-fixture-access-token');

    return !str_contains($out, 'vz-fixture-secret-abcdef') && !str_contains($out, 'vz-fixture-refresh-123456') && !str_contains($out, 'vz-fixture-access-token') ?: $out;
});

check('anything shaped like a credential is taken out by pattern', function() use ($alerts) {
    $out = $alerts->redact('Authorization: Bearer eyJhbGciOiJSUzI1NiJ9.abc {"client_secret":"hunter22xyz"} refresh_token=aaaabbbbcccc&x=1');

    return !str_contains($out, 'eyJhbGciOiJSUzI1NiJ9') && !str_contains($out, 'hunter22xyz') && !str_contains($out, 'aaaabbbbcccc') ?: $out;
});

check('Visma’s own error text survives redaction', function() use ($alerts) {
    $out = $alerts->redact('CustomerId: The customer does not exist. ErrorCode 4010');

    return str_contains($out, 'The customer does not exist') && str_contains($out, '4010') ?: $out;
});

check('Vismaz’s own token-refusal message survives redaction whole', function() use ($alerts, $plugin) {
    // A refused token request becomes the document's error and so the alert's "Latest:" line,
    // and the credential pattern eats the word after "token " — it used to read "the token ••••".
    $auth = $plugin->getAuth();
    $previous = $auth->tokenClient;
    $auth->tokenClient = new Client(['handler' => HandlerStack::create(new MockHandler([vismaReply(400, ['error' => 'invalid_client'])]))]);
    $text = null;

    try {
        (new ReflectionMethod($auth, 'requestToken'))->invoke($auth, ['grant_type' => 'authorization_code', 'code' => 'fixture-code']);
    } catch (Throwable $e) {
        $text = $e->getMessage();
    }

    $auth->tokenClient = $previous;

    return $text !== null && str_contains($text, 'invalid_client') && $alerts->redact($text) === $text ?: (string)$text . ' => ' . $alerts->redact((string)$text);
});

check('tags are stripped and the length is capped', function() use ($alerts) {
    $out = $alerts->redact('<b>' . str_repeat('x', 900) . '</b>');

    return !str_contains($out, '<b>') && mb_strlen($out) === 500 ?: mb_strlen($out) . ' chars';
});

// ---------------------------------------------------------------------------------------------
section('Orders failing to reach Visma');

check('below the threshold, nothing opens and nothing is sent', function() use ($alerts, $failedDocument, $latch, &$mail) {
    $failedDocument();
    $alerts->check([Alerts::INCIDENT_FAILURES]);

    return ($latch(Alerts::INCIDENT_FAILURES)['state'] ?? null) === 'ok' && $mail === [] ?: json_encode($latch(Alerts::INCIDENT_FAILURES));
});

check('reaching it opens the incident and sends one email to every recipient', function() use ($alerts, $failedDocument, $latch, &$mail) {
    $failedDocument('ArticleId: The article is inactive.');
    $results = $alerts->check([Alerts::INCIDENT_FAILURES]);

    return ($latch(Alerts::INCIDENT_FAILURES)['state'] ?? null) === 'open' && count($mail) === 1
        && $mail[0]['to'] === ['ops@example.test', 'books@example.test'] && ($results[0]['transition'] ?? null) === 'opened'
        ?: json_encode(['mail' => count($mail), 'results' => $results]);
});

check('the email says what failed and links the Documents screen filtered to failures', function() use (&$mail) {
    $body = $mail[0]['body'] ?? '';

    return str_contains($mail[0]['subject'] ?? '', 'Orders failing to reach Visma')
        && str_contains($body, 'The article is inactive')
        && str_contains($body, 'vismaz/documents') && str_contains($body, 'status=failed')
        ?: substr($body, 0, 600);
});

check('it stays quiet while open, however often it is checked', function() use ($alerts, $failedDocument, &$mail) {
    $failedDocument();
    $alerts->check();
    $alerts->check();

    return count($mail) === 1 ?: count($mail) . ' emails';
});

check('a push that fails evaluates alerts by itself — no cron', function() use ($plugin, $order, $latch, &$mail, $reset, $db, $ageDocuments) {
    $reset(['alertFailureThreshold' => 1]);
    $db->createCommand()->delete(Table::ALERTS)->execute();
    $ageDocuments('-1 day');

    $o = $order();
    $document = $plugin->getDocuments()->buildInvoice(reloadOrder($o), false);
    mockVisma([vismaReply(400, ['ErrorMessages' => ['CustomerId' => ['The customer does not exist.']]])]);
    $result = $plugin->getSync()->push($document);

    return $result['status'] === 'failed' && ($latch(Alerts::INCIDENT_FAILURES)['state'] ?? null) === 'open' && count($mail) === 1
        && str_contains($mail[0]['body'], 'The customer does not exist') && str_contains($mail[0]['body'], 'order:' . $o->id)
        ?: json_encode(['result' => $result['status'], 'latch' => $latch(Alerts::INCIDENT_FAILURES), 'mail' => count($mail)]);
});

check('a whole quiet window recovers it, with one recovery that says what is still failed', function() use ($alerts, $latch, &$mail, $ageDocuments) {
    $mail = [];
    $ageDocuments('-2 hours', 'failed');
    $results = $alerts->check([Alerts::INCIDENT_FAILURES]);
    $alerts->check([Alerts::INCIDENT_FAILURES]);
    $body = $mail[0]['body'] ?? '';

    return ($latch(Alerts::INCIDENT_FAILURES)['state'] ?? null) === 'ok' && count($mail) === 1
        && str_contains($mail[0]['subject'], 'Recovered') && str_contains($body, 'still show as failed')
        && ($results[0]['transition'] ?? null) === 'recovered'
        ?: json_encode(['mail' => count($mail), 'body' => $body]);
});

check('a reopening inside the quiet period is held, then sent once it ends', function() use ($alerts, $failedDocument, $latch, $db, $ago, &$mail) {
    $mail = [];
    $db->createCommand()->update(Table::ALERTS, ['quietUntil' => $ago('+30 minutes')], ['incident' => Alerts::INCIDENT_FAILURES])->execute();
    $failedDocument();
    $alerts->check([Alerts::INCIDENT_FAILURES]);
    $held = ($latch(Alerts::INCIDENT_FAILURES)['state'] ?? null) === 'open' && $mail === [];

    $db->createCommand()->update(Table::ALERTS, ['quietUntil' => $ago('-1 minute')], ['incident' => Alerts::INCIDENT_FAILURES])->execute();
    $alerts->check([Alerts::INCIDENT_FAILURES]);

    return $held && count($mail) === 1 ?: json_encode(['held' => $held, 'mail' => count($mail)]);
});

check('a failed send is released and retried on the next check, not lost', function() use ($alerts, $latch, &$mail, &$mailFails, $ageDocuments) {
    $mail = [];
    $ageDocuments('-2 hours', 'failed');
    $mailFails = true;
    $alerts->check([Alerts::INCIDENT_FAILURES]);
    $mailFails = false;
    $row = $latch(Alerts::INCIDENT_FAILURES);
    $owed = array_key_exists('recoveryNotifiedAt', $row) && $row['recoveryNotifiedAt'] === null;
    $alerts->check([Alerts::INCIDENT_FAILURES]);

    return $owed && count($mail) === 1 && ($latch(Alerts::INCIDENT_FAILURES)['recoveryNotifiedAt'] ?? null) !== null
        ?: json_encode(['owed' => $owed, 'mail' => count($mail)]);
});

check('switched off, failures alert nobody', function() use ($alerts, $failedDocument, $latch, &$mail, $reset) {
    $reset(['alertOnFailures' => false, 'alertFailureThreshold' => 1]);
    $failedDocument();
    $alerts->check([Alerts::INCIDENT_FAILURES]);

    return ($latch(Alerts::INCIDENT_FAILURES)['state'] ?? null) === 'ok' && $mail === [] ?: 'alerted';
});

$ageDocuments('-1 day');

// ---------------------------------------------------------------------------------------------
section('Documents booked at the wrong total');

$reset();

check('a push Visma books at another total opens it, by itself, naming the invoice', function() use ($plugin, $order, $latch, &$mail, &$journal) {
    $o = $order();
    $document = $plugin->getDocuments()->buildInvoice(reloadOrder($o), false);
    mockVisma([vismaReply(201, ['Id' => 'a1b2c3d4-0000-4000-8000-0000000000aa', 'InvoiceNumber' => 7001, 'TotalAmount' => $document->getGrossTotal() + 10])]);
    $result = $plugin->getSync()->push($document);
    $body = $mail[0]['body'] ?? '';

    return $result['status'] === 'mismatched' && ($latch(Alerts::INCIDENT_MISMATCHED)['state'] ?? null) === 'open' && count($mail) === 1
        && str_contains($body, '7001') && str_contains($body, 'status=mismatched') && str_contains($body, 'by hand')
        ?: json_encode(['status' => $result['status'], 'latch' => $latch(Alerts::INCIDENT_MISMATCHED), 'body' => $body]);
});

check('pushing a mismatched document again reports it and posts nothing (no second invoice)', function() use ($plugin, &$fixtureOrders, &$journal) {
    $o = craft\commerce\elements\Order::find()->id(end($fixtureOrders))->status(null)->one();
    mockVisma([vismaReply(201, ['Id' => 'should-not-be-asked'])]);
    $result = $plugin->getSync()->push($plugin->getDocuments()->buildInvoice($o, false));

    return $result['status'] === 'skipped' && $journal === [] && str_contains((string)$result['message'], 'different total')
        ?: json_encode(['status' => $result['status'], 'requests' => sentRequests(), 'message' => $result['message']]);
});

check('a quiet window recovers it, and the recovery counts what is still mismatched', function() use ($alerts, $latch, &$mail, $ageDocuments) {
    $mail = [];
    $ageDocuments('-2 hours', 'mismatched');
    $alerts->check([Alerts::INCIDENT_MISMATCHED]);
    $body = $mail[0]['body'] ?? '';

    return ($latch(Alerts::INCIDENT_MISMATCHED)['state'] ?? null) === 'ok' && count($mail) === 1 && str_contains($body, 'still show as mismatched')
        ?: json_encode(['mail' => count($mail), 'body' => $body]);
});

check('switched off, a mismatch alerts nobody', function() use ($alerts, $order, $latch, &$mail, $reset) {
    $reset(['alertOnMismatch' => false]);
    documentRow('invoice', 'order:' . $order()->id, 'mismatched', ['lastError' => 'Visma booked 135 but 125 was sent']);
    $alerts->check([Alerts::INCIDENT_MISMATCHED]);

    return ($latch(Alerts::INCIDENT_MISMATCHED)['state'] ?? null) === 'ok' && $mail === [] ?: 'alerted';
});

$ageDocuments('-1 day');

// ---------------------------------------------------------------------------------------------
section('Payments not registered in Visma');

$reset(['alertFailureThreshold' => 1]);

$payOrder = static function(craft\commerce\elements\Order $o, float $amount, string $currency = 'SEK'): craft\commerce\models\Transaction {
    $service = Commerce::getInstance()->getTransactions();
    $transaction = $service->createTransaction($o, null, 'purchase');
    $transaction->status = 'success';
    $transaction->amount = $amount;
    $transaction->paymentAmount = $amount;
    $transaction->paymentCurrency = $currency;
    $transaction->reference = 'ch_vz_' . bin2hex(random_bytes(4));
    $service->saveTransaction($transaction, false) or throw new RuntimeException('Could not save the fixture transaction.');

    return $service->getTransactionById($transaction->id);
};

check('a payment registration that fails opens it by itself, and says which order', function() use ($plugin, $order, $payOrder, $latch, $settings, &$mail, &$journal) {
    $o = $order();
    $gateway = Commerce::getInstance()->getGateways()->getGatewayByHandle('dummy') ?? (Commerce::getInstance()->getGateways()->getAllGateways()[0] ?? null);
    $o->gatewayId = $gateway?->id;
    Craft::$app->getElements()->saveElement($o, false);
    // The invoice is in Visma — in another currency, so the registration is refused before any
    // request is made.
    documentRow('invoice', 'order:' . $o->id, 'sent', ['vismaId' => 'a1b2c3d4-0000-4000-8000-0000000000bb', 'vismaNumber' => '7002', 'currency' => 'EUR', 'dateSent' => Db::prepareDateForDb(new DateTime())], (int)$o->id);
    // Saved with registration off, so the listener queues nothing a queue runner could pick up.
    $tx = $payOrder(reloadOrder($o), 50.0, 'SEK');
    $settings->syncPayments = true;
    mockVisma([]);
    $result = $plugin->getPayments()->register($tx);
    $settings->syncPayments = false;
    $body = $mail[0]['body'] ?? '';
    $reference = (string)(reloadOrder($o)->reference ?: substr((string)$o->number, 0, 7));

    return $result['status'] === 'failed' && ($latch(Alerts::INCIDENT_PAYMENTS)['state'] ?? null) === 'open' && count($mail) === 1
        && str_contains($mail[0]['subject'], 'Payments not registered') && str_contains($body, 'invoiced in EUR')
        && str_contains($body, $reference) && str_contains($body, 'vismaz/sync/payments') && $journal === []
        ?: json_encode(['status' => $result['status'], 'latch' => $latch(Alerts::INCIDENT_PAYMENTS), 'body' => $body]);
});

check('more failed payments while open send nothing more', function() use ($alerts, $order, &$mail) {
    paymentRow((int)$order()->id, 'failed');
    $alerts->check([Alerts::INCIDENT_PAYMENTS]);

    return count($mail) === 1 ?: count($mail) . ' emails';
});

check('a quiet window recovers it, quoting the payments still failed', function() use ($alerts, $latch, $agePayments, &$mail) {
    $mail = [];
    $agePayments('-2 hours');
    $alerts->check([Alerts::INCIDENT_PAYMENTS]);
    $body = $mail[0]['body'] ?? '';

    return ($latch(Alerts::INCIDENT_PAYMENTS)['state'] ?? null) === 'ok' && count($mail) === 1 && str_contains($body, 'payments still show as failed')
        ?: json_encode(['mail' => count($mail), 'body' => $body]);
});

check('switched off, a failed payment alerts nobody', function() use ($alerts, $order, $latch, &$mail, $reset) {
    $reset(['alertOnPayments' => false, 'alertFailureThreshold' => 1]);
    paymentRow((int)$order()->id, 'failed');
    $alerts->check([Alerts::INCIDENT_PAYMENTS]);

    return ($latch(Alerts::INCIDENT_PAYMENTS)['state'] ?? null) === 'ok' && $mail === [] ?: 'alerted';
});

check('payment failures and document failures are separate incidents', function() use ($latch) {
    return ($latch(Alerts::INCIDENT_FAILURES)['state'] ?? null) === 'ok' ?: 'a failed payment opened the document incident';
});

$agePayments('-1 day');

// ---------------------------------------------------------------------------------------------
section('Visma refusing the connection');

$reset();

check('a 401 that a refresh does not fix opens it and alerts at once, pointing at the Connection screen', function() use ($plugin, $latch, &$mail, &$journal) {
    // A 401 forces a refresh even though the token is not near expiry; the retry with the new
    // token is refused again.
    mockVisma([
        vismaReply(401, ['Message' => 'Authorization has been denied for this request.']),
        vismaReply(200, ['access_token' => 'vz-fixture-access-2', 'refresh_token' => 'vz-fixture-refresh-2', 'expires_in' => 3600]),
        vismaReply(401, ['Message' => 'Authorization has been denied for this request.']),
    ]);

    try {
        $plugin->getApi()->get('companysettings');
    } catch (Throwable) {
    }

    return ($latch(Alerts::INCIDENT_AUTH)['state'] ?? null) === 'open' && count($mail) === 1
        && str_contains($mail[0]['body'], 'denied') && str_contains($mail[0]['body'], 'vismaz/connection') && str_contains($mail[0]['body'], 'password')
        && sentRequests() === ['GET /v2/companysettings', 'POST /connect/token', 'GET /v2/companysettings']
        && $journal[2]['request']->getHeaderLine('Authorization') === 'Bearer vz-fixture-access-2'
        ?: json_encode(['latch' => $latch(Alerts::INCIDENT_AUTH), 'mail' => count($mail), 'requests' => sentRequests()]);
});

check('a 401 that the refresh does fix is not an incident — and that success recovers the open one', function() use ($plugin, $latch, &$mail) {
    $before = $latch(Alerts::INCIDENT_AUTH)['signalledAt'] ?? null;
    mockVisma([
        vismaReply(401, ['Message' => 'expired']),
        vismaReply(200, ['access_token' => 'vz-fixture-access-3', 'refresh_token' => 'vz-fixture-refresh-3', 'expires_in' => 3600]),
        vismaReply(200, ['Name' => 'Fixture AB']),
    ]);
    $plugin->getApi()->get('companysettings');

    return ($latch(Alerts::INCIDENT_AUTH)['signalledAt'] ?? null) === $before && ($latch(Alerts::INCIDENT_AUTH)['state'] ?? null) === 'ok'
        && count($mail) === 2 && str_contains($mail[1]['subject'], 'Recovered')
        ?: json_encode(['latch' => $latch(Alerts::INCIDENT_AUTH), 'mail' => array_column($mail, 'subject')]);
});

check('a token\'s expiry is read as UTC, whatever the site\'s time zone', function() use ($plugin) {
    $auth = $plugin->getAuth();
    $expiring = new ReflectionMethod($auth, 'isExpiring');
    $record = $auth->getConnection(true);
    $fresh = $expiring->invoke($auth, $record);
    expireToken();
    $stale = $expiring->invoke($auth, $auth->getConnection(true));

    return Craft::$app->getTimeZone() !== 'UTC' && $fresh === false && $stale === true
        ?: json_encode(['tz' => Craft::$app->getTimeZone(), 'fresh' => $fresh, 'stale' => $stale]);
});

check('a refresh token Visma refuses opens it too', function() use ($plugin, $latch, &$mail, $reset, $db) {
    $reset();
    $db->createCommand()->delete(Table::ALERTS, ['incident' => Alerts::INCIDENT_AUTH])->execute();
    expireToken();
    mockVisma([vismaReply(400, ['error' => 'invalid_grant'])]);

    try {
        $plugin->getApi()->get('companysettings');
    } catch (Throwable) {
    }

    return ($latch(Alerts::INCIDENT_AUTH)['state'] ?? null) === 'open' && count($mail) === 1 && str_contains($mail[0]['body'], 'invalid_grant')
        ?: json_encode(['latch' => $latch(Alerts::INCIDENT_AUTH), 'mail' => count($mail), 'requests' => sentRequests()]);
});

check('a second refusal does not send a second alert', function() use ($plugin, &$mail) {
    expireToken();
    mockVisma([vismaReply(400, ['error' => 'invalid_grant'])]);

    try {
        $plugin->getApi()->get('companysettings');
    } catch (Throwable) {
    }

    return count($mail) === 1 ?: count($mail) . ' emails';
});

check('a network failure on refresh is not an authentication failure', function() use ($plugin, $latch, $reset, $db) {
    $reset();
    $db->createCommand()->delete(Table::ALERTS, ['incident' => Alerts::INCIDENT_AUTH])->execute();
    expireToken();
    mockVisma([new ConnectException('cURL error 6: Could not resolve host', new Psr7Request('POST', 'https://identity-sandbox.test.vismaonline.com/connect/token'))]);

    try {
        $plugin->getApi()->get('companysettings');
    } catch (Throwable) {
    }

    return empty($latch(Alerts::INCIDENT_AUTH)['signalledAt']) ?: 'the network was taken for a refusal';
});

check('a 500 from the identity server is not an authentication failure', function() use ($plugin, $latch) {
    expireToken();
    mockVisma([vismaReply(503, ['error' => 'temporarily_unavailable'])]);

    try {
        $plugin->getApi()->get('companysettings');
    } catch (Throwable) {
    }

    return empty($latch(Alerts::INCIDENT_AUTH)['signalledAt']) ?: 'a 503 was taken for a refusal';
});

check('a refused authorization code (somebody at the Connect button) is not signalled', function() use ($plugin, $latch) {
    mockVisma([vismaReply(400, ['error' => 'invalid_grant'])]);

    try {
        $plugin->getAuth()->exchangeCode('vz-bad-code');
    } catch (Throwable) {
    }

    return empty($latch(Alerts::INCIDENT_AUTH)['signalledAt']) ?: 'a connect attempt was signalled';
});

check('switched off, a refusal records the signal but alerts nobody', function() use ($plugin, $latch, &$mail, $reset) {
    $reset(['alertOnAuthFailure' => false]);
    expireToken();
    mockVisma([vismaReply(400, ['error' => 'invalid_grant'])]);

    try {
        $plugin->getApi()->get('companysettings');
    } catch (Throwable) {
    }

    return !empty($latch(Alerts::INCIDENT_AUTH)['signalledAt']) && ($latch(Alerts::INCIDENT_AUTH)['state'] ?? null) === 'ok' && $mail === []
        ?: json_encode($latch(Alerts::INCIDENT_AUTH));
});

$db->createCommand()->delete(Table::ALERTS)->execute();
// The refreshes above may have rotated the fixture token; put a fresh one back.
disconnectFixture();
connectFixture();

// ---------------------------------------------------------------------------------------------
section('Sending to Visma has stalled');

$reset(['alertStallHours' => 6, 'autoPush' => true]);

// The harness holds a hundred-odd completed orders from sibling plugins, none of them in Visma.
// Rather than touch them, this process believes Vismaz was installed 6½ hours ago — the stall
// check never looks at orders placed before the install — so only this run's orders, placed
// 6¼ hours ago, are in its window. In memory only; nothing is written.
$pluginsService = Craft::$app->getPlugins();
$stored = new ReflectionProperty(craft\services\Plugins::class, '_storedPluginInfo');
$storedInfo = $stored->getValue($pluginsService);
$realInstallDate = $storedInfo['vismaz']['installDate'];
$storedInfo['vismaz']['installDate'] = new DateTime('-390 minutes');
$stored->setValue($pluginsService, $storedInfo);
$stallAge = '-375 minutes';

check('an order with no invoice 6¼ hours after it was placed, while sending automatically, opens it', function() use ($alerts, $order, $latch, $db, $ago, $stallAge, &$mail) {
    $o = $order();
    $db->createCommand()->update(CommerceTable::ORDERS, ['dateOrdered' => $ago($stallAge)], ['id' => $o->id])->execute();
    $results = $alerts->check([Alerts::INCIDENT_STALLED]);
    $body = $mail[0]['body'] ?? '';

    return ($latch(Alerts::INCIDENT_STALLED)['state'] ?? null) === 'open' && count($mail) === 1
        && str_contains($body, 'no invoice') && str_contains($body, 'queue-manager') && ($results[0]['transition'] ?? null) === 'opened'
        ?: json_encode(['latch' => $latch(Alerts::INCIDENT_STALLED), 'body' => $body]);
});

check('the stalled order is listed by the service', function() use ($alerts, &$fixtureOrders) {
    $ids = array_column($alerts->stalled()['orders'], 'id');

    return in_array(end($fixtureOrders), $ids, true) ?: json_encode($ids);
});

check('once it has an invoice, an invoice going out recovers the stall without cron', function() use ($plugin, $latch, &$fixtureOrders, &$mail) {
    $mail = [];
    $o = craft\commerce\elements\Order::find()->id(end($fixtureOrders))->status(null)->one();
    $document = $plugin->getDocuments()->buildInvoice($o, false);
    mockVisma([vismaReply(201, ['Id' => 'a1b2c3d4-0000-4000-8000-0000000000cc', 'InvoiceNumber' => 7003, 'TotalAmount' => $document->getGrossTotal()])]);
    $result = $plugin->getSync()->push($document);

    return $result['status'] === 'sent' && ($latch(Alerts::INCIDENT_STALLED)['state'] ?? null) === 'ok' && count($mail) === 1 && str_contains($mail[0]['subject'], 'Recovered')
        ?: json_encode(['status' => $result['status'], 'latch' => $latch(Alerts::INCIDENT_STALLED), 'mail' => array_column($mail, 'subject')]);
});

check('with automatic sending off, an order without an invoice is a choice, not a stall', function() use ($alerts, $order, $settings, $db, $ago, $stallAge) {
    $settings->autoPush = false;
    $o = $order();
    $db->createCommand()->update(CommerceTable::ORDERS, ['dateOrdered' => $ago($stallAge)], ['id' => $o->id])->execute();
    $ids = array_column($alerts->stalled()['orders'], 'id');
    $settings->autoPush = true;
    $db->createCommand()->update(CommerceTable::ORDERS, ['dateOrdered' => $ago('-30 days')], ['id' => $o->id])->execute();

    return !in_array((int)$o->id, $ids, true) ?: 'listed';
});

check('an order placed before Vismaz was installed is history, not a stall', function() use ($alerts, &$fixtureOrders) {
    $ids = array_column($alerts->stalled()['orders'], 'id');

    return !in_array(end($fixtureOrders), $ids, true) ?: 'a month-old order was listed';
});

check('a document stuck sending, and a payment queued but never run, count too', function() use ($alerts, $order, $db, $ago) {
    $before = $alerts->stalled();
    $o = $order();
    $db->createCommand()->update(CommerceTable::ORDERS, ['dateOrdered' => $ago('-30 days')], ['id' => $o->id])->execute();
    documentRow('invoice', 'order:' . $o->id, 'pending', ['dateUpdated' => $ago('-8 hours')]);
    paymentRow((int)$o->id, 'pending', ['dateUpdated' => $ago('-8 hours')]);
    $after = $alerts->stalled();

    return $after['pending'] === $before['pending'] + 1 && $after['payments'] === $before['payments'] + 1
        ?: json_encode([$before['pending'], $after['pending'], $before['payments'], $after['payments']]);
});

check('a payment waiting for an invoice that is long in Visma counts; one whose invoice is not there yet does not', function() use ($alerts, $order, $db, $ago) {
    $before = $alerts->stalled()['payments'];
    $released = $order();
    $db->createCommand()->update(CommerceTable::ORDERS, ['dateOrdered' => $ago('-30 days')], ['id' => $released->id])->execute();
    documentRow('invoice', 'order:' . $released->id, 'sent', ['dateSent' => $ago('-9 hours')], (int)$released->id);
    paymentRow((int)$released->id, 'waiting', ['dateUpdated' => $ago('-9 hours')]);

    $parked = $order();
    $db->createCommand()->update(CommerceTable::ORDERS, ['dateOrdered' => $ago('-30 days')], ['id' => $parked->id])->execute();
    paymentRow((int)$parked->id, 'waiting', ['dateUpdated' => $ago('-9 hours')]);

    return $alerts->stalled()['payments'] === $before + 1 ?: 'counted ' . ($alerts->stalled()['payments'] - $before);
});

check('0 hours turns the stall check off', function() use ($alerts, $settings) {
    $settings->alertStallHours = 0;
    $stalled = $alerts->stalled();
    $settings->alertStallHours = 6;

    return $stalled === ['orders' => [], 'pending' => 0, 'payments' => 0] ?: json_encode($stalled);
});

$storedInfo['vismaz']['installDate'] = $realInstallDate;
$stored->setValue($pluginsService, $storedInfo);
$db->createCommand()->delete(Table::ALERTS)->execute();
$db->createCommand()->delete(Table::DOCUMENTS, ['status' => 'pending', 'sourceKey' => array_map(static fn(int $id) => 'order:' . $id, $fixtureOrders)])->execute();
$db->createCommand()->delete(Table::PAYMENTS, ['orderId' => $fixtureOrders, 'status' => ['pending', 'waiting']])->execute();

// ---------------------------------------------------------------------------------------------
section('The webhook');

$reset(['alertRecipients' => '', 'alertWebhookUrl' => 'https://93.184.215.14/services/T000/B000/xyz', 'alertWebhookSecret' => "whsec-$suffix", 'alertFailureThreshold' => 1]);

check('an incident posts a Slack message, signed, to the pinned address', function() use ($alerts, $failedDocument, &$history, $suffix) {
    global $hookMock;
    $hookMock->append(new Psr7Response(200));
    $failedDocument();
    $alerts->check([Alerts::INCIDENT_FAILURES]);
    $request = $history[0]['request'] ?? null;

    if (!$request) {
        return 'nothing posted';
    }

    $body = (string)$request->getBody();
    $payload = json_decode($body, true);
    $expected = 'sha256=' . hash_hmac('sha256', $request->getHeaderLine('X-Vismaz-Timestamp') . '.' . $body, "whsec-$suffix");

    return str_contains($payload['text'] ?? '', 'Orders failing to reach Visma') && isset($payload['blocks'])
        && hash_equals($expected, $request->getHeaderLine('X-Vismaz-Signature'))
        && ($history[0]['options']['curl'][CURLOPT_RESOLVE][0] ?? '') === '93.184.215.14:443:93.184.215.14'
        ?: $body;
});

check('a webhook that fails with no email configured is retried, not lost', function() use ($alerts, $hookMock, $latch, $ageDocuments, &$history) {
    $ageDocuments('-2 hours', 'failed');
    $hookMock->append(new Psr7Response(500));
    $alerts->check([Alerts::INCIDENT_FAILURES]);
    $row = $latch(Alerts::INCIDENT_FAILURES);
    $owed = array_key_exists('recoveryNotifiedAt', $row) && $row['recoveryNotifiedAt'] === null;
    $hookMock->append(new Psr7Response(200));
    $alerts->check([Alerts::INCIDENT_FAILURES]);

    return $owed && ($latch(Alerts::INCIDENT_FAILURES)['recoveryNotifiedAt'] ?? null) !== null && count($history) === 3
        ?: json_encode(['owed' => $owed, 'posts' => count($history)]);
});

check('Teams gets an Adaptive Card, JSON gets a flat vismaz.alert event', function() use ($alerts) {
    $m = $alerts->compose(Alerts::INCIDENT_PAYMENTS, false, 'Paid in SEK but invoiced in EUR');
    $teams = $alerts->payload('teams', $m);
    $json = $alerts->payload('json', $m);

    return ($teams['attachments'][0]['content']['type'] ?? null) === 'AdaptiveCard'
        && $json['event'] === 'vismaz.alert.opened' && $json['incident'] === 'payments' && str_contains($json['syncUrl'], 'vismaz/documents')
        ?: json_encode([$teams, $json]);
});

check('a handler on EVENT_BEFORE_NOTIFY can reword or swallow an alert', function() use ($alerts) {
    $seen = null;
    $handler = function(AlertEvent $e) use (&$seen) {
        $seen = $e->subject;
        $e->isValid = false;
    };
    $alerts->on(Alerts::EVENT_BEFORE_NOTIFY, $handler);
    $result = $alerts->notify('test', false, 'x');
    $alerts->off(Alerts::EVENT_BEFORE_NOTIFY, $handler);

    return $result === true && is_string($seen) ?: 'not called';
});

$ageDocuments('-1 day');

// ---------------------------------------------------------------------------------------------
section('Dashboard widget');

$reset();

check('it shows the connection, the counts and any open incident', function() use ($db) {
    Craft::$app->getUser()->setIdentity(User::find()->admin()->one());
    $db->createCommand()->upsert(Table::ALERTS, [
        'incident' => Alerts::INCIDENT_AUTH, 'state' => 'open', 'detail' => 'shown on hover',
        'dateCreated' => Db::prepareDateForDb(new DateTime()), 'dateUpdated' => Db::prepareDateForDb(new DateTime()), 'uid' => craft\helpers\StringHelper::UUID(),
    ], ['state' => 'open', 'detail' => 'shown on hover'])->execute();
    $view = Craft::$app->getView();
    $mode = $view->getTemplateMode();
    $view->setTemplateMode(View::TEMPLATE_MODE_CP);
    $html = (string)(new HealthWidget())->getBodyHtml();
    $view->setTemplateMode($mode);

    return str_contains($html, 'Visma refused the connection') && str_contains($html, 'shown on hover')
        && str_contains($html, 'Mismatched') && str_contains($html, 'status=failed') && str_contains($html, 'Payments not registered')
        && HealthWidget::isSelectable()
        ?: substr(strip_tags($html), 0, 400);
});

check('it shows nothing to someone without “View Visma documents”', function() {
    Craft::$app->getUser()->setIdentity(null);

    return (new HealthWidget())->getBodyHtml() === null && !HealthWidget::isSelectable() ?: 'rendered';
});

check('it is registered with the Dashboard', function() {
    return in_array(HealthWidget::class, Craft::$app->getDashboard()->getAllWidgetTypes(), true) ?: 'missing';
});

$db->createCommand()->delete(Table::ALERTS)->execute();

// ---------------------------------------------------------------------------------------------
section('Console');

// The console reads saved settings, where Vismaz is not connected in this harness.
check('vismaz/alerts/check runs and exits 0', function() {
    exec('php craft vismaz/alerts/check 2>&1', $out, $code);
    $text = implode("\n", $out);

    return $code === 0 && (str_contains($text, 'not connected') || str_contains($text, 'open incident')) ?: "exit $code: " . implode(' | ', $out);
});

check('vismaz/alerts/test refuses with nothing configured (saved settings)', function() {
    exec('php craft vismaz/alerts/test 2>&1', $out, $code);

    return $code === 78 && str_contains(implode("\n", $out), 'nothing to send to') ?: "exit $code: " . implode(' | ', $out);
});

check('vismaz/sync/retry checks the alerts after retrying', function() {
    $src = (string)file_get_contents(dirname(__DIR__, 2) . '/src/console/controllers/SyncController.php');

    return substr_count($src, '$this->checkAlerts();') === 2 ?: 'retry/payments do not check alerts';
});

// ---------------------------------------------------------------------------------------------
section('“Send a test alert” over HTTP');

[$viewer, $password] = makeUser('alerts', ['accesscp', 'accessplugin-vismaz', 'vismaz-viewdocuments', 'vismaz-pushdocuments', 'vismaz-viewlog', 'vismaz-manageconnection', 'vismaz-exportsie']);

check('anonymous is refused', function() {
    $status = client(null, null)('vismaz/alerts/test')->getStatusCode();

    return in_array($status, [400, 401, 403], true) ?: "status $status";
});

check('a non-admin with every Vismaz permission is refused', function() use ($viewer, $password) {
    $status = client($viewer->username, $password)('vismaz/alerts/test')->getStatusCode();

    return $status === 403 ?: "status $status";
});

check('an admin without a CSRF token is refused', function() {
    $status = client('admin', 'claudepassword')('vismaz/alerts/test', [], 'POST', false)->getStatusCode();

    return $status === 400 ?: "status $status";
});

check('an admin GET is refused', function() {
    $status = client('admin', 'claudepassword')('vismaz/alerts/test', [], 'GET')->getStatusCode();

    return in_array($status, [400, 405], true) ?: "status $status";
});

check('an admin POST answers JSON from the saved settings (and takes no URL from the request)', function() {
    $response = client('admin', 'claudepassword')('vismaz/alerts/test', ['alertWebhookUrl' => 'http://169.254.169.254/']);
    $data = json_decode((string)$response->getBody(), true);

    return in_array($response->getStatusCode(), [200, 400], true) && is_string($data['message'] ?? null) && !str_contains((string)$data['message'], '169.254')
        ?: $response->getStatusCode() . ' ' . substr((string)$response->getBody(), 0, 200);
});

check('the settings screen’s test button is bound to an id that exists', function() {
    $twig = (string)file_get_contents(dirname(__DIR__, 2) . '/src/templates/settings.twig');

    return str_contains($twig, 'id="vismaz-test-alert"') && str_contains($twig, "getElementById('vismaz-test-alert')") && str_contains($twig, "'vismaz/alerts/test'") ?: 'unbound';
});

finish();
