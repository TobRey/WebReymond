<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/lib/Auth.php';
require_once dirname(__DIR__) . '/lib/Defaults.php';
require_once dirname(__DIR__) . '/lib/Store.php';
require_once dirname(__DIR__) . '/lib/Validate.php';

Auth::start();
$isAdmin = Auth::isAdmin();

// Das Sicherheitstoken existiert nur für angemeldete Sitzungen.
if ($isAdmin && empty($_SESSION['arena_csrf'])) {
    $_SESSION['arena_csrf'] = bin2hex(random_bytes(16));
}
$csrf = $isAdmin ? (string) $_SESSION['arena_csrf'] : '';
$content = $isAdmin ? (new Store())->read() : null;
$uploadLimit = $isAdmin ? (string) ini_get('upload_max_filesize') : '';
require_once dirname(__DIR__) . '/lib/Version.php';
Version::noStore();
$version = (string) max(
    (int) (@filemtime(__DIR__ . '/app.js') ?: 0),
    (int) (@filemtime(__DIR__ . '/admin.css') ?: 0),
    (int) Version::stamp(dirname(__DIR__)),
) ?: (string) time();
?>
<!DOCTYPE html>
<html lang="de">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
<meta name="theme-color" content="#0b0e15">
<meta name="robots" content="noindex, nofollow">
<title>Arena Admin</title>
<link rel="stylesheet" href="admin.css?v=<?= htmlspecialchars($version, ENT_QUOTES) ?>">
</head>
<body class="<?= $isAdmin ? 'is-admin' : 'is-login' ?>">

<?php if (!$isAdmin): ?>
<main class="login">
  <form id="login-form" class="login__card" autocomplete="off">
    <h1>Arena Admin</h1>
    <p class="muted">Bitte Admin-Code eingeben.</p>
    <input id="login-code" class="input" type="password" inputmode="numeric" placeholder="Code" autocomplete="current-password" required>
    <button class="btn btn--primary" type="submit">Anmelden</button>
    <p id="login-error" class="error" role="alert"></p>
  </form>
</main>
<script>
document.getElementById('login-form').addEventListener('submit', async (e) => {
  e.preventDefault();
  const code = document.getElementById('login-code').value;
  const error = document.getElementById('login-error');
  error.textContent = '';
  try {
    const res = await fetch('../api.php?action=login', {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify({ code }),
    });
    const data = await res.json();
    if (!data.ok) { error.textContent = data.error || 'Login fehlgeschlagen.'; return; }
    location.reload();
  } catch {
    error.textContent = 'Verbindung fehlgeschlagen.';
  }
});
</script>

<?php else: ?>
<div class="shell">
  <aside class="sidebar">
    <div class="brand">Arena<span>Admin</span></div>
    <nav id="nav" class="nav">
      <button class="nav__item is-active" data-view="dashboard">Dashboard</button>
      <button class="nav__item" data-view="maps">Maps</button>
      <button class="nav__item" data-view="characters">Charaktere</button>
      <button class="nav__item" data-view="enemies">Gegner</button>
      <button class="nav__item" data-view="weapons">Waffen</button>
      <button class="nav__item" data-view="upgrades">Upgrades</button>
      <button class="nav__item" data-view="items">Gegenstände</button>
      <button class="nav__item" data-view="player">Spieler</button>
      <button class="nav__item" data-view="balance">Balancing</button>
      <button class="nav__item" data-view="audio">Audio</button>
      <button class="nav__item" data-view="shop">Laden &amp; Aussehen</button>
    </nav>
    <div class="sidebar__foot">
      <a class="btn btn--ghost" href="../">Zum Spiel</a>
      <button id="btn-logout" class="btn btn--ghost">Abmelden</button>
    </div>
  </aside>

  <main class="main">
    <header class="topbar">
      <button id="btn-menu" class="iconbtn" aria-label="Menü">☰</button>
      <h1 id="view-title">Dashboard</h1>
      <div class="topbar__actions" id="view-actions"></div>
    </header>
    <div id="view" class="view"></div>
  </main>
</div>

<div id="modal" class="modal" hidden>
  <div class="modal__backdrop" data-close-modal></div>
  <div class="modal__panel" role="dialog" aria-modal="true">
    <header class="modal__head">
      <h2 id="modal-title">Bearbeiten</h2>
      <button class="iconbtn" data-close-modal aria-label="Schließen">✕</button>
    </header>
    <div id="modal-body" class="modal__body"></div>
    <footer id="modal-foot" class="modal__foot"></footer>
  </div>
</div>

<div id="toasts" class="toasts"></div>

<script>
  window.ADMIN = {
    csrf: <?= json_encode($csrf) ?>,
    content: <?= json_encode($content, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?>,
    uploadLimit: <?= json_encode($uploadLimit) ?>,
    sprites: <?= json_encode(array_values(array_map(
        static fn(string $p): string => 'assets/sprites/' . basename($p),
        glob(dirname(__DIR__) . '/assets/sprites/*') ?: []
    ))) ?>
  };
</script>
<script>window.ARENA_PERKS = <?= json_encode(Defaults::perks(), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?>;
window.ARENA_MODS = <?= json_encode(Validate::MOD_LIMITS, JSON_UNESCAPED_SLASHES) ?>;</script>
<script type="module" src="app.js?v=<?= htmlspecialchars($version, ENT_QUOTES) ?>"></script>
<?php endif; ?>
</body>
</html>
