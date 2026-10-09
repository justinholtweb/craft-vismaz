<?php

namespace justinholtweb\vismaz;

use Craft;
use craft\base\Element;
use craft\base\Model;
use craft\base\Plugin as BasePlugin;
use craft\commerce\elements\conditions\orders\OrderCondition;
use craft\commerce\elements\db\OrderQuery;
use craft\commerce\elements\Order;
use craft\commerce\events\TransactionEvent;
use craft\commerce\services\Transactions;
use craft\events\DefineAttributeHtmlEvent;
use craft\events\PopulateElementsEvent;
use craft\events\RegisterComponentTypesEvent;
use craft\events\RegisterConditionRulesEvent;
use craft\events\RegisterElementActionsEvent;
use craft\events\RegisterElementTableAttributesEvent;
use craft\events\RegisterUrlRulesEvent;
use craft\events\RegisterUserPermissionsEvent;
use craft\helpers\Html;
use craft\services\Dashboard;
use craft\services\UserPermissions;
use craft\web\twig\variables\CraftVariable;
use craft\web\UrlManager;
use craft\web\View;
use justinholtweb\vismaz\elements\actions\SendToVisma;
use justinholtweb\vismaz\elements\conditions\VismaStatusConditionRule;
use justinholtweb\vismaz\jobs\PushDocumentJob;
use justinholtweb\vismaz\models\Settings;
use justinholtweb\vismaz\services\Alerts;
use justinholtweb\vismaz\services\Api;
use justinholtweb\vismaz\services\Articles;
use justinholtweb\vismaz\services\Auth;
use justinholtweb\vismaz\services\Customers;
use justinholtweb\vismaz\services\Documents;
use justinholtweb\vismaz\services\Log;
use justinholtweb\vismaz\services\OrderStatus;
use justinholtweb\vismaz\services\Payments;
use justinholtweb\vismaz\services\Sie;
use justinholtweb\vismaz\services\Sync;
use justinholtweb\vismaz\services\Tax;
use justinholtweb\vismaz\twig\VismazVariable;
use justinholtweb\vismaz\widgets\HealthWidget;
use Throwable;
use yii\base\Event;

/**
 * Vismaz — Visma eAccounting integration for Craft Commerce.
 *
 * @property-read Auth $auth
 * @property-read Api $api
 * @property-read Tax $tax
 * @property-read Documents $documents
 * @property-read Sync $sync
 * @property-read Customers $customers
 * @property-read Articles $articles
 * @property-read Sie $sie
 * @property-read Log $log
 * @property-read Payments $payments
 * @property-read Alerts $alerts
 * @property-read OrderStatus $orderStatus
 * @method Settings getSettings()
 */
class Plugin extends BasePlugin
{
    /**
     * Connect, reconnect, test and disconnect Visma. Not admin-only, and not tied to
     * allowAdminChanges: the connection is runtime data in the database, and production — where
     * admin changes are off — is exactly where a revoked token has to be reconnected.
     */
    public const PERMISSION_MANAGE_CONNECTION = 'vismaz-manageConnection';

    public const HANDLE = 'vismaz';

    public string $schemaVersion = '5.2.0';
    public bool $hasCpSettings = true;
    public bool $hasCpSection = true;

    public static function config(): array
    {
        return [
            'components' => [
                'auth' => ['class' => Auth::class],
                'api' => ['class' => Api::class],
                'tax' => ['class' => Tax::class],
                'documents' => ['class' => Documents::class],
                'sync' => ['class' => Sync::class],
                'customers' => ['class' => Customers::class],
                'articles' => ['class' => Articles::class],
                'sie' => ['class' => Sie::class],
                'log' => ['class' => Log::class],
                'payments' => ['class' => Payments::class],
                'alerts' => ['class' => Alerts::class],
                'orderStatus' => ['class' => OrderStatus::class],
            ],
        ];
    }

    public function init(): void
    {
        parent::init();

        $this->_registerTwigVariable();
        $this->_registerPermissions();
        $this->_registerCpRoutes();
        $this->_registerWidgets();

        // The plugin can be installed while Commerce is disabled or mid-upgrade, and everything
        // below touches an order.
        if (!self::commerceIsReady()) {
            return;
        }

        $this->_registerOrderEditPanel();
        $this->_registerOrderCompletion();
        $this->_registerPayments();
        $this->_registerOrderIndex();
    }

    public static function commerceIsReady(): bool
    {
        return class_exists(\craft\commerce\Plugin::class)
            && Craft::$app->getPlugins()->isPluginEnabled('commerce');
    }

    public function getAuth(): Auth
    {
        return $this->get('auth');
    }

    public function getApi(): Api
    {
        return $this->get('api');
    }

    public function getTax(): Tax
    {
        return $this->get('tax');
    }

    public function getDocuments(): Documents
    {
        return $this->get('documents');
    }

    public function getSync(): Sync
    {
        return $this->get('sync');
    }

    public function getCustomers(): Customers
    {
        return $this->get('customers');
    }

    public function getArticles(): Articles
    {
        return $this->get('articles');
    }

    public function getSie(): Sie
    {
        return $this->get('sie');
    }

    public function getLog(): Log
    {
        return $this->get('log');
    }

    public function getPayments(): Payments
    {
        return $this->get('payments');
    }

    public function getAlerts(): Alerts
    {
        return $this->get('alerts');
    }

    public function getOrderStatus(): OrderStatus
    {
        return $this->get('orderStatus');
    }

    protected function createSettingsModel(): ?Model
    {
        return Craft::createObject(Settings::class);
    }

    protected function settingsHtml(): ?string
    {
        return Craft::$app->getView()->renderTemplate('vismaz/settings', [
            'plugin' => $this,
            'settings' => $this->getSettings(),
        ]);
    }

    public function getCpNavItem(): ?array
    {
        $item = parent::getCpNavItem();
        $item['label'] = Craft::t('vismaz', 'Vismaz');

        $user = Craft::$app->getUser();
        $subNav = [];

        if ($user->checkPermission('vismaz-viewDocuments')) {
            $subNav['documents'] = [
                'label' => Craft::t('vismaz', 'Documents'),
                'url' => 'vismaz/documents',
            ];
        }

        if ($user->checkPermission('vismaz-viewDocuments')) {
            $subNav['oss'] = [
                'label' => Craft::t('vismaz', 'OSS report'),
                'url' => 'vismaz/reports/oss',
            ];
        }

        if ($user->checkPermission('vismaz-viewLog')) {
            $subNav['log'] = [
                'label' => Craft::t('vismaz', 'Log'),
                'url' => 'vismaz/log',
            ];
        }

        if ($user->checkPermission(self::PERMISSION_MANAGE_CONNECTION)) {
            $subNav['connection'] = [
                'label' => Craft::t('vismaz', 'Connection'),
                'url' => 'vismaz/connection',
            ];
        }

        if ($user->getIsAdmin() && Craft::$app->getConfig()->getGeneral()->allowAdminChanges) {
            $subNav['settings'] = [
                'label' => Craft::t('vismaz', 'Settings'),
                'url' => 'settings/plugins/vismaz',
            ];
        }

        if (!$subNav) {
            return null;
        }

        $item['subnav'] = $subNav;

        return $item;
    }

    private function _registerTwigVariable(): void
    {
        Event::on(
            CraftVariable::class,
            CraftVariable::EVENT_INIT,
            static function(Event $event) {
                /** @var CraftVariable $variable */
                $variable = $event->sender;
                $variable->set('vismaz', VismazVariable::class);
            }
        );
    }

    private function _registerPermissions(): void
    {
        Event::on(
            UserPermissions::class,
            UserPermissions::EVENT_REGISTER_PERMISSIONS,
            static function(RegisterUserPermissionsEvent $event) {
                $event->permissions[] = [
                    'heading' => Craft::t('vismaz', 'Vismaz'),
                    'permissions' => [
                        'vismaz-viewDocuments' => [
                            'label' => Craft::t('vismaz', 'View Visma documents'),
                            'nested' => [
                                'vismaz-pushDocuments' => [
                                    'label' => Craft::t('vismaz', 'Send orders to Visma'),
                                ],
                            ],
                        ],
                        'vismaz-viewLog' => [
                            'label' => Craft::t('vismaz', 'View the connection log'),
                        ],
                        self::PERMISSION_MANAGE_CONNECTION => [
                            'label' => Craft::t('vismaz', 'Connect, test and disconnect Visma'),
                        ],
                        'vismaz-exportSie' => [
                            'label' => Craft::t('vismaz', 'Export SIE files'),
                        ],
                    ],
                ];
            }
        );
    }

    private function _registerCpRoutes(): void
    {
        Event::on(
            UrlManager::class,
            UrlManager::EVENT_REGISTER_CP_URL_RULES,
            static function(RegisterUrlRulesEvent $event) {
                $event->rules['vismaz'] = 'vismaz/documents/index';
                $event->rules['vismaz/documents'] = 'vismaz/documents/index';
                $event->rules['vismaz/documents/<documentId:\d+>'] = 'vismaz/documents/detail';
                $event->rules['vismaz/log'] = 'vismaz/log/index';
                $event->rules['vismaz/log/<entryId:\d+>'] = 'vismaz/log/detail';
                $event->rules['vismaz/reports/oss'] = 'vismaz/reports/oss';
                $event->rules['vismaz/connection'] = 'vismaz/connection/index';
            }
        );
    }

    private function _registerWidgets(): void
    {
        Event::on(
            Dashboard::class,
            Dashboard::EVENT_REGISTER_WIDGET_TYPES,
            static function(RegisterComponentTypesEvent $event) {
                $event->types[] = HealthWidget::class;
            }
        );
    }

    /**
     * The Orders index: a Visma column, a "Visma status" filter, and a bulk "Send to Visma"
     * action.
     *
     * Every hook is attached to the Order class, not to Element: the table-attribute events do not
     * say which element type is asking.
     */
    private function _registerOrderIndex(): void
    {
        // Registered unconditionally. A rule registered only for some users or settings is
        // dropped from saved conditions, and a custom source built on it silently widens to
        // every order.
        Event::on(
            OrderCondition::class,
            OrderCondition::EVENT_REGISTER_CONDITION_RULES,
            static function(RegisterConditionRulesEvent $event) {
                $event->conditionRules[] = VismaStatusConditionRule::class;
            }
        );

        Event::on(
            Order::class,
            Element::EVENT_REGISTER_TABLE_ATTRIBUTES,
            static function(RegisterElementTableAttributesEvent $event) {
                $event->tableAttributes['vismazStatus'] = ['label' => Craft::t('vismaz', 'Visma')];
            }
        );

        Event::on(
            Order::class,
            Element::EVENT_DEFINE_ATTRIBUTE_HTML,
            static function(DefineAttributeHtmlEvent $event) {
                if ($event->attribute !== 'vismazStatus') {
                    return;
                }

                /** @var Order $order */
                $order = $event->sender;
                $event->html = Plugin::getInstance()->orderStatusHtml($order);
                $event->handled = true;
            }
        );

        // One lookup per index page rather than per row.
        Event::on(
            OrderQuery::class,
            OrderQuery::EVENT_AFTER_POPULATE_ELEMENTS,
            static function(PopulateElementsEvent $event) {
                $request = Craft::$app->getRequest();

                if ($request->getIsConsoleRequest() || !$request->getIsCpRequest() || ($request->getActionSegments()[0] ?? null) !== 'element-indexes') {
                    return;
                }

                $ids = [];

                foreach ($event->elements as $element) {
                    if ($element instanceof Order && $element->id) {
                        $ids[] = $element->id;
                    }
                }

                try {
                    Plugin::getInstance()->getOrderStatus()->prefetch($ids);
                } catch (Throwable $e) {
                    // The column falls back to one lookup per row; the index itself must load.
                    Craft::warning('Vismaz could not prefetch order statuses: ' . $e->getMessage(), 'vismaz');
                }
            }
        );

        Event::on(
            Order::class,
            Element::EVENT_REGISTER_ACTIONS,
            static function(RegisterElementActionsEvent $event) {
                // Actions are not saved anywhere, so offering this only to people who may use it
                // is safe; the action checks the permission again when it runs.
                if (Craft::$app->getUser()->checkPermission('vismaz-pushDocuments')) {
                    $event->actions[] = SendToVisma::class;
                }
            }
        );
    }

    /**
     * The Orders index cell: a status dot and a word.
     */
    public function orderStatusHtml(Order $order): string
    {
        if (!$order->id || !Craft::$app->getUser()->checkPermission('vismaz-viewDocuments')) {
            return '';
        }

        $status = $this->getOrderStatus()->orderStatus((int)$order->id);
        $label = OrderStatus::options()[$status] ?? $status;
        $color = match ($status) {
            OrderStatus::SYNCED => 'green',
            OrderStatus::MISMATCHED => 'orange',
            OrderStatus::FAILED => 'red',
            OrderStatus::PENDING => 'yellow',
            default => 'disabled',
        };

        if ($status === OrderStatus::NONE) {
            return Html::tag('span', Html::encode($label), ['class' => 'light']);
        }

        return Html::tag('span', '', ['class' => ['status', $color], 'aria-hidden' => 'true'])
            . Html::encode($label);
    }

    /**
     * Vismaz's panel on Commerce's own order edit screen.
     */
    private function _registerOrderEditPanel(): void
    {
        Craft::$app->getView()->hook('cp.commerce.order.edit.details', function(array &$context) {
            $order = $context['order'] ?? null;

            if (!$order instanceof Order || !$order->id) {
                return null;
            }

            if (!Craft::$app->getUser()->checkPermission('vismaz-viewDocuments')) {
                return null;
            }

            $treatment = null;

            try {
                $treatment = $this->getTax()->treatOrder($order);
            } catch (Throwable) {
                // A panel that cannot describe the tax treatment still shows the sync state.
            }

            return Craft::$app->getView()->renderTemplate('vismaz/_order-panel', [
                'order' => $order,
                'documents' => $this->getSync()->getDocumentsForOrder($order->id),
                'payments' => $this->getPayments()->getPaymentsForOrder($order->id),
                'invoiceMode' => $this->getSettings()->documentMode === Settings::MODE_INVOICE,
                'treatment' => $treatment,
                'connected' => $this->getAuth()->isConnected(),
                'canPush' => Craft::$app->getUser()->checkPermission('vismaz-pushDocuments'),
            ], View::TEMPLATE_MODE_CP);
        });
    }

    /**
     * Queue a push when an order completes.
     *
     * `EVENT_AFTER_COMPLETE_ORDER` and a *queued* job, both deliberately: the order is already
     * saved by the time this runs, and nothing here touches the network, so no Visma problem can
     * reach the customer paying.
     */
    private function _registerOrderCompletion(): void
    {
        Event::on(
            Order::class,
            Order::EVENT_AFTER_COMPLETE_ORDER,
            static function(Event $event) {
                $plugin = Plugin::getInstance();

                if (!$plugin->getSettings()->autoPush) {
                    return;
                }

                /** @var Order $order */
                $order = $event->sender;

                try {
                    if ($order->id && $plugin->getSync()->isEligible($order)) {
                        Craft::$app->getQueue()->push(new PushDocumentJob(['orderId' => $order->id]));
                    }
                } catch (Throwable $e) {
                    // Never let a bookkeeping integration break a checkout.
                    Craft::error('Vismaz could not queue order ' . $order->id . ': ' . $e->getMessage(), 'vismaz');
                }
            }
        );
    }

    /**
     * Queue a payment registration when Commerce records money received.
     *
     * `EVENT_AFTER_SAVE_TRANSACTION` rather than `Order::EVENT_AFTER_ORDER_PAID`, because the
     * latter fires once, when the order is paid *in full* — a partial payment, or the second half
     * of a split one, would never be registered. Queued, like the push, so nothing here can reach
     * the customer paying.
     */
    private function _registerPayments(): void
    {
        Event::on(
            Transactions::class,
            Transactions::EVENT_AFTER_SAVE_TRANSACTION,
            static function(TransactionEvent $event) {
                try {
                    Plugin::getInstance()->getPayments()->queueTransaction($event->transaction);
                } catch (Throwable $e) {
                    Craft::error('Vismaz could not queue payment ' . $event->transaction->id . ': ' . $e->getMessage(), 'vismaz');
                }
            }
        );
    }
}
