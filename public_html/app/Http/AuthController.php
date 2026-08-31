<?php

declare(strict_types=1);

namespace WebAtze\Http;

use WebAtze\Core\{Audit, Config, Crypto, Db, Logger, RateLimit, Request, Response,
    SecondFactor, Session, Totp, View};

/**
 * Anmeldung zum versteckten Bereich.
 *
 * Der Bereich ist nirgends verlinkt und wird von Suchmaschinen ausgeschlossen.
 * Zusätzlich:
 *   - Sperre nach zu vielen Fehlversuchen, je IP und je Benutzername
 *   - immer dieselbe Fehlermeldung, egal ob Benutzer oder Passwort falsch war
 *   - immer dieselbe Antwortzeit, damit sich nichts an der Dauer ablesen lässt
 */
final class AuthController
{
    public function showLogin(Request $request): Response
    {
        if (Session::isLoggedIn()) {
            return $this->toDashboard();
        }

        return Response::html(View::page('admin/login', [
            'title' => 'Anmelden',
            'error' => Session::get('_wa_login_error', ''),
        ], 'layouts/bare'))->noCache()->noIndex();
    }

    public function login(Request $request): Response
    {
        Session::forget('_wa_login_error');

        $username = mb_strtolower(trim($request->input('username')));
        $password = $request->input('password');
        $ip = $request->ip();

        $maxAttempts = (int) Config::get('login_max_attempts', 5);
        $lockout = (int) Config::get('login_lockout_seconds', 900);

        // Zwei Zähler: einer je Herkunft, einer je Benutzername. Der zweite
        // verhindert, dass jemand mit vielen IP-Adressen ein Konto durchprobiert.
        $ipBucket = 'login:ip:' . $ip;
        $userBucket = 'login:user:' . $username;

        if (RateLimit::tooMany($ipBucket, $maxAttempts) || RateLimit::tooMany($userBucket, $maxAttempts * 3)) {
            $wait = max(RateLimit::retryAfter($ipBucket), RateLimit::retryAfter($userBucket));

            Logger::warning('Anmeldung gesperrt', ['ip' => $ip]);
            Audit::log('auth.locked', $username, ['ip' => $ip], $request);

            return $this->reject(
                sprintf('Zu viele Versuche. Bitte in %d Minuten erneut probieren.', (int) ceil($wait / 60))
            );
        }

        RateLimit::hit($ipBucket, $maxAttempts, $lockout);
        RateLimit::hit($userBucket, $maxAttempts * 3, $lockout);

        $user = $username === ''
            ? null
            : Db::first('SELECT * FROM users WHERE username = :u', ['u' => $username]);

        // Auch ohne gefundenen Benutzer wird ein Hash geprüft. Sonst wäre an
        // der Antwortzeit erkennbar, ob es den Benutzernamen überhaupt gibt.
        $hash = (string) ($user['password_hash'] ?? '$argon2id$v=19$m=65536,t=4,p=1$'
            . base64_encode(str_repeat('x', 16)) . '$' . base64_encode(str_repeat('y', 32)));

        $ok = Crypto::checkPassword($password, $hash) && $user !== null;

        Db::insert('login_attempts', [
            'ip' => $ip,
            'username' => mb_substr($username, 0, 64),
            'success' => $ok ? 1 : 0,
            'attempted_at' => Db::now(),
        ]);

        if (!$ok) {
            Audit::log('auth.failed', $username, ['ip' => $ip], $request);
            return $this->reject('Benutzername oder Passwort stimmt nicht.');
        }

        // Erfolg: Zähler zurücksetzen, damit die nächste Anmeldung frei ist.
        RateLimit::clear($ipBucket);
        RateLimit::clear($userBucket);

        // Zweiter Faktor eingerichtet? Dann ist hier erst die Hälfte
        // geschafft. Ohne Code gibt es keinen Zugang – auch nicht auf
        // Umwegen, denn die Sitzung bekommt noch keine Anmeldung.
        if (SecondFactor::isActive($user) && !SecondFactor::deviceIsTrusted((int) $user['id'], $request)) {
            SecondFactor::beginPending((int) $user['id']);
            Audit::log('auth.password_ok', $username, ['schritt' => 'wartet auf Code'], $request);

            return Response::redirect(self::base() . '/code')->noCache()->noIndex();
        }

        Session::login($user, $request);
        Audit::log('auth.login', $username, [], $request);

        $intended = Session::get('_wa_intended');
        Session::forget('_wa_intended');

        if (is_string($intended) && str_starts_with($intended, '/')) {
            return Response::redirect($intended)->noCache();
        }

        return $this->toDashboard();
    }

    /** Die Abfrage des Zeitcodes. */
    public function showCode(Request $request): Response
    {
        if (Session::isLoggedIn()) {
            return $this->toDashboard();
        }

        if (SecondFactor::pendingUser() === null) {
            return Response::redirect(self::base())->noCache()->noIndex();
        }

        $error = Session::get('_wa_code_error', '');
        Session::forget('_wa_code_error');

        return Response::html(View::page('admin/code', [
            'title' => 'Bestätigen',
            'error' => (string) $error,
        ], 'layouts/bare'))->noCache()->noIndex();
    }

    public function submitCode(Request $request): Response
    {
        $user = SecondFactor::pendingUser();

        if ($user === null) {
            Session::flash('error', 'Die Anmeldung hat zu lange gedauert. Bitte noch einmal.');
            return Response::redirect(self::base())->noCache()->noIndex();
        }

        $ip = $request->ip();
        $bucket = 'code:' . $ip;
        $max = (int) Config::get('login_max_attempts', 5);

        // Codes lassen sich sonst durchprobieren: Eine Million
        // Möglichkeiten sind ohne Sperre in Stunden durch.
        if (RateLimit::tooMany($bucket, $max)) {
            $wait = RateLimit::retryAfter($bucket);
            SecondFactor::clearPending();

            Audit::log('auth.code_locked', (string) $user['username'], ['ip' => $ip], $request);

            Session::flash('error', sprintf(
                'Zu viele Versuche. Bitte in %d Minuten erneut anmelden.',
                (int) ceil($wait / 60)
            ));

            return Response::redirect(self::base())->noCache()->noIndex();
        }

        RateLimit::hit($bucket, $max, (int) Config::get('login_lockout_seconds', 900));

        $result = SecondFactor::check($user, $request->input('code'));

        if (!$result['ok']) {
            Audit::log('auth.code_failed', (string) $user['username'], ['ip' => $ip], $request);
            Session::put('_wa_code_error', $result['error']);

            return Response::redirect(self::base() . '/code')->noCache()->noIndex();
        }

        RateLimit::clear($bucket);
        SecondFactor::clearPending();

        if ($request->has('trust')) {
            SecondFactor::trustDevice((int) $user['id'], $request);
        }

        Session::login($user, $request);

        Audit::log('auth.login', (string) $user['username'], [
            'zweiter_faktor' => $result['recovery'] ? 'Ersatzcode' : 'Zeitcode',
        ], $request);

        if ($result['recovery']) {
            $left = SecondFactor::recoveryCodesLeft(
                Db::first('SELECT * FROM users WHERE id = :id', ['id' => (int) $user['id']]) ?? []
            );

            Session::flash('warning', sprintf(
                'Mit einem Ersatzcode angemeldet. Es sind noch %d übrig – '
                . 'unter Einstellungen lassen sich neue erzeugen.',
                $left
            ));
        }

        $intended = Session::get('_wa_intended');
        Session::forget('_wa_intended');

        if (is_string($intended) && str_starts_with($intended, '/')) {
            return Response::redirect($intended)->noCache();
        }

        return $this->toDashboard();
    }

    public function logout(Request $request): Response
    {
        $user = Session::user();
        Audit::log('auth.logout', $user['username'] ?? '', [], $request);

        Session::logout();

        return Response::redirect('/' . trim((string) Config::get('create_path', 'create'), '/'))
            ->noCache()
            ->noIndex();
    }

    private static function base(): string
    {
        return '/' . trim((string) Config::get('create_path', 'create'), '/');
    }

    private function reject(string $message): Response
    {
        Session::put('_wa_login_error', $message);

        return Response::redirect('/' . trim((string) Config::get('create_path', 'create'), '/'))
            ->noCache()
            ->noIndex();
    }

    private function toDashboard(): Response
    {
        return Response::redirect(
            '/' . trim((string) Config::get('create_path', 'create'), '/') . '/start'
        )->noCache()->noIndex();
    }
}
