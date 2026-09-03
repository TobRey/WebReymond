<?php
/**
 * Grundlage des Bearbeitungsbereichs.
 *
 * Enthaelt: Sitzungsverwaltung, Schutzkoepfe, Anmeldung mit Argon2id,
 * Sperre nach Fehlversuchen und Schutz gegen fremde Formulare (CSRF).
 */

require_once __DIR__ . '/../../lib/store.php';

define('RT_USERS',     RT_PRIVATE . '/users.php');
define('RT_LOGINLOG',  RT_PRIVATE . '/logins.php');
define('RT_SESSION_IDLE', 2700);   // 45 Minuten ohne Aktivitaet
define('RT_SESSION_MAX',  43200);  // 12 Stunden insgesamt
define('RT_ADMIN_USER',   'reymondtobias');

/* ------------------------------------------------------------------
   Verbindung: verschluesselt oder nicht?
   ------------------------------------------------------------------ */
function rt_is_https()
{
    if (!empty($_SERVER['HTTPS']) && strtolower($_SERVER['HTTPS']) !== 'off') { return true; }
    if (isset($_SERVER['SERVER_PORT']) && (int) $_SERVER['SERVER_PORT'] === 443) { return true; }
    if (isset($_SERVER['HTTP_X_FORWARDED_PROTO']) && $_SERVER['HTTP_X_FORWARDED_PROTO'] === 'https') { return true; }
    return false;
}

/* ------------------------------------------------------------------
   Sitzung
   ------------------------------------------------------------------ */
if (session_status() !== PHP_SESSION_ACTIVE) {
    @ini_set('session.use_strict_mode', '1');
    @ini_set('session.use_only_cookies', '1');
    @ini_set('session.cookie_httponly', '1');
    @ini_set('session.sid_length', '48');
    @ini_set('session.gc_maxlifetime', (string) RT_SESSION_MAX);

    $params = array(
        'lifetime' => 0,
        'path'     => rtrim(dirname($_SERVER['SCRIPT_NAME'] ?? '/admin/index.php'), '/') . '/',
        'domain'   => '',
        'secure'   => rt_is_https(),
        'httponly' => true,
        'samesite' => 'Strict',
    );
    if (PHP_VERSION_ID >= 70300) {
        session_set_cookie_params($params);
    } else {
        session_set_cookie_params(
            $params['lifetime'],
            $params['path'] . '; samesite=Strict',
            $params['domain'],
            $params['secure'],
            $params['httponly']
        );
    }
    session_name('rtadmin');
    session_start();
}

/* ------------------------------------------------------------------
   Schutzkoepfe
   ------------------------------------------------------------------ */
$GLOBALS['rt_nonce'] = base64_encode(random_bytes(16));

header('X-Content-Type-Options: nosniff');
header('X-Frame-Options: DENY');
header('Referrer-Policy: no-referrer');
header('Permissions-Policy: geolocation=(), camera=(), microphone=(), interest-cohort=()');
header('Cache-Control: no-store, no-cache, must-revalidate, private');
header('Pragma: no-cache');
header(
    "Content-Security-Policy: default-src 'none'; " .
    "base-uri 'none'; " .
    "form-action 'self'; " .
    "frame-ancestors 'none'; " .
    "img-src 'self' data:; " .
    "style-src 'self' 'unsafe-inline'; " .
    "script-src 'self' 'nonce-" . $GLOBALS['rt_nonce'] . "'; " .
    "connect-src 'self'"
);
if (rt_is_https()) {
    header('Strict-Transport-Security: max-age=15768000');
}

/* ------------------------------------------------------------------
   Benutzerdatei
   ------------------------------------------------------------------ */
function rt_user_load()
{
    return rt_read(RT_USERS, array());
}

function rt_user_exists()
{
    $u = rt_user_load();
    return !empty($u['hash']) && !empty($u['user']);
}

/** Argon2id verfuegbar? */
function rt_argon2id_available()
{
    return defined('PASSWORD_ARGON2ID');
}

/** Passwort verschluesseln – bevorzugt Argon2id. */
function rt_hash_password($password)
{
    if (rt_argon2id_available()) {
        return password_hash($password, PASSWORD_ARGON2ID, array(
            'memory_cost' => 65536,   // 64 MB
            'time_cost'   => 4,
            'threads'     => 2,
        ));
    }
    // Nur als Notnagel, falls die Erweiterung fehlt – siehe /doc.html.
    return password_hash($password, PASSWORD_DEFAULT);
}

/* ------------------------------------------------------------------
   Sperre nach Fehlversuchen (ohne IP-Adressen)
   ------------------------------------------------------------------ */
function rt_lock_remaining($user)
{
    $log = rt_read(RT_LOGINLOG, array());
    $key = rt_lower($user);
    if (empty($log[$key]['until'])) { return 0; }
    $left = (int) $log[$key]['until'] - time();
    return $left > 0 ? $left : 0;
}

function rt_note_failure($user)
{
    $key = rt_lower($user);
    rt_update_locked(RT_LOGINLOG, function ($log) use ($key) {
        $entry = isset($log[$key]) && is_array($log[$key]) ? $log[$key] : array('fails' => 0, 'until' => 0);
        $entry['fails'] = (int) $entry['fails'] + 1;
        $entry['last']  = time();

        $wait = 0;
        if ($entry['fails'] >= 12)      { $wait = 1800; }
        elseif ($entry['fails'] >= 8)   { $wait = 300; }
        elseif ($entry['fails'] >= 5)   { $wait = 60; }
        $entry['until'] = $wait > 0 ? time() + $wait : 0;

        $log[$key] = $entry;
        return $log;
    });
}

function rt_clear_failures($user)
{
    $key = rt_lower($user);
    rt_update_locked(RT_LOGINLOG, function ($log) use ($key) {
        unset($log[$key]);
        return $log;
    });
}

/* ------------------------------------------------------------------
   Anmeldung
   ------------------------------------------------------------------ */
function rt_ua_fingerprint()
{
    return substr(hash('sha256', ($_SERVER['HTTP_USER_AGENT'] ?? '') . '|rt-admin'), 0, 32);
}

/**
 * Anmelden. Gibt true zurueck, wenn es geklappt hat.
 */
function rt_login($user, $password)
{
    $account = rt_user_load();
    if (empty($account['hash'])) { return false; }

    $userOk = hash_equals((string) $account['user'], (string) $user);
    $passOk = password_verify((string) $password, (string) $account['hash']);

    if (!$userOk || !$passOk) { return false; }

    /* Verfahren veraltet? Dann beim naechsten erfolgreichen Login erneuern. */
    if (rt_argon2id_available() && password_needs_rehash($account['hash'], PASSWORD_ARGON2ID, array(
        'memory_cost' => 65536, 'time_cost' => 4, 'threads' => 2,
    ))) {
        $account['hash'] = rt_hash_password($password);
        $account['rehashed'] = date('c');
        rt_backup(RT_USERS, 'users');
        rt_write(RT_USERS, $account);
    }

    session_regenerate_id(true);
    $_SESSION['auth']     = true;
    $_SESSION['user']     = $account['user'];
    $_SESSION['ua']       = rt_ua_fingerprint();
    $_SESSION['start']    = time();
    $_SESSION['last']     = time();
    $_SESSION['csrf']     = rt_token(32);

    /* Schluessel fuer den Passwortspeicher aus dem Passwort ableiten. */
    require_once __DIR__ . '/crypto.php';
    $salt = isset($account['vault_salt']) ? (string) $account['vault_salt'] : '';
    $kdf  = isset($account['vault_kdf'])  ? (string) $account['vault_kdf']  : '';
    if ($salt === '' || $kdf === '') {
        if ($salt === '') { $salt = bin2hex(random_bytes(16)); }
        if ($kdf === '')  { $kdf  = rt_kdf_name(); }
        $account['vault_salt'] = $salt;
        $account['vault_kdf']  = $kdf;
        rt_write(RT_USERS, $account);
    }
    $_SESSION['vk'] = base64_encode(rt_derive_key($password, $salt, $kdf));

    return true;
}

function rt_logout()
{
    $_SESSION = array();
    if (ini_get('session.use_cookies')) {
        $p = session_get_cookie_params();
        setcookie(session_name(), '', time() - 42000, $p['path'], $p['domain'], $p['secure'], $p['httponly']);
    }
    session_destroy();
}

/** Ist jemand angemeldet – und ist die Sitzung noch gueltig? */
function rt_is_logged_in()
{
    if (empty($_SESSION['auth'])) { return false; }
    if (($_SESSION['ua'] ?? '') !== rt_ua_fingerprint()) { rt_logout(); return false; }
    if (time() - (int) ($_SESSION['last'] ?? 0) > RT_SESSION_IDLE) { rt_logout(); return false; }
    if (time() - (int) ($_SESSION['start'] ?? 0) > RT_SESSION_MAX) { rt_logout(); return false; }
    $_SESSION['last'] = time();
    return true;
}

/** Zugang erzwingen. Ohne Anmeldung geht es zur Anmeldeseite. */
function rt_require_login()
{
    if (!rt_user_exists()) {
        header('Location: setup.php');
        exit;
    }
    if (!rt_is_logged_in()) {
        $next = basename($_SERVER['SCRIPT_NAME'] ?? 'index.php');
        header('Location: login.php?next=' . urlencode($next));
        exit;
    }
}

/* ------------------------------------------------------------------
   Schutz gegen fremde Formulare
   ------------------------------------------------------------------ */
function rt_csrf()
{
    if (empty($_SESSION['csrf'])) { $_SESSION['csrf'] = rt_token(32); }
    return $_SESSION['csrf'];
}

function rt_csrf_field()
{
    return '<input type="hidden" name="_token" value="' . rt_h(rt_csrf()) . '">';
}

function rt_csrf_ok()
{
    $sent = isset($_POST['_token']) ? (string) $_POST['_token'] : '';
    return $sent !== '' && !empty($_SESSION['csrf']) && hash_equals($_SESSION['csrf'], $sent);
}

/** Formularpruefung fuer jede schreibende Seite. */
function rt_require_post_token()
{
    if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') { return false; }
    if (!rt_csrf_ok()) {
        http_response_code(400);
        exit('Sitzung abgelaufen. Bitte lade die Seite neu und versuch es noch einmal.');
    }
    return true;
}

/* ------------------------------------------------------------------
   Kurznachrichten zwischen zwei Seitenaufrufen
   ------------------------------------------------------------------ */
function rt_flash($kind = null, $text = null)
{
    if ($kind === null) {
        $msg = isset($_SESSION['flash']) ? $_SESSION['flash'] : null;
        unset($_SESSION['flash']);
        return $msg;
    }
    $_SESSION['flash'] = array('kind' => $kind, 'text' => $text);
    return null;
}

function rt_redirect($to)
{
    header('Location: ' . $to);
    exit;
}
