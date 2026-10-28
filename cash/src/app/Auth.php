<?php
declare(strict_types=1);

if (!defined('CASH_ENTRY')) {
    http_response_code(404);
    exit;
}

/**
 * Anmeldung, Sessions, "Angemeldet bleiben"-Tokens, CSRF und Login-Limitierung.
 */
final class Auth
{
    public const SESSION_IDLE = 7200;            // 2 h Inaktivität
    public const SESSION_MAX = 86400;            // max. 24 h pro Session
    public const REMEMBER_DAYS = 30;             // Token-Gültigkeit (gleitend)
    public const REMEMBER_MAX_DAYS = 180;        // absolute Obergrenze
    public const ROTATE_GRACE = 120;             // Sekunden, in denen der Vorgänger-Token gilt
    public const COOKIE_REM = 'CASHREM';
    public const MAX_FAILS_USER = 5;
    public const MAX_FAILS_IP = 20;
    public const FAIL_WINDOW = 900;              // 15 min

    private static ?array $currentUser = null;

    private static function usersFile(): string { return cash_path('users.json.php'); }
    private static function tokensFile(): string { return cash_path('tokens.json.php'); }
    private static function rateFile(): string { return cash_path('ratelimit.json.php'); }

    public static function startSession(): void
    {
        if (session_status() === PHP_SESSION_ACTIVE) {
            return;
        }
        ini_set('session.use_strict_mode', '1');
        ini_set('session.use_only_cookies', '1');
        ini_set('session.use_trans_sid', '0');
        ini_set('session.cookie_httponly', '1');
        ini_set('session.gc_maxlifetime', (string)self::SESSION_MAX);
        ini_set('session.gc_probability', '1');
        ini_set('session.gc_divisor', '100');
        session_save_path(cash_path('sessions'));
        session_name('CASHSID');
        session_set_cookie_params([
            'lifetime' => 0,
            'path' => Http::basePath(),
            'secure' => Http::isHttps(),
            'httponly' => true,
            'samesite' => 'Lax',
        ]);
        session_start();
        if (empty($_SESSION['csrf'])) {
            $_SESSION['csrf'] = bin2hex(random_bytes(32));
        }
    }

    public static function csrf(): string
    {
        return (string)($_SESSION['csrf'] ?? '');
    }

    public static function checkCsrf(): void
    {
        $sent = (string)($_SERVER['HTTP_X_CSRF_TOKEN'] ?? '');
        $own = self::csrf();
        if ($own === '' || $sent === '' || !hash_equals($own, $sent)) {
            throw new ApiError('Sicherheitstoken ungültig. Bitte Seite neu laden.', 419);
        }
    }

    // ---------------------------------------------------------------- Benutzer

    public static function usersData(): array
    {
        return Store::read(self::usersFile(), ['users' => []]);
    }

    public static function hasUsers(): bool
    {
        return count(self::usersData()['users']) > 0;
    }

    public static function findUser(string $id): ?array
    {
        foreach (self::usersData()['users'] as $u) {
            if ($u['id'] === $id) {
                return $u;
            }
        }
        return null;
    }

    public static function registrationOpen(): bool
    {
        return (bool)(cash_config()['registration_open'] ?? false);
    }

    public static function publicUser(array $u): array
    {
        return [
            'id' => $u['id'],
            'username' => $u['username'],
            'name' => $u['name'],
            'email' => $u['email'] ?? '',
            'role' => $u['role'],
            'onboarded' => (bool)($u['onboarded'] ?? false),
        ];
    }

    public static function validateUsername(mixed $v): string
    {
        $v = is_string($v) ? trim($v) : '';
        if (!preg_match('/^[A-Za-z0-9._@\-]{3,60}$/', $v)) {
            throw new ApiError('Benutzername: 3–60 Zeichen (Buchstaben, Zahlen, . _ - @).');
        }
        return $v;
    }

    public static function validateName(mixed $v): string
    {
        $v = is_string($v) ? trim(preg_replace('/\s+/u', ' ', $v)) : '';
        if ($v === '' || mb_strlen($v) > 80) {
            throw new ApiError('Bitte einen Namen mit höchstens 80 Zeichen eingeben.');
        }
        return $v;
    }

    public static function validateEmail(mixed $v): string
    {
        $v = is_string($v) ? trim($v) : '';
        if ($v !== '' && (!filter_var($v, FILTER_VALIDATE_EMAIL) || strlen($v) > 190)) {
            throw new ApiError('Bitte eine gültige E-Mail-Adresse eingeben.');
        }
        return $v;
    }

    public static function validatePassword(mixed $v): string
    {
        if (!is_string($v) || mb_strlen($v) < 10) {
            throw new ApiError('Das Passwort muss mindestens 10 Zeichen lang sein.');
        }
        if (str_contains($v, "\0")) {
            throw new ApiError('Das Passwort enthält ungültige Zeichen.');
        }
        if (strlen($v) > 72) {
            throw new ApiError('Das Passwort darf höchstens 72 Bytes lang sein.');
        }
        return $v;
    }

    /** Legt einen Benutzer an. $setup = true nur für den allerersten Account. */
    public static function createUser(array $in, bool $setup): array
    {
        $username = self::validateUsername($in['username'] ?? '');
        $name = self::validateName($in['name'] ?? '');
        $email = self::validateEmail($in['email'] ?? '');
        $password = self::validatePassword($in['password'] ?? '');
        $hash = password_hash($password, PASSWORD_DEFAULT);

        $user = Store::mutate(self::usersFile(), function (array &$d) use ($username, $name, $email, $hash, $setup) {
            $d['users'] ??= [];
            if ($setup && count($d['users']) > 0) {
                throw new ApiError('CASH ist bereits eingerichtet.', 409);
            }
            if (!$setup && count($d['users']) === 0) {
                throw new ApiError('Bitte zuerst den Hauptaccount einrichten.', 409);
            }
            foreach ($d['users'] as $u) {
                if (strcasecmp($u['username'], $username) === 0) {
                    throw new ApiError('Dieser Benutzername ist bereits vergeben.', 409);
                }
            }
            $u = [
                'id' => cash_new_id(),
                'username' => $username,
                'name' => $name,
                'email' => $email,
                'hash' => $hash,
                'role' => $setup ? 'owner' : 'user',
                'epoch' => 1,
                'onboarded' => false,
                'created' => date('c'),
            ];
            $d['users'][] = $u;
            return $u;
        }, ['users' => []]);

        if ($setup) {
            Store::mutate(cash_path('config.json.php'), function (array &$c) {
                $c['registration_open'] = false;   // nach Einrichtung standardmässig geschlossen
                $c['installed'] = date('c');
                $c['version'] = CASH_VERSION;
            });
        }
        Finance::initUser($user['id']);
        return $user;
    }

    public static function updateUser(string $id, callable $fn): array
    {
        return Store::mutate(self::usersFile(), function (array &$d) use ($id, $fn) {
            foreach ($d['users'] as &$u) {
                if ($u['id'] === $id) {
                    $fn($u, $d['users']);
                    return $u;
                }
            }
            throw new ApiError('Benutzer nicht gefunden.', 404);
        }, ['users' => []]);
    }

    // ------------------------------------------------------------ Aktueller Benutzer

    /** Liefert den angemeldeten Benutzer (Session oder gültiges Token) oder null. */
    public static function current(): ?array
    {
        if (self::$currentUser !== null) {
            return self::$currentUser;
        }
        $now = time();
        $uid = $_SESSION['uid'] ?? null;
        if (is_string($uid)) {
            $idle = $now - (int)($_SESSION['last'] ?? 0);
            $age = $now - (int)($_SESSION['started'] ?? 0);
            $user = self::findUser($uid);
            if ($user && $idle <= self::SESSION_IDLE && $age <= self::SESSION_MAX
                && (int)($_SESSION['epoch'] ?? 0) === (int)($user['epoch'] ?? 1)) {
                $_SESSION['last'] = $now;
                return self::$currentUser = $user;
            }
            self::clearSessionLogin();
        }
        $user = self::tryRemember();
        if ($user) {
            return self::$currentUser = $user;
        }
        return null;
    }

    public static function requireUser(): array
    {
        $u = self::current();
        if (!$u) {
            throw new ApiError('Bitte anmelden.', 401);
        }
        return $u;
    }

    private static function clearSessionLogin(): void
    {
        unset($_SESSION['uid'], $_SESSION['last'], $_SESSION['started'], $_SESSION['epoch'], $_SESSION['rsel']);
        self::$currentUser = null;
    }

    private static function establish(array $user, ?string $selector): void
    {
        session_regenerate_id(true);
        $_SESSION['uid'] = $user['id'];
        $_SESSION['started'] = time();
        $_SESSION['last'] = time();
        $_SESSION['epoch'] = (int)($user['epoch'] ?? 1);
        $_SESSION['csrf'] = bin2hex(random_bytes(32));
        if ($selector !== null) {
            $_SESSION['rsel'] = $selector;
        } else {
            unset($_SESSION['rsel']);
        }
        self::$currentUser = $user;
    }

    // ------------------------------------------------------------------ Login

    public static function login(array $in): array
    {
        $username = is_string($in['username'] ?? null) ? trim($in['username']) : '';
        $password = is_string($in['password'] ?? null) ? $in['password'] : '';
        $remember = !empty($in['remember']);
        if ($username === '' || $password === '') {
            throw new ApiError('Bitte Benutzername und Passwort eingeben.');
        }
        $ip = Http::clientIp();
        self::rateCheck($ip, $username);

        $found = null;
        foreach (self::usersData()['users'] as $u) {
            if (strcasecmp($u['username'], $username) === 0
                || (($u['email'] ?? '') !== '' && strcasecmp($u['email'], $username) === 0)) {
                $found = $u;
                break;
            }
        }
        // Gleicher Rechenaufwand, auch wenn der Benutzer nicht existiert.
        $hash = $found['hash'] ?? password_hash(bin2hex(random_bytes(12)), PASSWORD_DEFAULT);
        $ok = password_verify($password, $hash) && $found !== null;
        if (!$ok) {
            self::rateFail($ip, $username);
            throw new ApiError('Benutzername oder Passwort ist falsch.', 401);
        }
        self::rateClear($username);
        if (password_needs_rehash($found['hash'], PASSWORD_DEFAULT)) {
            $newHash = password_hash($password, PASSWORD_DEFAULT);
            self::updateUser($found['id'], function (array &$u) use ($newHash) { $u['hash'] = $newHash; });
        }
        $selector = $remember ? self::issueRemember($found['id']) : null;
        self::establish($found, $selector);
        return $found;
    }

    /** Prüft Benutzername + Passwort (rate-limitiert), ohne anzumelden. */
    public static function checkCredentials(string $username, string $password): array
    {
        $username = trim($username);
        if ($username === '' || $password === '') {
            throw new ApiError('Bitte Benutzername und Passwort eingeben.');
        }
        $ip = Http::clientIp();
        self::rateCheck($ip, $username);
        $found = null;
        foreach (self::usersData()['users'] as $u) {
            if (strcasecmp($u['username'], $username) === 0) {
                $found = $u;
                break;
            }
        }
        $hash = $found['hash'] ?? password_hash(bin2hex(random_bytes(12)), PASSWORD_DEFAULT);
        if (!password_verify($password, $hash) || $found === null) {
            self::rateFail($ip, $username);
            throw new ApiError('Benutzername oder Passwort ist falsch.', 401);
        }
        self::rateClear($username);
        return $found;
    }

    public static function logout(): void
    {
        $sel = $_SESSION['rsel'] ?? null;
        $cookie = self::parseRememberCookie();
        $selectors = array_filter([$sel, $cookie[0] ?? null]);
        if ($selectors) {
            Store::mutate(self::tokensFile(), function (array &$d) use ($selectors) {
                $d['tokens'] = array_values(array_filter($d['tokens'] ?? [], fn($t) => !in_array($t['selector'], $selectors, true)));
            }, ['tokens' => []]);
        }
        self::clearRememberCookie();
        $_SESSION = [];
        if (session_status() === PHP_SESSION_ACTIVE) {
            session_regenerate_id(true);
        }
        $_SESSION['csrf'] = bin2hex(random_bytes(32));
        self::$currentUser = null;
    }

    // ----------------------------------------------------- Angemeldet bleiben

    private static function setRememberCookie(string $value, int $expires): void
    {
        setcookie(self::COOKIE_REM, $value, [
            'expires' => $expires,
            'path' => Http::basePath(),
            'secure' => Http::isHttps(),
            'httponly' => true,
            'samesite' => 'Lax',
        ]);
    }

    private static function clearRememberCookie(): void
    {
        if (isset($_COOKIE[self::COOKIE_REM]) || !headers_sent()) {
            self::setRememberCookie('', time() - 3600);
        }
    }

    private static function parseRememberCookie(): ?array
    {
        $c = $_COOKIE[self::COOKIE_REM] ?? '';
        if (!is_string($c) || !preg_match('/^([a-f0-9]{24}):([a-f0-9]{64})$/', $c, $m)) {
            return null;
        }
        return [$m[1], $m[2]];
    }

    private static function deviceLabel(): string
    {
        $ua = (string)($_SERVER['HTTP_USER_AGENT'] ?? '');
        $os = match (true) {
            str_contains($ua, 'iPhone') => 'iPhone',
            str_contains($ua, 'iPad') => 'iPad',
            str_contains($ua, 'Android') => 'Android',
            str_contains($ua, 'Mac OS') => 'Mac',
            str_contains($ua, 'Windows') => 'Windows',
            str_contains($ua, 'Linux') => 'Linux',
            default => 'Gerät',
        };
        $br = match (true) {
            str_contains($ua, 'Edg/') => 'Edge',
            str_contains($ua, 'Firefox') => 'Firefox',
            str_contains($ua, 'Chrome') || str_contains($ua, 'CriOS') => 'Chrome',
            str_contains($ua, 'Safari') => 'Safari',
            default => 'Browser',
        };
        return $br . ' auf ' . $os;
    }

    private static function issueRemember(string $userId): string
    {
        $selector = bin2hex(random_bytes(12));
        $validator = bin2hex(random_bytes(32));
        $now = time();
        $expires = $now + self::REMEMBER_DAYS * 86400;
        $token = [
            'id' => cash_new_id(),
            'selector' => $selector,
            'hash' => hash('sha256', $validator),
            'prevHash' => null,
            'rotatedAt' => $now,
            'userId' => $userId,
            'created' => $now,
            'lastUsed' => $now,
            'expires' => $expires,
            'maxExpires' => $now + self::REMEMBER_MAX_DAYS * 86400,
            'device' => self::deviceLabel(),
        ];
        Store::mutate(self::tokensFile(), function (array &$d) use ($token, $now) {
            $d['tokens'] = array_values(array_filter($d['tokens'] ?? [], fn($t) => $t['expires'] > $now));
            $d['tokens'][] = $token;
        }, ['tokens' => []]);
        self::setRememberCookie($selector . ':' . $validator, $expires);
        return $selector;
    }

    private static function tryRemember(): ?array
    {
        $parsed = self::parseRememberCookie();
        if (!$parsed) {
            if (isset($_COOKIE[self::COOKIE_REM])) {
                self::clearRememberCookie();
            }
            return null;
        }
        [$selector, $validator] = $parsed;
        $now = time();
        $hash = hash('sha256', $validator);
        $result = Store::mutate(self::tokensFile(), function (array &$d) use ($selector, $hash, $now) {
            $d['tokens'] = array_values(array_filter($d['tokens'] ?? [], fn($t) => $t['expires'] > $now));
            foreach ($d['tokens'] as $i => &$t) {
                if (!hash_equals($t['selector'], $selector)) {
                    continue;
                }
                if (hash_equals($t['hash'], $hash)) {
                    $newValidator = bin2hex(random_bytes(32));
                    $t['prevHash'] = $t['hash'];
                    $t['hash'] = hash('sha256', $newValidator);
                    $t['rotatedAt'] = $now;
                    $t['lastUsed'] = $now;
                    $t['expires'] = min($now + self::REMEMBER_DAYS * 86400, $t['maxExpires']);
                    return ['userId' => $t['userId'], 'cookie' => $selector . ':' . $newValidator, 'expires' => $t['expires']];
                }
                if ($t['prevHash'] !== null && hash_equals($t['prevHash'], $hash) && $now - $t['rotatedAt'] <= self::ROTATE_GRACE) {
                    // Parallele Anfrage während der Rotation – gültig, Cookie nicht neu setzen.
                    return ['userId' => $t['userId'], 'cookie' => null, 'expires' => $t['expires']];
                }
                // Selektor bekannt, Prüfwert falsch: möglicher Diebstahl → alle Tokens des Benutzers widerrufen.
                $uid = $t['userId'];
                unset($t);
                $d['tokens'] = array_values(array_filter($d['tokens'], fn($x) => $x['userId'] !== $uid));
                return ['theft' => true];
            }
            return null;
        }, ['tokens' => []]);

        if (!$result || !empty($result['theft'])) {
            self::clearRememberCookie();
            return null;
        }
        $user = self::findUser($result['userId']);
        if (!$user) {
            self::clearRememberCookie();
            return null;
        }
        if ($result['cookie'] !== null) {
            self::setRememberCookie($result['cookie'], $result['expires']);
        }
        self::establish($user, $selector);
        return $user;
    }

    public static function listTokens(string $userId): array
    {
        $cur = $_SESSION['rsel'] ?? null;
        $now = time();
        $d = Store::read(self::tokensFile(), ['tokens' => []]);
        $out = [];
        foreach ($d['tokens'] ?? [] as $t) {
            if ($t['userId'] !== $userId || $t['expires'] <= $now) {
                continue;
            }
            $out[] = [
                'id' => $t['id'],
                'device' => $t['device'],
                'created' => date('c', $t['created']),
                'lastUsed' => date('c', $t['lastUsed']),
                'expires' => date('c', $t['expires']),
                'current' => $cur !== null && hash_equals($t['selector'], $cur),
            ];
        }
        return $out;
    }

    public static function revokeToken(string $userId, string $tokenId): void
    {
        Store::mutate(self::tokensFile(), function (array &$d) use ($userId, $tokenId) {
            $d['tokens'] = array_values(array_filter($d['tokens'] ?? [], fn($t) => !($t['userId'] === $userId && $t['id'] === $tokenId)));
        }, ['tokens' => []]);
    }

    /** Meldet alle anderen Geräte ab (Tokens + andere Sessions über Epoch). */
    public static function revokeOthers(string $userId): void
    {
        $cur = $_SESSION['rsel'] ?? null;
        Store::mutate(self::tokensFile(), function (array &$d) use ($userId, $cur) {
            $d['tokens'] = array_values(array_filter($d['tokens'] ?? [], fn($t) => $t['userId'] !== $userId || ($cur !== null && $t['selector'] === $cur)));
        }, ['tokens' => []]);
        $user = self::updateUser($userId, function (array &$u) { $u['epoch'] = (int)($u['epoch'] ?? 1) + 1; });
        $_SESSION['epoch'] = $user['epoch'];
        self::$currentUser = $user;
    }

    public static function changePassword(string $userId, array $in): void
    {
        $current = is_string($in['current'] ?? null) ? $in['current'] : '';
        $new = self::validatePassword($in['password'] ?? '');
        $user = self::findUser($userId);
        if (!$user || !password_verify($current, $user['hash'])) {
            self::rateFail(Http::clientIp(), $user['username'] ?? 'x');
            throw new ApiError('Das aktuelle Passwort ist falsch.', 403);
        }
        $hash = password_hash($new, PASSWORD_DEFAULT);
        self::updateUser($userId, function (array &$u) use ($hash) { $u['hash'] = $hash; });
        self::revokeOthers($userId);
    }

    public static function updateProfile(string $userId, array $in): array
    {
        $name = self::validateName($in['name'] ?? '');
        $username = self::validateUsername($in['username'] ?? '');
        $email = self::validateEmail($in['email'] ?? '');
        return self::updateUser($userId, function (array &$u, array $all) use ($name, $username, $email, $userId) {
            foreach ($all as $o) {
                if ($o['id'] !== $userId && strcasecmp($o['username'], $username) === 0) {
                    throw new ApiError('Dieser Benutzername ist bereits vergeben.', 409);
                }
            }
            $u['name'] = $name;
            $u['username'] = $username;
            $u['email'] = $email;
        });
    }

    public static function setOnboarded(string $userId): void
    {
        self::updateUser($userId, function (array &$u) { $u['onboarded'] = true; });
    }

    public static function setRegistration(bool $open): void
    {
        Store::mutate(cash_path('config.json.php'), function (array &$c) use ($open) {
            $c['registration_open'] = $open;
        });
    }

    // ---------------------------------------------------------- Login-Limitierung

    private static function rateKeys(string $ip, string $username): array
    {
        return ['ip:' . $ip, 'user:' . mb_strtolower(trim($username))];
    }

    private static function rateCheck(string $ip, string $username): void
    {
        $now = time();
        $d = Store::read(self::rateFile(), ['entries' => []]);
        [$kIp, $kUser] = self::rateKeys($ip, $username);
        $fails = fn($k) => array_values(array_filter($d['entries'][$k] ?? [], fn($t) => $t > $now - self::FAIL_WINDOW));
        $u = $fails($kUser);
        $i = $fails($kIp);
        if (count($u) >= self::MAX_FAILS_USER || count($i) >= self::MAX_FAILS_IP) {
            $oldest = min(count($u) >= self::MAX_FAILS_USER ? $u : $i);
            $wait = max(1, (int)ceil(($oldest + self::FAIL_WINDOW - $now) / 60));
            throw new ApiError("Zu viele Fehlversuche. Bitte in {$wait} Minuten erneut versuchen.", 429);
        }
    }

    private static function rateFail(string $ip, string $username): void
    {
        $now = time();
        Store::mutate(self::rateFile(), function (array &$d) use ($ip, $username, $now) {
            $d['entries'] ??= [];
            foreach (self::rateKeys($ip, $username) as $k) {
                $d['entries'][$k][] = $now;
            }
            foreach ($d['entries'] as $k => $list) {
                $list = array_values(array_filter($list, fn($t) => $t > $now - self::FAIL_WINDOW));
                if ($list) {
                    $d['entries'][$k] = array_slice($list, -50);
                } else {
                    unset($d['entries'][$k]);
                }
            }
        }, ['entries' => []]);
    }

    private static function rateClear(string $username): void
    {
        Store::mutate(self::rateFile(), function (array &$d) use ($username) {
            unset($d['entries']['user:' . mb_strtolower(trim($username))]);
        }, ['entries' => []]);
    }
}
