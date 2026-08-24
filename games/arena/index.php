<?php
declare(strict_types=1);

require_once __DIR__ . '/lib/Defaults.php';
require_once __DIR__ . '/lib/Store.php';
require_once __DIR__ . '/lib/Version.php';

// Die Seite traegt die Spieldaten in sich - sie darf nie aus dem Cache kommen,
// sonst spielt man mit Werten von vorgestern weiter.
Version::noStore();

$store = new Store();
$content = $store->read();
$version = Version::stamp(__DIR__);
$importMap = Version::importMap(__DIR__);

?>
<!DOCTYPE html>
<html lang="de">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1, maximum-scale=1, user-scalable=no, viewport-fit=cover">
<meta name="theme-color" content="#07090e">
<meta name="mobile-web-app-capable" content="yes">
<meta name="apple-mobile-web-app-status-bar-style" content="black-translucent">
<meta name="description" content="Arena Survivors - mobiles Top-Down-Roguelite mit Wellen, Bossen und Upgrades.">
<title>Arena Survivors</title>
<link rel="preload" href="assets/fonts/rubik-latin.woff2" as="font" type="font/woff2" crossorigin>
<link rel="preload" href="assets/fonts/pixelify-sans-latin.woff2" as="font" type="font/woff2" crossorigin>
<link rel="stylesheet" href="assets/css/game.css?v=<?= htmlspecialchars($version, ENT_QUOTES) ?>">
<link rel="icon" href="data:image/svg+xml,<svg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 100 100'><rect width='100' height='100' rx='22' fill='%237c6cff'/><path d='M28 66l22-38 22 38z' fill='%23fff'/></svg>">
</head>
<body>

<canvas id="stage" aria-label="Spielfeld"></canvas>

<!-- ------------------------------------------------------------- HUD -->
<div id="hud" class="hud" hidden>
  <div class="hud__top">
    <div class="hud__pillen">
      <div class="pill pill--wave"><b id="hud-wave">1-1</b><span id="hud-cycle">Zyklus 1</span></div>
      <div class="pill pill--timer"><b id="hud-timer">1:00</b></div>
      <div class="pill pill--money"><span class="coin"></span><b id="hud-money">0</b></div>
    </div>
    <div class="hud__buttons">
      <button id="btn-sound" class="iconbtn" data-ui title="Musik an/aus">♪</button>
      <button id="btn-stats" class="iconbtn" data-ui title="Statistiken">≡</button>
      <button id="btn-pause" class="iconbtn" data-ui title="Pause">II</button>
    </div>
  </div>

  <div class="hud__boss" id="hud-boss" hidden>
    <div class="bossbar__label"><span id="boss-name">Boss</span><span id="boss-hp-text"></span></div>
    <div class="bossbar"><i id="boss-fill"></i></div>
  </div>

  <div class="hud__bottom">
    <div class="vitals">
      <div class="bar bar--hp"><i id="hp-fill"></i><span id="hp-text">100 / 100</span></div>
      <div class="bar bar--shield" id="shield-bar" hidden><i id="shield-fill"></i></div>
    </div>
    <div class="hud__weapon" id="hud-weapon" data-ui>
      <img id="hud-weapon-icon" alt="">
      <span id="hud-weapon-name">Pistole</span>
    </div>
  </div>

  <button id="btn-ult" class="ultbtn" data-ui title="Druckwelle">
    <span class="ultbtn__fuell" id="ult-fuell"></span>
    <span class="ultbtn__icon">◎</span>
    <span class="ultbtn__zeit" id="ult-zeit"></span>
  </button>

  <div id="debug-panel" class="debug" hidden></div>
  <div id="wave-banner" class="banner" hidden></div>

<!-- Countdown vor jeder Welle und nach der Pause -->
<div id="countdown" class="countdown" hidden>
  <div class="countdown__inner">
    <div id="countdown-kicker" class="countdown__kicker">Bereit?</div>
    <div id="countdown-zahl" class="countdown__zahl">3</div>
  </div>
</div>
</div>

<!-- ------------------------------------------------------------ Menü -->
<section id="screen-menu" class="screen screen--titel is-active">
  <div class="menu">
    <h1 class="logo">Arena<span>Survivors</span></h1>
    <p class="logo__sub">Überlebe Welle für Welle. Wähle klug.</p>
    <div class="menu__buttons">
      <button id="btn-play" class="btn btn--gold btn--xl">Spielen</button>
      <div class="menu__row">
        <button id="btn-scores" class="btn btn--stein">Bestenliste</button>
        <button id="btn-account" class="btn btn--stein">Anmelden</button>
      </div>
      <div class="menu__row">
        <button id="btn-sound-menu" class="btn btn--stein">Ton: an</button>
        <a class="btn btn--stein" href="admin/">Admin</a>
      </div>
    </div>
    <div id="menu-best" class="menu__best"></div>
  </div>
</section>

<!-- -------------------------------------------------------- Charakter -->
<section id="screen-character" class="screen screen--charwahl">
  <div class="charwahl">
    <header class="charwahl__kopf">
      <button class="btn btn--stein btn--klein" data-back>Zurück</button>
      <h2>Charakter wählen</h2>
      <span id="xp-badge" class="chip"></span>
    </header>

    <div class="charwahl__buehne">
      <button id="char-prev" class="charpfeil charpfeil--links" type="button" aria-label="Vorheriger Charakter">‹</button>
      <div class="charwahl__figur">
        <img id="char-bild" alt="">
        <div id="char-schloss" class="charwahl__schloss" hidden></div>
      </div>
      <button id="char-next" class="charpfeil charpfeil--rechts" type="button" aria-label="Nächster Charakter">›</button>
      <div id="char-info" class="charwahl__info"></div>
    </div>

    <div class="charwahl__fuss">
      <div id="char-punkte" class="charpunkte"></div>
      <button id="char-waehlen" class="btn btn--gold btn--xl">Auswählen</button>
    </div>
  </div>
</section>

<!-- ------------------------------------------------------ Bestenliste -->
<section id="screen-scores" class="screen">
  <div class="panel">
    <header class="panel__head">
      <h2>Bestenliste</h2>
      <button class="btn btn--quiet" data-back>Zurück</button>
    </header>
    <div id="score-own" class="scoreown"></div>
    <div id="score-list" class="scorelist"></div>
  </div>
</section>

<!-- ------------------------------------------------------------ Konto -->
<section id="screen-account" class="screen screen--center">
  <div class="panel panel--narrow">
    <header class="panel__head">
      <h2 id="account-title">Anmelden</h2>
      <button class="btn btn--quiet" data-back>Zurück</button>
    </header>
    <div id="account-body" class="form"></div>
  </div>
</section>

<!-- ------------------------------------------------------------- Waffe -->
<section id="screen-weapon" class="screen">
  <div class="panel">
    <header class="panel__head">
      <h2>Starterwaffe</h2>
      <button class="btn btn--quiet" data-back>Zurück</button>
    </header>
    <div id="weapon-list" class="weaponlist"></div>
  </div>
</section>

<!-- --------------------------------------------------------- Overlays -->
<div id="overlay-upgrade" class="overlay" hidden>
  <div class="overlay__inner">
    <p class="overlay__kicker" id="upgrade-kicker">Welle geschafft</p>
    <h2 class="overlay__title">Wähle ein Upgrade</h2>
    <div id="upgrade-cards" class="cards"></div>
  </div>
</div>

<div id="overlay-swap" class="overlay overlay--dialog" hidden>
  <div class="dialog">
    <h3>Waffe ersetzen?</h3>
    <p id="swap-text"></p>
    <div class="dialog__actions">
      <button id="swap-yes" class="btn btn--primary">Ersetzen</button>
      <button id="swap-no" class="btn btn--quiet">Behalten</button>
    </div>
  </div>
</div>

<!-- Der Laden zwischen den Wellen -->
<div id="overlay-shop" class="overlay overlay--shop" hidden>
  <div class="shop" id="shop-buehne">
    <div class="shop__haendler" id="shop-haendler" aria-hidden="true"></div>
    <img class="shop__tresen" id="shop-tresen" alt="" aria-hidden="true">

    <div class="shop__inhalt">
      <header class="shop__kopf">
        <h2 class="shop__titel" id="shop-titel">Laden</h2>
        <div class="shop__geld"><span class="coin"></span><b id="shop-geld">0</b></div>
      </header>
      <div class="shop__auslage" id="shop-auslage"></div>
      <div class="shop__leiste">
        <button id="shop-reroll" class="btn btn--stein" type="button">Tauschen</button>
        <button id="shop-liste-btn" class="btn btn--stein" type="button">Gekauft</button>
        <button id="shop-weiter" class="btn btn--gold" type="button">Weiter</button>
      </div>
    </div>

    <div class="shop__besitz" id="shop-besitz" hidden>
      <header class="shop__besitzkopf">
        <h3>Dein Aufbau</h3>
        <button id="shop-liste-zu" class="btn btn--stein btn--klein" type="button">Schließen</button>
      </header>
      <div id="shop-besitz-liste" class="upgradelist"></div>
    </div>
  </div>
</div>

<div id="overlay-stats" class="overlay" data-dismiss hidden>
  <div class="overlay__inner overlay__inner--scroll">
    <header class="panel__head">
      <h2>Statistiken</h2>
      <button class="btn btn--primary" data-close-stats>Weiterspielen</button>
    </header>
    <div id="stats-grid" class="statgrid"></div>
    <h3 class="subhead">Gewählte Upgrades</h3>
    <div id="stats-upgrades" class="upgradelist"></div>
  </div>
</div>

<div id="overlay-pause" class="overlay overlay--dialog" data-dismiss hidden>
  <div class="dialog">
    <h3>Pause</h3>

    <div class="soundpanel">
      <label class="soundrow">
        <span>Musik</span>
        <input id="opt-music" type="checkbox" class="switch">
        <input id="vol-music" type="range" min="0" max="100" step="5" aria-label="Musiklautstärke">
      </label>
      <label class="soundrow">
        <span>Effekte</span>
        <input id="opt-sfx" type="checkbox" class="switch">
        <input id="vol-sfx" type="range" min="0" max="100" step="5" aria-label="Effektlautstärke">
      </label>
    </div>

    <div class="dialog__actions dialog__actions--column">
      <button id="pause-resume" class="btn btn--primary btn--lg">Weiter</button>
      <button id="pause-debug" class="btn btn--quiet">Debug-Ansicht</button>
      <button id="pause-quit" class="btn btn--quiet">Run beenden</button>
    </div>
    <p class="muted dialog__hint">Tippe neben das Fenster, um weiterzuspielen.</p>
  </div>
</div>

<div id="overlay-death" class="overlay" hidden>
  <div class="overlay__inner overlay__inner--scroll">
    <p class="overlay__kicker">Run beendet</p>
    <h2 class="overlay__title" id="death-title">Du bist gefallen</h2>
    <p id="death-xp" class="death-xp" hidden></p>
    <div id="death-grid" class="statgrid"></div>
    <h3 class="subhead">Upgrades</h3>
    <div id="death-upgrades" class="upgradelist"></div>
    <div class="dialog__actions">
      <button id="death-retry" class="btn btn--primary btn--xl">Nochmal</button>
      <button id="death-menu" class="btn btn--quiet">Hauptmenü</button>
    </div>
  </div>
</div>

<div id="loading" class="loading" hidden>
  <div class="loading__spinner"></div>
  <span id="loading-text">Lade ...</span>
  <div id="loading-bar" class="loading__bar"><i></i></div>
  <p id="loading-hint" class="loading__hint" hidden></p>
</div>
<div id="rotate-hint" class="rotate" hidden>Drehe dein Handy für das beste Spielerlebnis</div>
<div id="toasts" class="toasts"></div>

<script>window.ARENA_CONTENT = <?= json_encode($content, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?>;</script>
<script>window.ARENA_PERKS = <?= json_encode(Defaults::perks(), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?>;</script>
<script type="importmap">
<?= json_encode(['imports' => $importMap], JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT) ?>
</script>
<script type="module" src="assets/js/main.js?v=<?= htmlspecialchars($version, ENT_QUOTES) ?>"></script>
</body>
</html>
