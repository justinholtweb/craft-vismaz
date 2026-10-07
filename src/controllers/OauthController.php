<?php

namespace justinholtweb\vismaz\controllers;

use Craft;
use craft\web\Controller;
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
        $this->requirePermission(Plugin::PERMISSION_MANAGE_CONNECTION);

        $auth = Plugin::getInstance()->getAuth();

        if (!$auth->isConfigured()) {
            Craft::$app->getSession()->setError(Craft::t('vismaz', 'Add your Visma client ID and secret first.'));

            return $this->redirect('vismaz/connection');
        }

        return $this->redirect($auth->getAuthorizationUrl());
    }

    /**
     * Where Visma sends them back.
     *
     * The `state` is single-use and carries the environment and user it was issued for, both
     * checked here, so a callback cannot be replayed and cannot land a sandbox token on a
     * production connection. (Until 5.0.1 only its existence was checked.)
     */
    public function actionCallback(): Response
    {
        $this->requirePermission(Plugin::PERMISSION_MANAGE_CONNECTION);

        $request = Craft::$app->getRequest();
        $session = Craft::$app->getSession();
        $plugin = Plugin::getInstance();

        $error = $request->getQueryParam('error');

        if ($error !== null) {
            $description = $request->getQueryParam('error_description') ?: $error;
            $plugin->getLog()->error('auth.callback', (string)$description);
            $session->setError(Craft::t('vismaz', 'Visma refused the connection: {error}', ['error' => $description]));

            return $this->redirect('vismaz/connection');
        }

        $code = $request->getQueryParam('code');
        $state = (string)$request->getQueryParam('state');

        $issued = $code ? $plugin->getAuth()->consumeState($state) : null;

        // The state is single-use, and it also records what it was issued for. A callback for a
        // different environment — settings switched between connect and return — or a different
        // user is refused rather than storing the token wherever the settings point now.
        if (
            $issued === null
            || ($issued['environment'] ?? null) !== $plugin->getSettings()->environment
            || (int)($issued['userId'] ?? 0) !== (int)Craft::$app->getUser()->getId()
        ) {
            $session->setError(Craft::t('vismaz', 'That Visma sign-in could not be verified. Please try again.'));

            return $this->redirect('vismaz/connection');
        }

        try {
            $record = $plugin->getAuth()->exchangeCode((string)$code, Craft::$app->getUser()->getId());

            $session->setNotice(Craft::t('vismaz', 'Connected to {company}.', [
                'company' => $record->companyName ?: Craft::t('vismaz', 'Visma'),
            ]));
        } catch (Throwable $e) {
            $session->setError($e->getMessage());
        }

        return $this->redirect('vismaz/connection');
    }

    public function actionDisconnect(): Response
    {
        $this->requirePermission(Plugin::PERMISSION_MANAGE_CONNECTION);
        $this->requirePostRequest();

        Plugin::getInstance()->getAuth()->disconnect();
        Craft::$app->getSession()->setNotice(Craft::t('vismaz', 'Disconnected from Visma.'));

        return $this->redirect('vismaz/connection');
    }

    /**
     * "Test connection" — answers with a message rather than an exception.
     */
    public function actionTest(): Response
    {
        $this->requirePermission(Plugin::PERMISSION_MANAGE_CONNECTION);
        $this->requireAcceptsJson();

        $result = Plugin::getInstance()->getApi()->test();

        return $this->asJson($result);
    }

    /**
     * The redirect URI to register with the Visma client, shown in settings.
     */
    public function actionRedirectUri(): Response
    {
        $this->requirePermission(Plugin::PERMISSION_MANAGE_CONNECTION);
        $this->requireAcceptsJson();

        return $this->asJson(['redirectUri' => Plugin::getInstance()->getAuth()->getRedirectUri()]);
    }
}
