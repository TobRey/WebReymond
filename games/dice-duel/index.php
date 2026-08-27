<?php
declare(strict_types=1);

require_once __DIR__ . '/lib/Content.php';
require_once __DIR__ . '/lib/Game.php';

$catalog = [
    'dice' => Content::dice(),
    'potions' => Content::potions(),
    'startMoney' => Content::START_MONEY,
    'turnsPerPlayer' => Content::TURNS_PER_PLAYER,
    'totalTurns' => Game::TOTAL_TURNS,
];
$assetVersion = (string) @filemtime(__DIR__ . '/assets/app.js') ?: '1';
?>
<!DOCTYPE html>
<html lang="de">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
<meta name="theme-color" content="#0b0d12">
<meta name="description" content="Dice Duel - modernes 2-Spieler-Würfelspiel mit Raumcode, Spezialwürfeln und Tränken.">
<title>Dice Duel</title>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=Space+Grotesk:wght@500;600;700&family=Inter:wght@400;500;600&display=swap" media="print" onload="this.media='all';this.onload=null">
<noscript><link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=Space+Grotesk:wght@500;600;700&family=Inter:wght@400;500;600&display=swap"></noscript>
<link rel="stylesheet" href="assets/app.css?v=<?= htmlspecialchars($assetVersion, ENT_QUOTES) ?>">
<link rel="icon" href="data:image/svg+xml,<svg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 100 100'><rect width='100' height='100' rx='24' fill='%236c5cff'/><circle cx='32' cy='32' r='9' fill='%23fff'/><circle cx='68' cy='68' r='9' fill='%23fff'/><circle cx='50' cy='50' r='9' fill='%23fff'/></svg>">
</head>
<body>

<div id="toasts" class="toasts" aria-live="polite"></div>
<div id="tip" class="tip" hidden role="tooltip"></div>

<!-- ------------------------------------------------------------- Home -->
<section id="screen-home" class="screen screen--center is-active">
  <div class="home">
    <header class="home__head">
      <div class="brand">
        <span class="brand__mark" aria-hidden="true">
          <i></i><i></i><i></i>
        </span>
        <h1 class="brand__name">Dice&nbsp;Duel</h1>
      </div>
      <p class="home__tagline">Zwei Spieler, vier Zielzahlen, 20 Züge.</p>
    </header>

    <div class="card home__card">
      <label class="field">
        <span class="field__label">Dein Name</span>
        <input id="input-name" class="input" type="text" maxlength="16" autocomplete="nickname" placeholder="z. B. Nova" spellcheck="false">
      </label>

      <button id="btn-create" class="btn btn--primary btn--lg">Raum erstellen</button>

      <div class="divider"><span>oder</span></div>

      <label class="field">
        <span class="field__label">Raumcode</span>
        <input id="input-code" class="input input--code" type="text" maxlength="5" autocomplete="off" placeholder="ABCDE" spellcheck="false">
      </label>
      <button id="btn-join" class="btn btn--ghost btn--lg">Raum beitreten</button>
      <p id="home-error" class="form-error" role="alert"></p>
    </div>
  </div>
</section>

<!-- ------------------------------------------------------------ Lobby -->
<section id="screen-lobby" class="screen screen--center">
  <div class="card lobby">
    <p class="lobby__hint">Code teilen</p>
    <button id="room-code" class="roomcode" title="Code kopieren"><span id="room-code-text">-----</span></button>
    <div class="lobby__players">
      <div class="lobby__player is-ready"><span class="dot"></span><span id="lobby-p1">-</span></div>
      <div class="lobby__player" id="lobby-slot2"><span class="dot"></span><span id="lobby-p2">Warte auf Spieler 2</span></div>
    </div>
    <div class="lobby__wait"><span class="pulse"></span> Startet automatisch, sobald beide da sind</div>
    <button id="btn-leave-lobby" class="btn btn--quiet">Abbrechen</button>
  </div>
</section>

<!-- ------------------------------------------------------------- Game -->
<section id="screen-game" class="screen">
  <header class="topbar">
    <div class="topbar__left">
      <span class="brand__mark brand__mark--sm" aria-hidden="true"><i></i><i></i><i></i></span>
      <span class="topbar__title">Dice Duel</span>
    </div>
    <div class="topbar__mid">
      <button id="topbar-code" class="chip chip--code" title="Raumcode kopieren"><span id="topbar-code-text">-----</span></button>
      <span class="chip"><b id="turn-counter">1</b><span class="chip__sub">/ <?= Game::TOTAL_TURNS ?> Züge</span></span>
    </div>
    <div class="topbar__right">
      <button id="btn-shop" class="btn btn--shop">Shop</button>
    </div>
  </header>

  <div class="board">
    <div class="players" id="players"></div>

    <div class="stage">
      <div class="stage__head">
        <span id="turn-avatar" class="turn-avatar">1</span>
        <span id="turn-line" class="turn-line">Du bist dran</span>
        <span id="turn-step" class="turn-step">Zielzahl wählen</span>
      </div>

      <div id="targets" class="targets"></div>

      <div id="roll-stage" class="rollstage">
        <div id="dice-view" class="dicecubes"></div>
        <div id="roll-result" class="rollresult" hidden>
          <div class="rollresult__total"><span id="result-total">0</span></div>
          <div id="result-label" class="rollresult__label"></div>
          <div id="result-gains" class="rollresult__gains"></div>
          <div id="result-effects" class="rollresult__effects"></div>
        </div>
      </div>
    </div>

    <div class="actionbar">
      <div class="actionbar__dice">
        <div class="actionbar__title">Deine Würfel<span id="tip-hint" class="tip-hint">halten für Info</span><span id="dice-hint" class="dice-hint">0/2</span></div>
        <div id="dice-tray" class="dicetray"></div>
      </div>
      <button id="btn-roll" class="btn btn--roll" disabled>Würfeln</button>
    </div>

    <div class="log" id="log" aria-live="polite"></div>
  </div>
</section>

<!-- ------------------------------------------------------------ Result -->
<section id="screen-result" class="screen screen--center">
  <div class="card result">
    <div id="result-crown" class="result__crown">🏆</div>
    <h2 id="result-title" class="result__title">Sieg</h2>
    <div id="result-scores" class="result__scores"></div>
    <div id="result-stats" class="result__stats"></div>
    <div class="result__actions">
      <button id="btn-rematch" class="btn btn--primary btn--lg">Revanche</button>
      <button id="btn-home" class="btn btn--quiet">Neues Spiel</button>
    </div>
    <p id="rematch-hint" class="muted"></p>
  </div>
</section>

<!-- -------------------------------------------------------------- Shop -->
<div id="shop" class="shop" hidden>
  <div class="shop__backdrop" data-close-shop></div>
  <aside class="shop__panel" role="dialog" aria-label="Shop">
    <header class="shop__head">
      <h2>Shop</h2>
      <div class="shop__money">$<span id="shop-money">0</span></div>
      <button class="shop__close" data-close-shop aria-label="Schließen">✕</button>
    </header>
    <div class="tabs">
      <button class="tab is-active" data-tab="dice">Spezialwürfel</button>
      <button class="tab" data-tab="potions">Tränke</button>
    </div>
    <div id="shop-list-dice" class="shop__list"></div>
    <div id="shop-list-potions" class="shop__list" hidden></div>
  </aside>
</div>

<script>window.CATALOG = <?= json_encode($catalog, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?>;</script>
<script src="assets/app.js?v=<?= htmlspecialchars($assetVersion, ENT_QUOTES) ?>" defer></script>
</body>
</html>
