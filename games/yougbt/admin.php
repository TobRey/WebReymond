<?php
// YouGBT – Ersteinrichtung und passwortgeschützter Adminbereich.
declare(strict_types=1);
require __DIR__ . '/lib/bootstrap.php';
require __DIR__ . '/lib/claude.php';

ini_set('display_errors', '0');
yg_security_headers();
header('Content-Type: text/html; charset=utf-8');
header('Cache-Control: no-store');

// Anmeldung ohne PHP-Sitzungen (manche Shared-Hostings verlieren Sitzungen oder cachen Seiten):
// signiertes Cookie + davon abgeleitetes CSRF-Token + Herkunftsprüfung (Origin/Referer).
$https = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
    || strtolower((string) ($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '')) === 'https'
    || (string) ($_SERVER['SERVER_PORT'] ?? '') === '443';
header('Vary: Cookie');

function yg_admin_secret(): string
{
    $s = (string) yg_cfg('secret');
    if ($s === '' && yg_is_configured()) {
        $s = yg_rand_hex(32);
        yg_save_config(['secret' => $s]);
    }
    return $s;
}

function yg_admin_sig(string $exp, string $adminHash): string
{
    return hash_hmac('sha256', 'admin|' . $exp . '|' . substr($adminHash, -24), yg_admin_secret());
}

function yg_admin_set_cookie(string $value, int $expires): void
{
    global $https;
    setcookie('yougbt_admin', $value, [
        'expires' => $expires, 'path' => yg_base_path(), 'secure' => $https, 'httponly' => true, 'samesite' => 'Lax',
    ]);
}

function yg_admin_login_cookie(string $adminHash): void
{
    $exp = (string) (time() + 8 * 3600);
    yg_admin_set_cookie($exp . '.' . yg_admin_sig($exp, $adminHash), (int) $exp);
}

function yg_admin_cookie_valid(): bool
{
    $c = (string) ($_COOKIE['yougbt_admin'] ?? '');
    if (!preg_match('/^(\d{10})\.([a-f0-9]{64})$/', $c, $m) || (int) $m[1] < time()) {
        return false;
    }
    return hash_equals(yg_admin_sig($m[1], (string) yg_cfg('admin_hash')), $m[2]);
}

/** CSRF-Token: an das Anmelde-Cookie gebunden; vor der Anmeldung leer (dort schützen Einrichtungscode/Passwort + Herkunftsprüfung). */
function yg_admin_csrf(): string
{
    $c = (string) ($_COOKIE['yougbt_admin'] ?? '');
    return $c === '' || !yg_is_configured() ? 'none' : hash_hmac('sha256', 'csrf|' . $c, yg_admin_secret());
}

/** Anfrage muss von derselben Website kommen (falls der Browser Origin/Referer mitsendet). */
function yg_admin_same_origin(): bool
{
    $host = strtolower((string) ($_SERVER['HTTP_HOST'] ?? ''));
    foreach (['HTTP_ORIGIN', 'HTTP_REFERER'] as $h) {
        $v = (string) ($_SERVER[$h] ?? '');
        if ($v !== '' && $v !== 'null') {
            return strtolower((string) parse_url($v, PHP_URL_HOST)) . (parse_url($v, PHP_URL_PORT) ? ':' . parse_url($v, PHP_URL_PORT) : '') === $host;
        }
    }
    return true;
}

function h(?string $s): string
{
    return htmlspecialchars((string) $s, ENT_QUOTES, 'UTF-8');
}

function yg_admin_csrf_ok(): bool
{
    return is_string($_POST['csrf'] ?? null) && hash_equals(yg_admin_csrf(), $_POST['csrf']);
}

/** Einmaliger Einrichtungscode – nur über den cPanel-Dateimanager lesbar. */
function yg_setup_code(): string
{
    $file = YG_APP_DIR . '/data/setup-code.php';
    $data = yg_read_guarded($file);
    if (is_array($data) && !empty($data['code'])) {
        return (string) $data['code'];
    }
    $code = strtoupper(yg_rand_hex(4));
    // Lesbar im Dateimanager, per Web gesperrt (PHP-Sperrzeile + .htaccess)
    @file_put_contents($file, YG_GUARD . json_encode(['code' => $code, 'hinweis' => 'YouGBT-Einrichtungscode. Datei nach der Einrichtung automatisch gelöscht.']));
    return $code;
}

function yg_mask_key(string $k): string
{
    return $k === '' ? '—' : substr($k, 0, 7) . '…' . substr($k, -4);
}

$msg = '';
$err = '';
$configured = yg_is_configured();
$loggedIn = $configured && yg_admin_cookie_valid();
$action = (string) ($_POST['do'] ?? '');

try {
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        if (!yg_admin_same_origin()) {
            throw new YgError('csrf');
        }
        // Formulare im angemeldeten Bereich zusätzlich mit CSRF-Token
        if ($loggedIn && !in_array($action, ['setup', 'login'], true) && !yg_admin_csrf_ok()) {
            throw new YgError('csrf');
        }
        if (!$configured && $action === 'setup') {
            yg_rate_limit('setup|' . yg_client_ip(), 10, 600);
            if (!hash_equals(yg_setup_code(), strtoupper(trim((string) ($_POST['setup_code'] ?? ''))))) {
                throw new YgError('setup_code_wrong');
            }
            $pw = (string) ($_POST['password'] ?? '');
            if (mb_strlen($pw) < 10 || $pw !== (string) ($_POST['password2'] ?? '')) {
                throw new YgError('password_rules');
            }
            $key = trim((string) ($_POST['api_key'] ?? ''));
            if (!preg_match('/^sk-ant-[A-Za-z0-9_\-]{20,200}$/', $key)) {
                throw new YgError('api_key_format');
            }
            $list = yg_list_models($key);
            // Speicher möglichst außerhalb des Webroots anlegen
            if (yg_try_external_storage()) {
                yg_data_dir(true);
            }
            $ids = array_column($list, 'id');
            $model = in_array(YG_DEFAULT_MODEL, $ids, true) ? YG_DEFAULT_MODEL : ($ids[0] ?? YG_DEFAULT_MODEL);
            $hash = password_hash($pw, PASSWORD_DEFAULT);
            $secret = yg_rand_hex(32);
            yg_save_config([
                'api_key' => $key,
                'admin_hash' => $hash,
                'model' => $model,
                'models' => $list,
                'secret' => $secret,
                'created' => time(),
            ]);
            @unlink(YG_APP_DIR . '/data/setup-code.php');
            $exp = (string) (time() + 8 * 3600);
            yg_admin_set_cookie($exp . '.' . hash_hmac('sha256', 'admin|' . $exp . '|' . substr($hash, -24), $secret), (int) $exp);
            header('Location: admin.php?ok=setup');
            exit;
        }
        if ($configured && $action === 'login') {
            yg_rate_limit('login|' . yg_client_ip(), 6, 600);
            if (!password_verify((string) ($_POST['password'] ?? ''), (string) yg_cfg('admin_hash'))) {
                throw new YgError('login_failed');
            }
            yg_admin_login_cookie((string) yg_cfg('admin_hash'));
            header('Location: admin.php');
            exit;
        }
        if ($loggedIn) {
            switch ($action) {
                case 'logout':
                    yg_admin_set_cookie('', time() - 3600);
                    header('Location: admin.php');
                    exit;
                case 'models':
                    yg_save_config(['models' => yg_list_models((string) yg_cfg('api_key'))]);
                    header('Location: admin.php?ok=models');
                    exit;
                case 'save':
                    $new = [];
                    $key = trim((string) ($_POST['api_key'] ?? ''));
                    if ($key !== '') {
                        if (!preg_match('/^sk-ant-[A-Za-z0-9_\-]{20,200}$/', $key)) {
                            throw new YgError('api_key_format');
                        }
                        $new['models'] = yg_list_models($key);
                        $new['api_key'] = $key;
                    }
                    $model = trim((string) ($_POST['model'] ?? ''));
                    if ($model !== '') {
                        if (!yg_valid_model_id($model)) {
                            throw new YgError('model_invalid');
                        }
                        $new['model'] = $model;
                    }
                    $new['daily_limit'] = max(0, min(100000, (int) ($_POST['daily_limit'] ?? 400)));
                    $new['max_games'] = max(1, min(200, (int) ($_POST['max_games'] ?? 10)));
                    yg_save_config($new);
                    header('Location: admin.php?ok=saved');
                    exit;
                case 'password':
                    $pw = (string) ($_POST['password'] ?? '');
                    if (!password_verify((string) ($_POST['old'] ?? ''), (string) yg_cfg('admin_hash'))) {
                        throw new YgError('login_failed');
                    }
                    if (mb_strlen($pw) < 10 || $pw !== (string) ($_POST['password2'] ?? '')) {
                        throw new YgError('password_rules');
                    }
                    $hash = password_hash($pw, PASSWORD_DEFAULT);
                    yg_save_config(['admin_hash' => $hash]);
                    yg_admin_login_cookie($hash);
                    header('Location: admin.php?ok=password');
                    exit;
            }
        }
        throw new YgError('forbidden');
    }
} catch (YgError $e) {
    $err = [
        'csrf' => 'Sitzung abgelaufen oder Anfrage kam nicht von dieser Seite. Bitte Seite neu laden und erneut versuchen.',
        'setup_code_wrong' => 'Der Einrichtungscode stimmt nicht. Du findest ihn im cPanel-Dateimanager in yougbt/data/setup-code.php.',
        'password_rules' => 'Passwort: mindestens 10 Zeichen, beide Eingaben müssen übereinstimmen.',
        'api_key_format' => 'Das sieht nicht wie ein Anthropic-API-Schlüssel aus (beginnt mit „sk-ant-“).',
        'api_key_invalid' => 'Anthropic hat den API-Schlüssel abgelehnt. Bitte prüfen.',
        'api_unreachable' => 'Die Anthropic-API war nicht erreichbar. Bitte später erneut versuchen (evtl. blockiert das Hosting ausgehende Verbindungen).',
        'php_curl_missing' => 'Die PHP-Erweiterung cURL fehlt. Aktiviere sie im cPanel unter „Select PHP Version“ → Extensions.',
        'login_failed' => 'Passwort falsch.',
        'rate_limited' => 'Zu viele Versuche. Bitte warte ein paar Minuten.',
        'model_invalid' => 'Ungültige Modellkennung.',
        'storage_error' => 'Speichern fehlgeschlagen: Der Ordner data/ muss für PHP beschreibbar sein.',
    ][$e->errCode] ?? 'Aktion nicht möglich.';
} catch (Throwable $e) {
    error_log('YouGBT admin: ' . $e->getMessage());
    $err = 'Unerwarteter Fehler. Details stehen im PHP-Fehlerprotokoll.';
}

$ok = [
    'setup' => 'Einrichtung abgeschlossen! YouGBT ist spielbereit.',
    'saved' => 'Gespeichert.',
    'models' => 'Modellliste aktualisiert.',
    'password' => 'Passwort geändert.',
][(string) ($_GET['ok'] ?? '')] ?? '';

// Voraussetzungen
$checks = [
    ['PHP ≥ 8.0', PHP_VERSION_ID >= 80000, PHP_VERSION],
    ['cURL-Erweiterung', function_exists('curl_init'), ''],
    ['mbstring-Erweiterung', function_exists('mb_strlen'), ''],
    ['Speicher beschreibbar', is_writable(yg_data_dir()), ''],
];
$csrf = h(yg_admin_csrf());
$models = yg_cfg('models');
?><!doctype html>
<html lang="de" data-theme="dark">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="robots" content="noindex">
<title>YouGBT – Admin</title>
<link rel="icon" href="assets/icon.svg" type="image/svg+xml">
<link rel="stylesheet" href="assets/app.css?v=<?= YG_VERSION ?>">
</head>
<body class="admin">
<div class="bg" aria-hidden="true"><div class="bg-layer bg-dots"></div><div class="bg-layer"><i class="sh s1"></i><i class="sh s6"></i></div></div>
<main class="app admin-main">
  <div class="card glass">
    <h1 class="title-sm"><span class="logo-mark">?</span> YouGBT · Admin</h1>
    <?php if ($err): ?><p class="alert alert-err"><?= h($err) ?></p><?php endif; ?>
    <?php if ($ok): ?><p class="alert alert-ok"><?= h($ok) ?></p><?php endif; ?>

    <ul class="checks">
      <?php foreach ($checks as [$label, $pass, $info]): ?>
        <li class="<?= $pass ? 'pass' : 'fail' ?>"><?= $pass ? '✔' : '✖' ?> <?= h($label) ?> <?= $info ? '<small>(' . h($info) . ')</small>' : '' ?></li>
      <?php endforeach; ?>
      <li id="probe-result" class="muted" data-probe="data/probe.php">… Webschutz des Datenordners wird geprüft</li>
    </ul>

<?php if (!$configured): ?>
    <h2>Ersteinrichtung</h2>
    <p class="muted">Damit niemand Fremdes deine Installation übernimmt, brauchst du den <b>Einrichtungscode</b>.
      Öffne im cPanel den <b>Dateimanager</b>, gehe in den Ordner <code>yougbt/data/</code> und öffne die Datei
      <code>setup-code.php</code> (Rechtsklick → View). Dort steht der Code.</p>
    <?php yg_setup_code(); ?>
    <form method="post" class="form" autocomplete="off">
      <input type="hidden" name="csrf" value="<?= $csrf ?>"><input type="hidden" name="do" value="setup">
      <label>Einrichtungscode<input name="setup_code" required maxlength="16" autocomplete="off"></label>
      <label>Anthropic-API-Schlüssel<input name="api_key" type="password" required placeholder="sk-ant-…" autocomplete="off"></label>
      <label>Admin-Passwort (min. 10 Zeichen)<input name="password" type="password" required minlength="10" autocomplete="new-password"></label>
      <label>Passwort wiederholen<input name="password2" type="password" required minlength="10" autocomplete="new-password"></label>
      <button class="btn btn-primary" type="submit">Schlüssel prüfen &amp; einrichten</button>
      <p class="muted small">Der Schlüssel wird nur serverseitig gespeichert und nie an Browser ausgeliefert. Standardmodell: das günstigste passende verfügbare Modell (<code><?= h(YG_DEFAULT_MODEL) ?></code>), änderbar nach der Einrichtung.</p>
    </form>
<?php elseif (!$loggedIn): ?>
    <h2>Anmelden</h2>
    <form method="post" class="form">
      <input type="hidden" name="csrf" value="<?= $csrf ?>"><input type="hidden" name="do" value="login">
      <label>Admin-Passwort<input name="password" type="password" required autocomplete="current-password" autofocus></label>
      <button class="btn btn-primary" type="submit">Anmelden</button>
    </form>
<?php else: $u = yg_usage_today(); ?>
    <div class="stats">
      <div><b><?= (int) $u['calls'] ?></b><span>KI-Aufrufe heute (UTC) von <?= (int) yg_cfg('daily_limit') ?: '∞' ?></span></div>
      <div><b><?= (int) $u['errors'] ?></b><span>API-Fehler heute</span></div>
      <div><b><?= yg_active_games() ?></b><span>aktive Partien (max. <?= (int) yg_cfg('max_games') ?>)</span></div>
    </div>
    <p class="muted small">Speicherort: <?= yg_data_is_external() ? '<b>außerhalb des Webroots</b> ✔' : 'gesperrter Ordner <code>data/</code> in der App (außerhalb des Webroots war nicht beschreibbar)' ?></p>

    <h2>API &amp; Modell</h2>
    <form method="post" class="form" autocomplete="off">
      <input type="hidden" name="csrf" value="<?= $csrf ?>"><input type="hidden" name="do" value="save">
      <label>Aktueller Schlüssel<input value="<?= h(yg_mask_key((string) yg_cfg('api_key'))) ?>" disabled></label>
      <label>Neuer API-Schlüssel (leer lassen = unverändert)<input name="api_key" type="password" placeholder="sk-ant-…" autocomplete="off"></label>
      <label>Modell
        <select name="model">
          <?php
          $cur = (string) yg_cfg('model');
          $opts = is_array($models) && $models ? $models : [['id' => $cur, 'name' => $cur]];
          if (!in_array($cur, array_column($opts, 'id'), true)) {
              array_unshift($opts, ['id' => $cur, 'name' => $cur]);
          }
          foreach ($opts as $m): ?>
            <option value="<?= h($m['id']) ?>" <?= $m['id'] === $cur ? 'selected' : '' ?>><?= h($m['name']) ?> (<?= h($m['id']) ?>)<?= $m['id'] === YG_DEFAULT_MODEL ? ' – empfohlen, günstig' : '' ?></option>
          <?php endforeach; ?>
        </select>
      </label>
      <label>Tageslimit KI-Aufrufe (0 = unbegrenzt)<input name="daily_limit" type="number" min="0" max="100000" value="<?= (int) yg_cfg('daily_limit') ?>"></label>
      <label>Max. gleichzeitige Partien<input name="max_games" type="number" min="1" max="200" value="<?= (int) yg_cfg('max_games') ?>"></label>
      <button class="btn btn-primary" type="submit">Speichern</button>
    </form>
    <form method="post" class="form inline">
      <input type="hidden" name="csrf" value="<?= $csrf ?>"><input type="hidden" name="do" value="models">
      <button class="btn btn-ghost" type="submit">Verfügbare Modelle bei Anthropic abrufen</button>
    </form>
    <p class="muted small">Faustregel: Eine Runde braucht ca. 2 KI-Aufrufe (Frage + gebündelte Bewertung), eine Spezialrunde ca. 4. Hinweise kosten keinen zusätzlichen Aufruf.</p>

    <h2>Passwort ändern</h2>
    <form method="post" class="form" autocomplete="off">
      <input type="hidden" name="csrf" value="<?= $csrf ?>"><input type="hidden" name="do" value="password">
      <label>Aktuelles Passwort<input name="old" type="password" required autocomplete="current-password"></label>
      <label>Neues Passwort<input name="password" type="password" required minlength="10" autocomplete="new-password"></label>
      <label>Wiederholen<input name="password2" type="password" required minlength="10" autocomplete="new-password"></label>
      <button class="btn btn-ghost" type="submit">Passwort ändern</button>
    </form>
    <form method="post" class="form inline">
      <input type="hidden" name="csrf" value="<?= $csrf ?>"><input type="hidden" name="do" value="logout">
      <button class="btn btn-ghost" type="submit">Abmelden</button>
    </form>
<?php endif; ?>
    <p class="small"><a href="./">← Zum Spiel</a></p>
  </div>
</main>
<script src="assets/admin.js?v=<?= YG_VERSION ?>"></script>
</body>
</html>
