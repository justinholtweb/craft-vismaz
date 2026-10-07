<?php
/**
 * Who can connect Visma, where, and what the OAuth callback checks — in the plugin-testing harness,
 * over HTTP.
 *
 *     docker exec -w /var/www/html ddev-plugin-testing-web php /var/www/craft-vismaz/tests/integration/security.php
 *
 * Until 5.0.1 every OAuth action called requireAdmin(), which needs allowAdminChanges — so on a
 * production site nobody could connect, reconnect after a revoked token, or disconnect. And the
 * callback only checked that its state existed, not the environment and user it was issued for.
 *
 * Sets placeholder Visma credentials for the run (a connect only ever redirects to Visma; nothing is
 * requested from it while these checks pass) and turns allowAdminChanges off in the harness .env
 * for part of it. Both are put back, the settings in a fresh process (see craft-nuke's
 * tests/README).
 */

$root = getcwd();
require $root . '/bootstrap.php';

/** @var craft\console\Application $app */
$app = require CRAFT_VENDOR_PATH . '/craftcms/cms/bootstrap/console.php';

use craft\elements\User;
use GuzzleHttp\Client;
use GuzzleHttp\Cookie\CookieJar;
use justinholtweb\vismaz\Plugin;
use justinholtweb\vismaz\services\Auth;

$passed = 0;
$failed = 0;

function check(string $label, callable $test): void
{
    global $passed, $failed;

    try {
        $result = $test();
    } catch (Throwable $e) {
        $result = get_class($e) . ': ' . $e->getMessage();
    }

    if ($result === true) {
        $passed++;
        echo "  ✓ $label\n";
    } else {
        $failed++;
        echo "  ✗ $label\n    " . (is_string($result) ? $result : var_export($result, true)) . "\n";
    }
}

Craft::$app->getPlugins()->loadPlugins();

// craft-penny's broken beforeSaveElement handler (see craft-bird's checks.php) — detached in-process only.
if (Craft::$app->getPlugins()->isPluginEnabled('penny')) {
    yii\base\Event::off(craft\services\Elements::class, craft\services\Elements::EVENT_BEFORE_SAVE_ELEMENT);
}

$plugin = Plugin::getInstance();
$run = bin2hex(random_bytes(3));
$password = 'Vismaz-' . bin2hex(random_bytes(6));
$settingsPath = 'plugins.vismaz.settings';
$settingsBefore = Craft::$app->getProjectConfig()->get($settingsPath);
$envFile = $root . '/.env';
$envBefore = file_get_contents($envFile);
$cleanup = ['users' => []];
$statePrefix = (new ReflectionClassConstant(Auth::class, 'STATE_CACHE_PREFIX'))->getValue();

Craft::$app->getPlugins()->savePluginSettings($plugin, array_merge($plugin->getSettings()->toArray(), [
    'environment' => 'sandbox', 'clientId' => "client-$run", 'clientSecret' => "secret-$run",
]));
Craft::$app->getProjectConfig()->saveModifiedConfigData();
Craft::$app->getProjectConfig()->writeYamlFiles(true);

register_shutdown_function(function() use (&$cleanup, $envFile, $envBefore, $settingsPath, $settingsBefore, $root) {
    file_put_contents($envFile, $envBefore);
    foreach ($cleanup['users'] as $user) {
        Craft::$app->getElements()->deleteElement($user, true);
    }

    $restore = sys_get_temp_dir() . '/vismaz-restore-' . bin2hex(random_bytes(4)) . '.php';
    file_put_contents($restore, '<?php
require ' . var_export($root . '/bootstrap.php', true) . ';
$app = require CRAFT_VENDOR_PATH . "/craftcms/cms/bootstrap/console.php";
$pc = Craft::$app->getProjectConfig();
$pc->set(' . var_export($settingsPath, true) . ', ' . var_export($settingsBefore, true) . ', "Restore Vismaz settings after security.php");
$pc->saveModifiedConfigData();
$pc->writeYamlFiles(true);
');
    exec('php ' . escapeshellarg($restore) . ' 2>&1', $out, $code);
    unlink($restore);
    $code === 0 or print("  ! Could not restore Vismaz's settings: " . implode("\n", $out) . "\n");
});

$user = static function(string $name, array $permissions) use (&$cleanup, $run, $password): User {
    $u = new User(['username' => "vismaz-$name-$run", 'email' => "vismaz-$name-$run@example.com", 'newPassword' => $password]);
    Craft::$app->getElements()->saveElement($u, false);
    Craft::$app->getUsers()->activateUser($u);
    Craft::$app->getUserPermissions()->saveUserPermissions($u->id, $permissions);
    $cleanup['users'][] = $u;

    return $u;
};

function client(string $username, string $password): array
{
    $http = new Client(['base_uri' => 'http://localhost/', 'cookies' => new CookieJar(), 'http_errors' => false, 'allow_redirects' => false]);
    $csrf = static fn() => (string)(json_decode((string)$http->get('index.php?p=actions/users/session-info', ['headers' => ['Accept' => 'application/json']])->getBody(), true)['csrfTokenValue'] ?? '');
    $http->post('index.php?p=actions/users/login', ['headers' => ['Accept' => 'application/json'], 'form_params' => ['loginName' => $username, 'password' => $password, 'CRAFT_CSRF_TOKEN' => $csrf()]])->getStatusCode() === 200
        or throw new RuntimeException("Could not sign in as $username");

    return [$http, $csrf];
}

$manager = $user('manager', ['accesscp', 'accessplugin-vismaz', 'vismaz-manageconnection']);
$bystander = $user('bystander', ['accesscp', 'accessplugin-vismaz', 'vismaz-viewdocuments']);
[$managerHttp, $managerCsrf] = client($manager->username, $password);
[$bystanderHttp, $bystanderCsrf] = client($bystander->username, $password);

/** Whether a connect answers with a redirect to Visma's own sign-in page. */
$connectsToVisma = static function(Client $http): bool {
    $response = $http->get('index.php?p=admin/actions/vismaz/oauth/connect');

    return $response->getStatusCode() === 302 && str_contains($response->getHeaderLine('Location'), '/connect/authorize?');
};

echo "\nWho can connect\n";

check('“Connect, test and disconnect Visma” gets the Connection screen', function() use ($managerHttp) {
    $response = $managerHttp->get('index.php?p=admin/vismaz/connection', ['headers' => ['Accept' => 'application/json']]);
    $body = (string)$response->getBody();

    return $response->getStatusCode() === 200 && str_contains($body, 'vismaz/oauth/connect') ?: 'status ' . $response->getStatusCode() . ' ' . substr($body, 0, 300);
});

check('…and can connect, without being an admin', function() use ($managerHttp, $connectsToVisma) {
    return $connectsToVisma($managerHttp) ?: 'no redirect to Visma';
});

check('without that permission, neither', function() use ($bystanderHttp) {
    $page = $bystanderHttp->get('index.php?p=admin/vismaz/connection')->getStatusCode();
    $connect = $bystanderHttp->get('index.php?p=admin/actions/vismaz/oauth/connect')->getStatusCode();

    return $page === 403 && $connect === 403 ?: "page $page, connect $connect";
});

check('the plugin settings page still renders the connection for an admin', function() {
    [$admin] = client('admin', 'claudepassword');
    $response = $admin->get('index.php?p=admin/settings/plugins/vismaz');

    return $response->getStatusCode() === 200 && str_contains((string)$response->getBody(), 'vismaz/oauth/connect') ?: 'status ' . $response->getStatusCode();
});

// Production: allowAdminChanges off.
$envOff = preg_replace('/^CRAFT_ALLOW_ADMIN_CHANGES=.*$/m', 'CRAFT_ALLOW_ADMIN_CHANGES=false', $envBefore, -1, $replaced);
file_put_contents($envFile, $replaced ? $envOff : rtrim($envBefore) . "\nCRAFT_ALLOW_ADMIN_CHANGES=false\n");

check('with allowAdminChanges off, an admin can still connect', function() use ($connectsToVisma) {
    [$admin] = client('admin', 'claudepassword');

    return $connectsToVisma($admin) ?: 'no redirect to Visma';
});

check('…and so can the permission holder, and test and disconnect', function() use ($managerHttp, $managerCsrf, $connectsToVisma) {
    $connect = $connectsToVisma($managerHttp);
    $test = $managerHttp->post('index.php?p=admin/actions/vismaz/oauth/test', ['headers' => ['Accept' => 'application/json'], 'form_params' => ['CRAFT_CSRF_TOKEN' => $managerCsrf()]])->getStatusCode();
    $disconnect = $managerHttp->post('index.php?p=admin/actions/vismaz/oauth/disconnect', ['headers' => ['Accept' => 'application/json'], 'form_params' => ['CRAFT_CSRF_TOKEN' => $managerCsrf()]])->getStatusCode();

    return $connect && $test === 200 && in_array($disconnect, [200, 302], true) ?: json_encode(['connect' => $connect, 'test' => $test, 'disconnect' => $disconnect]);
});

check('…and an admin can clear the log', function() {
    [$admin, $csrf] = client('admin', 'claudepassword');
    $status = $admin->post('index.php?p=admin/actions/vismaz/log/clear', ['headers' => ['Accept' => 'application/json'], 'form_params' => ['CRAFT_CSRF_TOKEN' => $csrf()]])->getStatusCode();

    return in_array($status, [200, 302], true) ?: "status $status";
});

file_put_contents($envFile, $envBefore);

echo "\nThe callback\n";

/**
 * Plants a state as connect() would have, and returns whether the callback refused it.
 *
 * Refused means it never tried to exchange the code. Getting past the state check sends the code
 * to Visma, and the harness can't reach Visma, so that attempt always ends in a connection error
 * the callback reports — which a refusal never does. Each attempt uses a fresh session, because
 * another harness plugin keeps notices on screen until they are dismissed.
 */
$exchangeMarkers = ['cURL error', 'Could not resolve', 'refused the token request', 'Connected to'];
$callbackRefuses = static function(array $issued) use ($manager, $password, $statePrefix, $run, $exchangeMarkers): bool {
    [$http] = client($manager->username, $password);
    $state = craft\helpers\StringHelper::UUID();
    Craft::$app->getCache()->set($statePrefix . $state, $issued, 900);
    $response = $http->get('index.php?p=admin/actions/vismaz/oauth/callback&code=code-' . $run . '&state=' . $state);
    $page = (string)$http->get('index.php?p=admin/vismaz/connection')->getBody();

    foreach ($exchangeMarkers as $marker) {
        if (str_contains($page, $marker)) {
            return false;
        }
    }

    return $response->getStatusCode() === 302 && str_contains($page, 'could not be verified');
};

check('a state issued for another environment is refused', function() use ($callbackRefuses, $manager) {
    return $callbackRefuses(['environment' => 'production', 'userId' => $manager->id]) ?: 'accepted';
});

check('…and one issued to another user', function() use ($callbackRefuses, $bystander) {
    return $callbackRefuses(['environment' => 'sandbox', 'userId' => $bystander->id]) ?: 'accepted';
});

check('…and one that was never issued', function() use ($manager, $password, $run, $exchangeMarkers) {
    [$http] = client($manager->username, $password);
    $http->get('index.php?p=admin/actions/vismaz/oauth/callback&code=code-' . $run . '&state=' . craft\helpers\StringHelper::UUID());
    $page = (string)$http->get('index.php?p=admin/vismaz/connection')->getBody();

    return str_contains($page, 'could not be verified') && !array_filter($exchangeMarkers, fn($m) => str_contains($page, $m)) ?: 'it tried to exchange the code';
});

check('a state for this environment and this user does get as far as exchanging the code', function() use ($callbackRefuses, $manager) {
    // The positive control: without it, the refusals above could all be a callback that refuses
    // everything. (It asks Visma's sandbox, with placeholder credentials, and is turned down.)
    return !$callbackRefuses(['environment' => 'sandbox', 'userId' => $manager->id]) ?: 'refused a valid state';
});

check('the subnav offers Connection to whoever can use it', function() use ($plugin, $manager, $bystander) {
    $nav = static function(User $u) use ($plugin): array {
        Craft::$app->getUser()->setIdentity(User::find()->id($u->id)->status(null)->one());

        return array_keys($plugin->getCpNavItem()['subnav'] ?? []);
    };
    $managerNav = $nav($manager);
    $bystanderNav = $nav($bystander);
    Craft::$app->getUser()->setIdentity(null);

    return in_array('connection', $managerNav, true) && !in_array('connection', $bystanderNav, true) ?: json_encode([$managerNav, $bystanderNav]);
});

echo "\n$passed passed, $failed failed\n";
exit($failed === 0 ? 0 : 1);
