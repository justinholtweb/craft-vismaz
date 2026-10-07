<?php

namespace justinholtweb\vismaz;

use Craft;
use craft\base\Model;
use craft\base\Plugin as BasePlugin;
use craft\commerce\elements\Order;
use craft\events\RegisterUrlRulesEvent;
use craft\events\RegisterUserPermissionsEvent;
use craft\services\UserPermissions;
use craft\web\twig\variables\CraftVariable;
use craft\web\UrlManager;
use craft\web\View;
use justinholtweb\vismaz\jobs\PushDocumentJob;
use justinholtweb\vismaz\models\Settings;
use justinholtweb\vismaz\services\Api;
use justinholtweb\vismaz\services\Articles;
use justinholtweb\vismaz\services\Auth;
use justinholtweb\vismaz\services\Customers;
use justinholtweb\vismaz\services\Documents;
use justinholtweb\vismaz\services\Log;
use justinholtweb\vismaz\services\Sie;
use justinholtweb\vismaz\services\Sync;
use justinholtweb\vismaz\services\Tax;
use justinholtweb\vismaz\twig\VismazVariable;
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

    public string $schemaVersion = '5.0.0';
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
            ],
        ];
    }

    public function init(): void
    {
        parent::init();

        $this->_registerTwigVariable();
        $this->_registerPermissions();
        $this->_registerCpRoutes();

        // The plugin can be installed while Commerce is disabled or mid-upgrade, and everything
        // below touches an order.
        if (!self::commerceIsReady()) {
            return;
        }

        $this->_registerOrderEditPanel();
        $this->_registerOrderCompletion();
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
}
