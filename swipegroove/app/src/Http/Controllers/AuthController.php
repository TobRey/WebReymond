<?php
declare(strict_types=1);

namespace SwipeGroove\Http\Controllers;

use SwipeGroove\Auth\AccountService;
use SwipeGroove\Auth\SessionManager;
use SwipeGroove\Core\App;
use SwipeGroove\Core\HttpException;
use SwipeGroove\Core\I18n;
use SwipeGroove\Core\Request;
use SwipeGroove\Core\Response;
use SwipeGroove\Spotify\SpotifyAuth;
use SwipeGroove\Spotify\SpotifyClient;
use SwipeGroove\Spotify\SpotifyException;

final class AuthController
{
    public function __construct(private App $app)
    {
    }

    /** GET /auth/spotify/start?purpose=login|reconnect */
    public function start(Request $request): Response
    {
        $auth = $this->app->service(SpotifyAuth::class);
        if (!$auth->isConfigured()) {
            return $this->fail('auth.spotify_not_configured');
        }
        $this->app->limiter()->enforce('oauth:' . $request->ip, 20, 600, 'error.rate_limited');
        $purpose = $request->queryStr('purpose', 'login');
        $lang = I18n::normalize($request->queryStr('lang', $this->app->i18n->lang()));
        $redirectTo = $request->queryStr('redirect', '');
        if ($redirectTo !== '' && !preg_match('#^/[A-Za-z0-9_\-/?=&%.]*$#', $redirectTo)) {
            $redirectTo = '';
        }
        if ($purpose === 'reconnect') {
            if (!$request->user) {
                throw HttpException::unauthorized();
            }
            $url = $auth->startUrl('reconnect', $request->userId(), false, $lang, $redirectTo ?: null);
            return Response::redirect($url);
        }
        $accounts = $this->app->service(AccountService::class);
        if (!$accounts->ownerExists()) {
            // Erstregistrierung nur über /setup mit Einrichtungsschlüssel
            $url = $this->app->url->path('/setup');
            return Response::redirect($url);
        }
        $url = $auth->startUrl('login', null, false, $lang, $redirectTo ?: null);
        return Response::redirect($url);
    }

    /** POST /api/auth/setup-start {setup_key, lang} → {url} */
    public function setupStart(Request $request): array
    {
        $this->app->limiter()->enforce('setup:' . $request->ip, 8, 900, 'error.rate_limited');
        $accounts = $this->app->service(AccountService::class);
        $auth = $this->app->service(SpotifyAuth::class);
        if ($accounts->ownerExists()) {
            throw HttpException::forbidden('auth.setup_done');
        }
        if (!$auth->isConfigured()) {
            throw HttpException::badRequest('auth.spotify_not_configured');
        }
        $key = $request->str('setup_key');
        if ($key === '' || !$accounts->verifySetupKey($key)) {
            throw HttpException::forbidden('auth.setup_key_invalid');
        }
        $lang = I18n::normalize($request->str('lang', $this->app->i18n->lang()));
        return ['url' => $auth->startUrl('setup', null, true, $lang)];
    }

    /** GET /auth/spotify/callback */
    public function callback(Request $request): Response
    {
        $auth = $this->app->service(SpotifyAuth::class);
        $nonce = $_COOKIE[SpotifyAuth::BROWSER_COOKIE] ?? null;
        $state = $auth->consumeState($request->queryStr('state'), is_string($nonce) ? $nonce : null);
        if (!$state) {
            return $this->fail('auth.state_invalid');
        }
        $lang = (string) ($state['lang'] ?? 'de');
        $this->app->i18n->setLang($lang);
        if ($request->queryStr('error') !== '') {
            return $this->fail($request->queryStr('error') === 'access_denied' ? 'auth.denied' : 'auth.spotify_error');
        }
        $code = $request->queryStr('code');
        if ($code === '') {
            return $this->fail('auth.spotify_error');
        }
        try {
            $tokens = $auth->exchangeCode($code, (string) $state['code_verifier']);
            $profile = $this->app->service(SpotifyClient::class)->me($tokens['access_token']);
            $user = $this->app->service(AccountService::class)->loginOrRegister($profile, $tokens, $state, $request);
        } catch (SpotifyException $e) {
            $this->app->logger->warning('OAuth-Callback fehlgeschlagen', ['reason' => $e->reason, 'status' => $e->status]);
            return $this->fail('auth.spotify_error');
        } catch (HttpException $e) {
            return $this->fail($e->errorCode);
        }
        $this->app->service(SessionManager::class)->setLangCookie((string) $user->lang);
        $target = (string) ($state['redirect_to'] ?? '');
        if ($target === '' || !str_starts_with($target, '/')) {
            $target = !$user->onboarding_done ? '/welcome' : '/';
        }
        return Response::redirect($this->app->url->path($target));
    }

    private function fail(string $code): Response
    {
        return Response::redirect($this->app->url->path('/login') . '?error=' . rawurlencode($code));
    }

    /** POST /api/auth/logout */
    public function logout(Request $request): array
    {
        $this->app->service(SessionManager::class)->logout($request);
        return ['ok' => true];
    }

    /** POST /api/auth/logout-all */
    public function logoutAll(Request $request): array
    {
        $this->app->service(SessionManager::class)->logoutEverywhere($request->userId());
        return ['ok' => true];
    }
}
