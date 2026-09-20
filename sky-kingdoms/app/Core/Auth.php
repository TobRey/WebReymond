<?php

declare(strict_types=1);

namespace SkyKingdoms\Core;

use SkyKingdoms\Game\Player;
use SkyKingdoms\Game\World;

/**
 * Registrierung, Anmeldung, „Angemeldet bleiben" und Passwort-Zurücksetzen.
 *
 * Gastspielen gibt es nicht: Ohne Konto ist kein Zugriff auf das Spiel möglich.
 */
final class Auth
{
    public const REMEMBER_COOKIE = 'sk_remember';

    /** Gültiger bcrypt-Wert ohne Bedeutung – nur zum Zeitausgleich. */
    private const DUMMY_HASH = '$2y$12$Phst.GYvnW2evZtC.u97UuD92tEqdj3Fv525vsTbEJIBaa7dXMoae';

    private static ?array $current = null;
    private static bool $resolved  = false;

    // =================================================================
    // Registrierung
    // =================================================================

    /**
     * Neues Konto samt Startkönigreich anlegen.
     *
     * @return array{ok:bool,error?:string,field?:string,uid?:string}
     */
    public static function register(string $username, string $email, string $password, array $roles = [Player::ROLE_PLAYER], string $kingdom = ''): array
    {
        if (!App::config('registration_open', true) && $roles === [Player::ROLE_PLAYER]) {
            return ['ok' => false, 'error' => 'Neue Anmeldungen sind derzeit geschlossen.'];
        }

        $username = Security::clean($username, 40);
        $email    = mb_strtolower(Security::clean($email, 191));

        $problems = Password::problems($password, $username, $email);
        if ($problems !== []) {
            return ['ok' => false, 'error' => $problems[0], 'field' => 'password'];
        }

        // Schnellprüfung (die endgültige Entscheidung fällt die Anspruchsdatei)
        if (Player::findByUsername($username) !== null) {
            return ['ok' => false, 'error' => 'Dieser Spielername ist schon vergeben.', 'field' => 'username'];
        }
        if (Player::findByEmail($email) !== null) {
            return ['ok' => false, 'error' => 'Zu dieser E-Mail-Adresse gibt es bereits ein Konto.', 'field' => 'email'];
        }

        $uid = Ids::generate(6);
        while (Player::exists($uid)) {
            $uid = Ids::generate(6);
        }

        $collision = Player::claimIdentity($uid, $username, $email);
        if ($collision !== null) {
            return [
                'ok'    => false,
                'field' => $collision,
                'error' => $collision === 'username'
                    ? 'Dieser Spielername wurde soeben vergeben.'
                    : 'Zu dieser E-Mail-Adresse gibt es bereits ein Konto.',
            ];
        }

        $now     = time();
        $account = [
            'id'         => $uid,
            'username'   => $username,
            'email'      => $email,
            'password'   => Password::hash($password),
            'roles'      => array_values(array_unique($roles)),
            'status'     => 'active',
            'created_at' => $now,
            'last_login' => 0,
            'last_seen'  => $now,
            'alliance'   => null,
            'remember'   => [],
            'settings'   => [
                'sound'      => true,
                'music'      => false,
                'animations' => true,
                'quality'    => 'auto',
                'haptics'    => true,
            ],
        ];

        $world = World::create($kingdom !== '' ? $kingdom : ($username . 's Reich'));

        try {
            Player::saveAccount($uid, $account);
            Player::saveWorld($uid, $world);
        } catch (\Throwable $e) {
            Player::releaseIdentity($username, $email);
            Logger::error('Registrierung fehlgeschlagen', ['fehler' => $e->getMessage()]);

            return ['ok' => false, 'error' => 'Das Konto konnte nicht angelegt werden. Bitte später erneut versuchen.'];
        }

        Audit::log('account.register', 'Neues Konto: ' . $username, ['uid' => $uid], $uid);

        return ['ok' => true, 'uid' => $uid];
    }

    // =================================================================
    // Anmeldung
    // =================================================================

    /**
     * Anmeldung mit Spielername ODER E-Mail-Adresse.
     *
     * @return array{ok:bool,error?:string,uid?:string,retry_after?:int}
     */
    public static function login(string $login, string $password, bool $remember = false): array
    {
        $login = Security::clean($login, 191);

        $ipCheck = RateLimit::attempt('login', RateLimit::identity());
        $idCheck = RateLimit::attempt('login', RateLimit::identity(mb_strtolower($login)));
        if (!$ipCheck['allowed'] || !$idCheck['allowed']) {
            $retry = max($ipCheck['retry_after'], $idCheck['retry_after']);
            Logger::warn('Anmeldung durch Rate-Limit gestoppt', ['login' => $login]);

            return [
                'ok'          => false,
                'error'       => 'Zu viele Anmeldeversuche. Bitte in ' . Num::duration($retry) . ' erneut versuchen.',
                'retry_after' => $retry,
            ];
        }

        $uid = str_contains($login, '@') ? Player::findByEmail($login) : Player::findByUsername($login);
        $account = $uid !== null ? Player::account($uid) : null;

        if ($account === null) {
            // Gleiche Rechenzeit wie bei falschem Passwort, damit nicht erkennbar
            // ist, ob es das Konto überhaupt gibt.
            Password::verify($password, self::DUMMY_HASH);

            return ['ok' => false, 'error' => 'Spielername oder Passwort stimmt nicht.'];
        }

        if (!Password::verify($password, (string) ($account['password'] ?? ''))) {
            Audit::log('account.login_failed', 'Fehlgeschlagene Anmeldung', ['login' => $login], (string) $uid);

            return ['ok' => false, 'error' => 'Spielername oder Passwort stimmt nicht.'];
        }

        $ban = Player::banReason($account);
        if ($ban !== '') {
            return ['ok' => false, 'error' => 'Dieses Konto ist gesperrt: ' . $ban];
        }

        // Erfolgreich: Zähler zurücksetzen und Passwort ggf. neu hashen.
        RateLimit::clear('login', RateLimit::identity());
        RateLimit::clear('login', RateLimit::identity(mb_strtolower($login)));

        if (Password::needsRehash((string) $account['password'])) {
            $account['password'] = Password::hash($password);
        }

        $account['last_login'] = time();
        $account['last_seen']  = time();
        Player::saveAccount((string) $uid, $account);

        Session::login((string) $uid, ['roles' => (array) ($account['roles'] ?? [])]);

        if ($remember) {
            self::issueRememberToken((string) $uid);
        }

        Audit::log('account.login', 'Anmeldung erfolgreich', [], (string) $uid);

        return ['ok' => true, 'uid' => (string) $uid];
    }

    public static function logout(): void
    {
        $uid = Session::userId();
        if ($uid !== null) {
            self::clearRememberToken($uid);
        }
        Session::logout();
    }

    /** Aktuell angemeldetes Konto (inklusive „Angemeldet bleiben"). */
    public static function user(): ?array
    {
        if (self::$resolved) {
            return self::$current;
        }
        self::$resolved = true;

        Session::start();
        $uid = Session::userId();

        if ($uid === null) {
            $uid = self::resolveRememberToken();
            if ($uid === null) {
                return self::$current = null;
            }
        }

        $account = Player::account($uid);
        if ($account === null || Player::banReason($account) !== '') {
            self::logout();

            return self::$current = null;
        }

        self::$current = $account;

        // „Zuletzt gesehen" höchstens einmal pro Minute schreiben.
        if (time() - (int) ($account['last_seen'] ?? 0) > 60) {
            Player::updateAccount($uid, static function (array $current): array {
                $current['last_seen'] = time();

                return $current;
            });
        }

        return self::$current;
    }

    public static function id(): ?string
    {
        $user = self::user();

        return $user === null ? null : (string) $user['id'];
    }

    public static function check(): bool
    {
        return self::user() !== null;
    }

    /** Zugriff nur für Angemeldete – sonst zur Anmeldung schicken. */
    public static function requireLogin(): array
    {
        $user = self::user();
        if ($user !== null) {
            return $user;
        }

        if (App::mode() === App::MODE_JSON) {
            Response::fail('Bitte melde dich an.', 401);
        }

        Response::redirect('?p=login');
    }

    /** Zugriff nur für Administratoren. */
    public static function requireAdmin(): array
    {
        $user = self::requireLogin();
        if (!Player::isAdmin($user)) {
            Logger::suspicious('Unberechtigter Adminzugriff', ['uid' => $user['id'] ?? '']);
            Response::forbidden('Dieser Bereich ist Administratoren vorbehalten.');
        }

        return $user;
    }

    // =================================================================
    // „Angemeldet bleiben"
    // =================================================================

    private static function issueRememberToken(string $uid): void
    {
        $selector  = bin2hex(random_bytes(9));
        $validator = bin2hex(random_bytes(32));
        $expires   = time() + max(3600, (int) App::config('remember_lifetime', 2592000));

        Player::updateAccount($uid, static function (array $account) use ($selector, $validator, $expires): array {
            $tokens = (array) ($account['remember'] ?? []);
            // Abgelaufene Einträge entfernen und höchstens fünf Geräte merken.
            $tokens = array_filter($tokens, static fn (array $t): bool => (int) ($t['expires'] ?? 0) > time());
            $tokens[$selector] = [
                'hash'    => hash('sha256', $validator),
                'expires' => $expires,
                'agent'   => substr((string) ($_SERVER['HTTP_USER_AGENT'] ?? ''), 0, 120),
            ];
            if (count($tokens) > 5) {
                $tokens = array_slice($tokens, -5, null, true);
            }
            $account['remember'] = $tokens;

            return $account;
        });

        App::store()->write(self::rememberKey($selector), ['uid' => $uid, 'expires' => $expires]);
        self::setRememberCookie($selector . ':' . $validator, $expires);
    }

    private static function resolveRememberToken(): ?string
    {
        $cookie = (string) ($_COOKIE[self::REMEMBER_COOKIE] ?? '');
        if ($cookie === '' || !str_contains($cookie, ':')) {
            return null;
        }

        [$selector, $validator] = explode(':', $cookie, 2);
        if (!preg_match('/^[a-f0-9]{18}$/', $selector) || !preg_match('/^[a-f0-9]{64}$/', $validator)) {
            self::setRememberCookie('', time() - 3600);

            return null;
        }

        // Der Selector zeigt direkt auf das Konto – kein Durchsuchen aller Spieler.
        $index = App::store()->read(self::rememberKey($selector));
        $uid   = is_array($index) ? (string) ($index['uid'] ?? '') : '';
        if ($uid === '' || !Player::exists($uid)) {
            self::setRememberCookie('', time() - 3600);

            return null;
        }

        $account = Player::account($uid);
        $token   = $account['remember'][$selector] ?? null;

        if ($token === null || (int) ($token['expires'] ?? 0) < time()) {
            self::removeToken($uid, $selector);
            self::setRememberCookie('', time() - 3600);

            return null;
        }

        if (!hash_equals((string) $token['hash'], hash('sha256', $validator))) {
            // Gestohlenes oder gefälschtes Merkmal: alle Merkmale dieses Kontos verwerfen.
            Logger::suspicious('Ungültiges Anmelde-Merkmal', ['uid' => $uid]);
            foreach (array_keys((array) ($account['remember'] ?? [])) as $known) {
                App::store()->delete(self::rememberKey((string) $known));
            }
            Player::updateAccount($uid, static function (array $current): array {
                $current['remember'] = [];

                return $current;
            });
            self::setRememberCookie('', time() - 3600);

            return null;
        }

        // Gültig: Merkmal erneuern (Rotation) und anmelden.
        self::removeToken($uid, $selector);
        Session::login($uid, ['roles' => (array) ($account['roles'] ?? [])]);
        self::issueRememberToken($uid);

        return $uid;
    }

    private static function rememberKey(string $selector): string
    {
        return 'index/remember/' . preg_replace('/[^a-f0-9]/', '', $selector) . '.json';
    }

    private static function clearRememberToken(string $uid): void
    {
        $cookie = (string) ($_COOKIE[self::REMEMBER_COOKIE] ?? '');
        if ($cookie !== '' && str_contains($cookie, ':')) {
            [$selector] = explode(':', $cookie, 2);
            if (preg_match('/^[a-f0-9]{18}$/', $selector)) {
                self::removeToken($uid, $selector);
            }
        }
        self::setRememberCookie('', time() - 3600);
    }

    private static function removeToken(string $uid, string $selector): void
    {
        App::store()->delete(self::rememberKey($selector));
        Player::updateAccount($uid, static function (array $account) use ($selector): array {
            unset($account['remember'][$selector]);

            return $account;
        });
    }

    private static function setRememberCookie(string $value, int $expires): void
    {
        if (PHP_SAPI === 'cli' || headers_sent()) {
            return;
        }

        $base = Url::base();
        setcookie(self::REMEMBER_COOKIE, $value, [
            'expires'  => $expires,
            'path'     => $base === '' ? '/' : $base . '/',
            'secure'   => Session::isSecure(),
            'httponly' => true,
            'samesite' => 'Lax',
        ]);
    }

    // =================================================================
    // Passwort zurücksetzen
    // =================================================================

    /**
     * Zurücksetzen anstossen. Aus Datenschutzgründen ist die Antwort immer
     * gleich – unabhängig davon, ob die Adresse existiert.
     *
     * @return array{ok:bool,token?:string,mailed:bool}
     */
    public static function requestPasswordReset(string $email): array
    {
        $email = mb_strtolower(Security::clean($email, 191));
        $uid   = Player::findByEmail($email);

        if ($uid === null) {
            return ['ok' => true, 'mailed' => false];
        }

        $token = bin2hex(random_bytes(32));
        App::store()->write('resets/' . hash('sha256', $token) . '.json', [
            'uid'     => $uid,
            'created' => time(),
            'expires' => time() + max(600, (int) App::config('reset_lifetime', 3600)),
            'ip'      => Audit::clientIp(),
        ]);

        Audit::log('account.reset_requested', 'Passwort-Zurücksetzen angefordert', [], $uid);

        $link = Url::absolute('?p=reset&token=' . $token);
        $sent = Mailer::send(
            $email,
            App::config('name', 'Sky Kingdoms') . ': Passwort zurücksetzen',
            "Hallo,\n\nmit diesem Link kannst du ein neues Passwort festlegen:\n"
            . $link . "\n\nDer Link ist " . Num::duration((int) App::config('reset_lifetime', 3600))
            . " gültig.\nWenn du das nicht angefordert hast, ignoriere diese Nachricht einfach.\n"
        );

        return ['ok' => true, 'mailed' => $sent, 'token' => $token];
    }

    /** Gültigkeit eines Zurücksetz-Merkmals prüfen. */
    public static function checkResetToken(string $token): ?string
    {
        if (!preg_match('/^[a-f0-9]{64}$/', $token)) {
            return null;
        }

        $entry = App::store()->read('resets/' . hash('sha256', $token) . '.json');
        if ($entry === null || (int) ($entry['expires'] ?? 0) < time()) {
            return null;
        }

        return (string) ($entry['uid'] ?? '') ?: null;
    }

    /** Neues Passwort setzen und alle Merkmale verwerfen. */
    public static function completePasswordReset(string $token, string $password): array
    {
        $uid = self::checkResetToken($token);
        if ($uid === null) {
            return ['ok' => false, 'error' => 'Dieser Link ist abgelaufen oder ungültig.'];
        }

        $account = Player::account($uid);
        if ($account === null) {
            return ['ok' => false, 'error' => 'Das zugehörige Konto gibt es nicht mehr.'];
        }

        $problems = Password::problems($password, (string) $account['username'], (string) $account['email']);
        if ($problems !== []) {
            return ['ok' => false, 'error' => $problems[0]];
        }

        Player::updateAccount($uid, static function (array $current) use ($password): array {
            $current['password'] = Password::hash($password);
            $current['remember'] = [];

            return $current;
        });

        App::store()->delete('resets/' . hash('sha256', $token) . '.json');
        Audit::log('account.reset_done', 'Passwort neu gesetzt', [], $uid);

        return ['ok' => true];
    }

    /** Passwort im angemeldeten Zustand ändern. */
    public static function changePassword(string $uid, string $oldPassword, string $newPassword): array
    {
        $account = Player::account($uid);
        if ($account === null) {
            return ['ok' => false, 'error' => 'Konto nicht gefunden.'];
        }
        if (!Password::verify($oldPassword, (string) $account['password'])) {
            return ['ok' => false, 'error' => 'Das bisherige Passwort stimmt nicht.'];
        }

        $problems = Password::problems($newPassword, (string) $account['username'], (string) $account['email']);
        if ($problems !== []) {
            return ['ok' => false, 'error' => $problems[0]];
        }

        Player::updateAccount($uid, static function (array $current) use ($newPassword): array {
            $current['password'] = Password::hash($newPassword);
            $current['remember'] = [];

            return $current;
        });

        Audit::log('account.password_changed', 'Passwort geändert', [], $uid);

        return ['ok' => true];
    }
}
