<?php
/**
 * CASH – privates Finanzsystem.
 * Ohne gültige PIN-Session liefert diese Seite nur das PIN-Formular aus – weder App-Code
 * noch Daten. Alle Daten kommen über api.php (ebenfalls PIN-geschützt).
 */
declare(strict_types=1);

const CASH_ENTRY = true;
require __DIR__ . '/app/bootstrap.php';

Http::forceHttps();
Http::securityHeaders(true);

$e = static fn(string $s): string => htmlspecialchars($s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');

try {
    cash_data_dir();
    Auth::startSession();
} catch (Throwable $ex) {
    error_log('[CASH] Start fehlgeschlagen: ' . $ex::class);
    http_response_code(500);
    exit('CASH konnte nicht gestartet werden.');
}

// Alte, öffentlich liegende App-Datei aus Version 1.x entfernen (Code liegt jetzt geschützt in app/).
if (is_file(__DIR__ . '/assets/app.js')) {
    @unlink(__DIR__ . '/assets/app.js');
}

// App-Code nur nach PIN ausliefern.
if (($_GET['asset'] ?? '') === 'app.js') {
    if (!Pin::verified()) {
        http_response_code(403);
        exit;
    }
    $raw = (string)file_get_contents(__DIR__ . '/app/client.js.php');
    $raw = substr($raw, strpos($raw, "\n") + 1);
    header('Content-Type: application/javascript; charset=utf-8');
    header('Cache-Control: private, no-store');
    echo $raw;
    exit;
}

// PIN-Formulare (POST → Redirect → GET).
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    $msg = null;
    try {
        Http::checkSameOrigin();
        $sent = is_string($_POST['csrf'] ?? null) ? $_POST['csrf'] : '';
        if ($sent === '' || !hash_equals(Auth::csrf(), $sent)) {
            $msg = 'Sitzung abgelaufen. Bitte erneut versuchen.';
        } elseif (($_POST['do'] ?? '') === 'setup') {
            $msg = Pin::setup($_POST);
        } else {
            $msg = Pin::attempt($_POST['pin'] ?? '');
        }
    } catch (ApiError $ex) {
        $msg = $ex->getMessage();
    }
    if ($msg !== null) {
        $_SESSION['pin_flash'] = $msg;
    }
    header('Location: ' . Http::basePath(), true, 303);
    exit;
}

header('Content-Type: text/html; charset=utf-8');
$unlocked = Pin::verified();
$v = rawurlencode(CASH_VERSION . '-' . substr((string)@filemtime(__DIR__ . '/app/client.js.php'), -6));
?><!doctype html>
<html lang="de-CH">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
<meta name="robots" content="noindex, nofollow">
<meta name="theme-color" content="#F5F8F6">
<meta name="apple-mobile-web-app-capable" content="yes">
<meta name="mobile-web-app-capable" content="yes">
<meta name="apple-mobile-web-app-status-bar-style" content="default">
<meta name="apple-mobile-web-app-title" content="CASH">
<meta name="format-detection" content="telephone=no">
<title>CASH</title>
<link rel="manifest" href="manifest.webmanifest">
<link rel="icon" href="assets/icon.svg" type="image/svg+xml">
<link rel="apple-touch-icon" href="assets/icon-180.png">
<link rel="stylesheet" href="assets/app.css?v=<?= $e($v) ?>">
</head>
<body>
<?php if ($unlocked): ?>
<div id="app" aria-live="polite">
  <div class="boot"><div class="boot-logo">CASH</div></div>
</div>
<noscript><p class="noscript">CASH benötigt JavaScript.</p></noscript>
<script src="index.php?asset=app.js&amp;v=<?= $e($v) ?>" defer></script>
<?php else:
    $flash = $_SESSION['pin_flash'] ?? null;
    unset($_SESSION['pin_flash']);
    $configured = Pin::isConfigured();
    $lock = $configured ? Pin::currentLock() : null;
    $needCreds = !$configured && Auth::hasUsers();
?>
<div class="auth"><div class="auth-box">
  <div class="auth-logo"><img src="assets/icon.svg" alt=""><b>CASH</b></div>
  <?php if ($configured): ?>
    <h1>PIN eingeben</h1>
    <p class="lead">CASH ist geschützt.</p>
    <form class="form" method="post" action="<?= $e(Http::basePath()) ?>" autocomplete="off">
      <input type="hidden" name="csrf" value="<?= $e(Auth::csrf()) ?>">
      <input type="hidden" name="do" value="pin">
      <label class="field"><span>PIN</span>
        <input class="inp pin-inp" type="password" name="pin" inputmode="numeric" pattern="[0-9]{4,8}" minlength="4" maxlength="8" required autofocus<?= $lock ? ' disabled' : '' ?>>
      </label>
      <?php if ($flash || $lock): ?><div class="err" role="alert"><?= $e((string)($lock ?? $flash)) ?></div><?php endif; ?>
      <button class="btn mint block" type="submit"<?= $lock ? ' disabled' : '' ?>>Entsperren</button>
    </form>
  <?php else: ?>
    <h1>PIN festlegen</h1>
    <p class="lead"><?= $needCreds ? 'Zum Schutz aller Seiten wird jetzt eine PIN eingerichtet. Bestätige dies mit den Zugangsdaten deines Hauptaccounts.' : 'Lege die PIN fest, die CASH ab sofort schützt.' ?></p>
    <form class="form" method="post" action="<?= $e(Http::basePath()) ?>" autocomplete="off">
      <input type="hidden" name="csrf" value="<?= $e(Auth::csrf()) ?>">
      <input type="hidden" name="do" value="setup">
      <?php if ($needCreds): ?>
        <label class="field"><span>Benutzername</span><input class="inp" type="text" name="username" autocomplete="username" autocapitalize="none" required></label>
        <label class="field"><span>Passwort</span><input class="inp" type="password" name="password" autocomplete="current-password" required></label>
      <?php endif; ?>
      <div class="row2">
        <label class="field"><span>Neue PIN</span><input class="inp pin-inp" type="password" name="pin" inputmode="numeric" pattern="[0-9]{4,8}" minlength="4" maxlength="8" required></label>
        <label class="field"><span>Wiederholen</span><input class="inp pin-inp" type="password" name="pin2" inputmode="numeric" pattern="[0-9]{4,8}" minlength="4" maxlength="8" required></label>
      </div>
      <?php if ($flash): ?><div class="err" role="alert"><?= $e((string)$flash) ?></div><?php endif; ?>
      <button class="btn mint block" type="submit">PIN speichern</button>
      <div class="note"><span>Eine 4-stellige PIN hat nur 10’000 Möglichkeiten und bietet begrenzten Schutz. CASH sperrt nach Fehlversuchen; zusätzlich bleibt die Anmeldung mit Passwort aktiv.</span></div>
    </form>
  <?php endif; ?>
</div></div>
<?php endif; ?>
</body>
</html>
