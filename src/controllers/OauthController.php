<?php

namespace justinholtweb\vismaz\controllers;

use Craft;
use craft\web\Controller;
use craft\helpers\UrlHelper;
use justinholtweb\vismaz\Plugin;
use Throwable;
use yii\web\Response;

/**
 * The OAuth2 handshake.
 */
class OauthController extends Controller
{
    protected array|bool|int $allowAnonymous = false;

    /**
     * Send the merchant to Visma.
     */
    public function actionConnect(): Response
    {
        $this->requireAdmin();

        $auth = Plugin::getInstance()->getAuth();

        if (!$auth->isConfigured()) {
            Craft::$app->getSession()->setError(Craft::t('vismaz', 'Add your Visma client ID and secret first.'));

            return $this->redirect('settings/plugins/vismaz');
        }

        return $this->redirect($auth->getAuthorizationUrl());
    }

    /**
     * Where Visma sends them back.
     *
     * The `state` is single-use and carries the environment it was issued for, so a callback
     * cannot be replayed and cannot land a sandbox token on a production connection.
     */
    public function actionCallback(): Response
    {
        $this->requireAdmin();

        $request = Craft::$app->getRequest();
        $session = Craft::$app->getSession();
        $plugin = Plugin::getInstance();

        $error = $request->getQueryParam('error');

        if ($error !== null) {
            $description = $request->getQueryParam('error_description') ?: $error;
            $plugin->getLog()->error('auth.callback', (string)$description);
            $session->setError(Craft::t('vismaz', 'Visma refused the connection: {error}', ['error' => $description]));

            return $this->redirect('settings/plugins/vismaz');
        }

        $code = $request->getQueryParam('code');
        $state = (string)$request->getQueryParam('state');

        if (!$code || $plugin->getAuth()->consumeState($state) === null) {
            $session->setError(Craft::t('vismaz', 'That Visma sign-in could not be verified. Please try again.'));

            return $this->redirect('settings/plugins/vismaz');
        }

        try {
            $record = $plugin->getAuth()->exchangeCode((string)$code, Craft::$app->getUser()->getId());

            $session->setNotice(Craft::t('vismaz', 'Connected to {company}.', [
                'company' => $record->companyName ?: Craft::t('vismaz', 'Visma'),
            ]));
        } catch (Throwable $e) {
            $session->setError($e->getMessage());
        }

        return $this->redirect('settings/plugins/vismaz');
    }

    public function actionDisconnect(): Response
    {
        $this->requireAdmin();
        $this->requirePostRequest();

        Plugin::getInstance()->getAuth()->disconnect();
        Craft::$app->getSession()->setNotice(Craft::t('vismaz', 'Disconnected from Visma.'));

        return $this->redirect('settings/plugins/vismaz');
    }

    /**
     * "Test connection" — answers with a message rather than an exception.
     */
    public function actionTest(): Response
    {
        $this->requireAdmin();
        $this->requireAcceptsJson();

        $result = Plugin::getInstance()->getApi()->test();

        return $this->asJson($result);
    }

    /**
     * The redirect URI to register with the Visma client, shown in settings.
     */
    public function actionRedirectUri(): Response
    {
        $this->requireAdmin();
        $this->requireAcceptsJson();

        return $this->asJson(['redirectUri' => Plugin::getInstance()->getAuth()->getRedirectUri()]);
    }
}
