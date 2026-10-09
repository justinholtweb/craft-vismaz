<?php
/**
 * The Orders index: Visma status sets (PHP and SQL held equal), the "Visma status" condition rule,
 * the "Visma" column, and the bulk "Send to Visma" action — the action and the column also over
 * HTTP, as a signed-in admin and as a user without the permission.
 *
 *     docker exec -w /var/www/html ddev-plugin-testing-web php /var/www/craft-vismaz/tests/integration/orders.php
 *
 * Document and payment rows are written directly (as `Sync::record()`/`Payments::register()`
 * leave them); nothing here talks to Visma. Settings stay in memory.
 */

require __DIR__ . '/_support.php';

use craft\commerce\elements\conditions\orders\OrderCondition;
use craft\commerce\elements\Order;
use craft\db\Query;
use craft\elements\User;
use justinholtweb\vismaz\elements\actions\SendToVisma;
use justinholtweb\vismaz\elements\conditions\VismaStatusConditionRule;
use justinholtweb\vismaz\jobs\PushDocumentJob;
use justinholtweb\vismaz\services\OrderStatus;

$statuses = $plugin->getOrderStatus();
$variant = makeVariant();

// One order per situation. The name says what the column should read.
$fixtures = [];
$expect = [];
$add = function(string $name, string $status) use (&$fixtures, &$expect, $variant): Order {
    $fixtures[$name] = makeOrder($variant);
    $expect[$name] = $status;

    return $fixtures[$name];
};

$o = $add('synced', OrderStatus::SYNCED);
documentRow('invoice', 'order:' . $o->id, 'sent', ['vismaId' => 'g-1', 'vismaNumber' => '9001'], (int)$o->id);

$o = $add('syncedPaid', OrderStatus::SYNCED);
documentRow('invoice', 'order:' . $o->id, 'sent', ['vismaId' => 'g-2'], (int)$o->id);
paymentRow((int)$o->id, 'sent');
// A payment still waiting for its invoice is not a failure.
paymentRow((int)$o->id, 'waiting');

$o = $add('mismatched', OrderStatus::MISMATCHED);
documentRow('invoice', 'order:' . $o->id, 'mismatched', ['vismaId' => 'g-3', 'lastError' => 'Visma booked 135 but 125 was sent'], (int)$o->id);

// A failed invoice never got a join row: only its key ties it to the order.
$o = $add('failed', OrderStatus::FAILED);
documentRow('invoice', 'order:' . $o->id, 'failed', ['lastError' => 'CustomerId is required']);

$o = $add('failedRefund', OrderStatus::FAILED);
documentRow('invoice', 'order:' . $o->id, 'sent', ['vismaId' => 'g-4'], (int)$o->id);
documentRow('creditnote', 'refund:' . $o->id . ':' . substr(sha1((string)$o->id), 0, 16), 'failed', ['lastError' => 'Credit note refused']);

$o = $add('failedPayment', OrderStatus::FAILED);
documentRow('invoice', 'order:' . $o->id, 'sent', ['vismaId' => 'g-5'], (int)$o->id);
paymentRow((int)$o->id, 'failed');

// Precedence: a failed payment outranks a mismatched invoice.
$o = $add('mismatchedAndFailed', OrderStatus::FAILED);
documentRow('invoice', 'order:' . $o->id, 'mismatched', ['vismaId' => 'g-6'], (int)$o->id);
paymentRow((int)$o->id, 'failed');

$o = $add('pending', OrderStatus::PENDING);
documentRow('invoice', 'order:' . $o->id, 'pending');

// Voucher mode: the order's only tie to the voucher is the join row.
$o = $add('inVoucher', OrderStatus::SYNCED);
documentRow('voucher', 'voucher:vz-test-' . $suffix . ':' . $o->id, 'sent', ['vismaId' => 'g-7', 'orderCount' => 1], (int)$o->id);

$add('none', OrderStatus::NONE);

// An order id inside another's key must not leak: order 1x is not order 1.
$o = $add('prefixSafe', OrderStatus::NONE);
documentRow('invoice', 'order:' . $o->id . '9', 'failed');

$ids = array_map(static fn(Order $o) => (int)$o->id, $fixtures);

// ---------------------------------------------------------------------------------------------
section('Order status sets');

check('PHP gives every fixture the status it should have', function() use ($statuses, $fixtures, $expect) {
    $got = $statuses->orderStatuses(array_map(static fn(Order $o) => $o->id, $fixtures));
    $wrong = [];

    foreach ($fixtures as $name => $order) {
        if (($got[(int)$order->id] ?? null) !== $expect[$name]) {
            $wrong[] = "$name: " . ($got[(int)$order->id] ?? 'null') . ' (want ' . $expect[$name] . ')';
        }
    }

    return $wrong === [] ?: implode('; ', $wrong);
});

check('SQL builds exactly the same five sets — and they partition the orders', function() use ($statuses, $ids) {
    $php = $statuses->orderStatuses($ids);
    $problems = [];
    $seen = [];

    foreach (array_keys(OrderStatus::options()) as $status) {
        $sql = array_map('intval', Order::find()->id($ids)->status(null)->andWhere($statuses->condition($status))->ids());
        $want = array_keys(array_filter($php, static fn($s) => $s === $status));
        sort($sql);
        sort($want);

        if ($sql !== $want) {
            $problems[] = "$status: sql " . json_encode($sql) . ' php ' . json_encode($want);
        }

        $seen = array_merge($seen, $sql);
    }

    sort($seen);
    $all = $ids;
    sort($all);

    return $problems === [] && $seen === array_values($all) ?: implode('; ', $problems) . ' seen ' . json_encode($seen);
});

check('an unknown status matches nothing rather than everything', function() use ($statuses, $ids) {
    return Order::find()->id($ids)->status(null)->andWhere($statuses->condition('bogus'))->count() == 0 ?: 'matched';
});

check('the memo answers a row from the page prefetch, and forgets after a push', function() use ($statuses, $ids, $fixtures) {
    $statuses->reset();
    $statuses->prefetch($ids);
    $memo = new ReflectionProperty($statuses, 'memo');
    $filled = count($memo->getValue($statuses)) === count($ids);
    $status = $statuses->orderStatus((int)$fixtures['mismatched']->id);
    $statuses->reset();

    return $filled && $status === OrderStatus::MISMATCHED && $memo->getValue($statuses) === [] ?: json_encode([$filled, $status]);
});

// ---------------------------------------------------------------------------------------------
section('“Visma status” condition rule');

$makeRule = function(array $values, string $operator = 'in'): VismaStatusConditionRule {
    $condition = Craft::$app->getConditions()->createCondition(['class' => OrderCondition::class, 'elementType' => Order::class]);

    /** @var VismaStatusConditionRule $rule */
    $rule = $condition->createConditionRule(['class' => VismaStatusConditionRule::class, 'values' => $values, 'operator' => $operator]);

    return $rule;
};

check('it is offered on order conditions', function() {
    $condition = Craft::$app->getConditions()->createCondition(['class' => OrderCondition::class, 'elementType' => Order::class]);
    $types = array_map(static fn($r) => get_class($r), $condition->getSelectableConditionRules());

    return in_array(VismaStatusConditionRule::class, $types, true) ?: 'missing';
});

check('“is one of failed, mismatched” narrows an order query to exactly those', function() use ($makeRule, $ids, $fixtures) {
    $query = Order::find()->id($ids)->status(null);
    $makeRule([OrderStatus::FAILED, OrderStatus::MISMATCHED])->modifyQuery($query);
    $got = array_map('intval', $query->ids());
    $want = array_map(static fn($n) => (int)$fixtures[$n]->id, ['mismatched', 'failed', 'failedRefund', 'failedPayment', 'mismatchedAndFailed']);
    sort($got);
    sort($want);

    return $got === $want ?: json_encode(['got' => $got, 'want' => $want]);
});

check('“is not one of synced” keeps everything else, including never-sent orders', function() use ($makeRule, $ids, $fixtures) {
    $query = Order::find()->id($ids)->status(null);
    $makeRule([OrderStatus::SYNCED], 'ni')->modifyQuery($query);
    $got = array_map('intval', $query->ids());

    return count($got) === count($ids) - 3 && !in_array((int)$fixtures['synced']->id, $got, true) && in_array((int)$fixtures['none']->id, $got, true)
        ?: json_encode($got);
});

check('matchElement agrees with the query for every fixture', function() use ($makeRule, $fixtures, $expect) {
    $rule = $makeRule([OrderStatus::PENDING, OrderStatus::NONE]);
    $wrong = [];

    foreach ($fixtures as $name => $order) {
        $want = in_array($expect[$name], [OrderStatus::PENDING, OrderStatus::NONE], true);

        if ($rule->matchElement($order) !== $want) {
            $wrong[] = $name;
        }
    }

    return $wrong === [] ?: implode(', ', $wrong);
});

check('stale values are kept on the rule and survive a config round-trip', function() use ($makeRule) {
    $rule = $makeRule([OrderStatus::FAILED, 'drop table', 'bogus']);
    $config = $rule->getConfig();
    $again = Craft::$app->getConditions()->createConditionRule($config);
    $want = [OrderStatus::FAILED, 'drop table', 'bogus'];

    return $rule->getValues() === $want && $again->getValues() === $want && $rule->validate(['values'])
        ?: json_encode($config);
});

check('…but only known values reach the query', function() use ($makeRule, $ids) {
    $stale = Order::find()->id($ids)->status(null);
    $makeRule([OrderStatus::FAILED, 'drop table', 'bogus'])->modifyQuery($stale);
    $plain = Order::find()->id($ids)->status(null);
    $makeRule([OrderStatus::FAILED])->modifyQuery($plain);
    $a = array_map('intval', $stale->ids());
    $b = array_map('intval', $plain->ids());
    sort($a);
    sort($b);

    return ($a === $b && $a !== []) ?: json_encode([$a, $b]);
});

check('a saved rule whose only value is stale: “is one of” matches no order at all', function() use ($makeRule, $fixtures) {
    $config = $makeRule(['bogus'])->getConfig();
    $rule = Craft::$app->getConditions()->createConditionRule($config);
    $query = Order::find()->status(null);
    $rule->modifyQuery($query);
    $count = (int)$query->count();
    $matched = array_filter($fixtures, static fn(Order $o) => $rule->matchElement($o));

    return ($rule->getValues() === ['bogus'] && $count === 0 && $matched === [])
        ?: json_encode([$rule->getValues(), $count, array_keys($matched)]);
});

check('…and “is not one of” it excludes nothing', function() use ($makeRule, $fixtures) {
    $rule = $makeRule(['bogus'], 'ni');
    $query = Order::find()->status(null);
    $rule->modifyQuery($query);
    $base = (int)Order::find()->status(null)->count();
    $count = (int)$query->count();
    $unmatched = array_filter($fixtures, static fn(Order $o) => !$rule->matchElement($o));

    return ($count === $base && $base > 0 && $unmatched === []) ?: json_encode([$count, $base, array_keys($unmatched)]);
});

check('an empty rule matches every element', function() use ($makeRule, $fixtures) {
    $rule = $makeRule([]);
    $base = (int)Order::find()->status(null)->count();
    $query = Order::find()->status(null);
    $rule->modifyQuery($query);
    $unmatched = array_filter($fixtures, static fn(Order $o) => !$rule->matchElement($o));

    return ((int)$query->count() === $base && $unmatched === []) ?: json_encode([(int)$query->count(), $base, array_keys($unmatched)]);
});

check('“is not one of” a known status still matches like the query, stale values or not', function() use ($makeRule, $ids, $fixtures) {
    $rule = $makeRule([OrderStatus::SYNCED, 'bogus'], 'ni');
    $query = Order::find()->id($ids)->status(null);
    $rule->modifyQuery($query);
    $got = array_map('intval', $query->ids());
    $wrong = [];

    foreach ($fixtures as $name => $order) {
        if ($rule->matchElement($order) !== in_array((int)$order->id, $got, true)) {
            $wrong[] = $name;
        }
    }

    return ($wrong === [] && count($got) === count($ids) - 3) ?: json_encode([$wrong, count($got)]);
});

check('an empty rule leaves the query alone', function() use ($makeRule, $ids) {
    $query = Order::find()->id($ids)->status(null);
    $makeRule([])->modifyQuery($query);

    return count($query->ids()) === count($ids) ?: 'narrowed';
});

// ---------------------------------------------------------------------------------------------
section('Orders index column');

check('“Visma” is an available column on orders only', function() {
    $orders = Craft::$app->getElementSources()->getAvailableTableAttributes(Order::class);
    $entries = Craft::$app->getElementSources()->getAvailableTableAttributes(craft\elements\Entry::class);

    return isset($orders['vismazStatus']) && !isset($entries['vismazStatus']) ?: 'not registered as expected';
});

check('the cell shows the status to someone who can view documents, and nothing to anyone else', function() use ($plugin, $fixtures) {
    $order = $fixtures['mismatched'];
    Craft::$app->getUser()->setIdentity(User::find()->admin()->one());
    $admin = $plugin->orderStatusHtml($order);
    Craft::$app->getUser()->setIdentity(null);
    $anonymous = $plugin->orderStatusHtml($order);

    return str_contains($admin, 'Mismatched') && str_contains($admin, 'status orange') && $anonymous === ''
        ?: json_encode([$admin, $anonymous]);
});

check('over HTTP, the Orders index renders the column for each row', function() use ($ids) {
    $response = client('admin', 'claudepassword')('element-indexes/get-elements', [
        'elementType' => Order::class,
        'source' => '*',
        'context' => 'index',
        'viewState' => ['mode' => 'table', 'static' => false, 'tableColumns' => ['vismazStatus']],
        'criteria' => ['id' => array_values($ids), 'status' => null, 'isCompleted' => null],
    ], 'POST', true, true);
    $html = json_decode((string)$response->getBody(), true)['html'] ?? '';

    return $response->getStatusCode() === 200 && str_contains($html, 'Mismatched') && str_contains($html, 'Failed')
        && str_contains($html, 'Not sent') && str_contains($html, 'Pending') && str_contains($html, 'Synced')
        ?: $response->getStatusCode() . ': ' . substr(strip_tags((string)$response->getBody()), 0, 300);
});

check('over HTTP, a custom-source-style condition filters the index', function() use ($ids, $fixtures) {
    $response = client('admin', 'claudepassword')('element-indexes/get-elements', [
        'elementType' => Order::class,
        'source' => '*',
        'context' => 'index',
        'viewState' => ['mode' => 'table', 'static' => false, 'tableColumns' => ['vismazStatus']],
        'criteria' => ['id' => array_values($ids), 'status' => null, 'isCompleted' => null],
        'condition' => [
            'class' => OrderCondition::class,
            'elementType' => Order::class,
            'conditionRules' => [['class' => VismaStatusConditionRule::class, 'operator' => 'in', 'values' => [OrderStatus::PENDING]]],
        ],
    ], 'POST', true, true);
    $data = json_decode((string)$response->getBody(), true);
    $html = $data['html'] ?? '';

    return $response->getStatusCode() === 200 && str_contains($html, 'Pending') && !str_contains($html, 'Mismatched') && !str_contains($html, 'Not sent')
        ?: $response->getStatusCode() . ': ' . substr(strip_tags((string)$response->getBody()), 0, 300);
});

// ---------------------------------------------------------------------------------------------
section('“Send to Visma” element action');

$queued = function(int $after): array {
    $jobs = (new Query())->select(['job'])->from(craft\db\Table::QUEUE)->where(['>', 'id', $after])->column();

    return array_values(array_filter(array_map(static fn($blob) => unserialize(is_resource($blob) ? stream_get_contents($blob) : $blob), $jobs), static fn($j) => $j instanceof PushDocumentJob));
};

check('it refuses someone without “Send orders to Visma”, even if they reach it', function() use ($fixtures) {
    Craft::$app->getUser()->setIdentity(null);
    $action = new SendToVisma();

    return $action->performAction(Order::find()->id($fixtures['none']->id)->status(null)) === false ?: 'ran';
});

check('it refuses when Vismaz is not connected', function() use ($fixtures, $plugin) {
    Craft::$app->getUser()->setIdentity(User::find()->admin()->one());
    $connected = $plugin->getAuth()->isConnected();
    $action = new SendToVisma();
    $ok = $action->performAction(Order::find()->id($fixtures['none']->id)->status(null));

    return $connected || ($ok === false && str_contains((string)$action->getMessage(), 'not connected')) ?: (string)$action->getMessage();
});

connectFixture();

check('it queues a push for every selected completed order, and skips carts', function() use ($fixtures, $variant, $queued) {
    Craft::$app->getUser()->setIdentity(User::find()->admin()->one());
    $cart = makeOrder($variant, false);
    $ids = [$fixtures['none']->id, $fixtures['failed']->id, $cart->id];
    $before = (int)(new Query())->from(craft\db\Table::QUEUE)->max('id');
    $action = new SendToVisma();
    $ok = $action->performAction(Order::find()->id($ids)->status(null));
    $orderIds = array_map(static fn(PushDocumentJob $job) => $job->orderId, $queued($before));
    Craft::$app->getDb()->createCommand()->delete(craft\db\Table::QUEUE, ['>', 'id', $before])->execute();
    Craft::$app->getUser()->setIdentity(null);
    sort($orderIds);
    $want = [(int)$fixtures['none']->id, (int)$fixtures['failed']->id];
    sort($want);

    return $ok && $orderIds === $want && str_contains((string)$action->getMessage(), '1 incomplete')
        ?: json_encode(['ok' => $ok, 'orders' => $orderIds, 'message' => $action->getMessage()]);
});

check('it is offered to someone who may send, and not to someone who may only view', function() {
    $offered = static function(?User $user): bool {
        Craft::$app->getUser()->setIdentity($user);
        $event = new craft\events\RegisterElementActionsEvent(['source' => '*', 'actions' => []]);
        (new Order())->trigger(craft\base\Element::EVENT_REGISTER_ACTIONS, $event);
        Craft::$app->getUser()->setIdentity(null);

        return in_array(SendToVisma::class, $event->actions, true);
    };

    [$viewer] = makeUser('viewonly', ['accesscp', 'accessplugin-vismaz', 'vismaz-viewdocuments']);

    return $offered(User::find()->admin()->one()) && !$offered($viewer) ?: 'offered wrongly';
});

// Commerce's own order actions need a site the user may edit, or the index 500s before any
// action is reached — a refusal that would pass vacuously.
$sitePermission = 'editsite:' . Craft::$app->getSites()->getPrimarySite()->uid;
[$viewer, $viewerPassword] = makeUser('orders', ['accesscp', $sitePermission, 'accessplugin-commerce', 'commerce-manageorders', 'commerce-editorders', 'accessplugin-vismaz', 'vismaz-viewdocuments']);

check('over HTTP, a user without “Send orders to Visma” cannot run it', function() use ($viewer, $viewerPassword, $fixtures, $queued) {
    $before = (int)(new Query())->from(craft\db\Table::QUEUE)->max('id');
    $response = client($viewer->username, $viewerPassword)('element-indexes/perform-action', [
        'elementType' => Order::class,
        'source' => '*',
        'context' => 'index',
        'viewState' => ['mode' => 'table', 'static' => false],
        'elementAction' => SendToVisma::class,
        'elementIds' => [$fixtures['none']->id],
    ], 'POST', true, true);
    $data = json_decode((string)$response->getBody(), true);

    // A 400/403 from the action check, not a 500 from somewhere on the way.
    return in_array($response->getStatusCode(), [400, 403], true) && empty($data['success']) && $queued($before) === []
        ?: $response->getStatusCode() . ' ' . substr((string)$response->getBody(), 0, 300);
});

check('over HTTP, the same user still sees the column (they may view documents)', function() use ($viewer, $viewerPassword, $fixtures) {
    $response = client($viewer->username, $viewerPassword)('element-indexes/get-elements', [
        'elementType' => Order::class,
        'source' => '*',
        'context' => 'index',
        'viewState' => ['mode' => 'table', 'static' => false, 'tableColumns' => ['vismazStatus']],
        'criteria' => ['id' => [$fixtures['mismatched']->id], 'status' => null, 'isCompleted' => null],
    ], 'POST', true, true);
    $html = json_decode((string)$response->getBody(), true)['html'] ?? '';

    return $response->getStatusCode() === 200 && str_contains($html, 'Mismatched') ?: $response->getStatusCode() . ' ' . substr(strip_tags((string)$response->getBody()), 0, 300);
});

check('over HTTP, an admin reaches the action (the saved config is not connected, so it says so)', function() use ($fixtures) {
    $response = client('admin', 'claudepassword')('element-indexes/perform-action', [
        'elementType' => Order::class,
        'source' => '*',
        'context' => 'index',
        'viewState' => ['mode' => 'table', 'static' => false],
        'elementAction' => SendToVisma::class,
        'elementIds' => [$fixtures['none']->id],
    ], 'POST', true, true);
    $body = (string)$response->getBody();

    return str_contains($body, 'not connected') || str_contains($body, 'queued for Visma') ?: $response->getStatusCode() . ' ' . substr($body, 0, 300);
});

check('over HTTP, the action needs a CSRF token', function() use ($fixtures) {
    $response = client('admin', 'claudepassword')('element-indexes/perform-action', [
        'elementType' => Order::class,
        'source' => '*',
        'context' => 'index',
        'viewState' => ['mode' => 'table', 'static' => false],
        'elementAction' => SendToVisma::class,
        'elementIds' => [$fixtures['none']->id],
    ], 'POST', false, true);

    return $response->getStatusCode() === 400 ?: 'status ' . $response->getStatusCode();
});

check('the queued job never posts a mismatched invoice a second time', function() use ($plugin, $fixtures, &$journal) {
    mockVisma([vismaReply(201, ['Id' => 'should-not-be-asked'])]);
    (new PushDocumentJob(['orderId' => (int)$fixtures['mismatched']->id]))->execute(Craft::$app->getQueue());

    // The job builds with remote lookups on, which may sync the customer first; it must never
    // reach the invoice endpoint.
    return !in_array('POST /v2/customerinvoices', sentRequests(), true) ?: json_encode(sentRequests());
});

disconnectFixture();

finish();
