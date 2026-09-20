<?php
/** Aufbau der Spieloberfläche – gefüllt wird sie vom JavaScript. */
use SkyKingdoms\Core\Url;
?>
<!-- Kopfleiste: Profil und Rohstoffe -->
<header class="sk-hud">
    <button class="sk-hud__profile" id="sk-profile" type="button" aria-label="Profil öffnen">
        <span class="sk-hud__avatar" id="sk-avatar">?</span>
        <span>
            <span class="sk-hud__name" id="sk-playername">…</span>
            <span class="sk-hud__level" id="sk-level">Stufe 1</span>
        </span>
    </button>
    <div class="sk-res" id="sk-resources" aria-label="Rohstoffe"></div>
</header>

<!-- Inselwechsler -->
<nav class="sk-islands" id="sk-islands" aria-label="Inseln"></nav>

<!-- Schnellzugriffe -->
<div class="sk-side">
    <button class="sk-side__btn" data-panel="quests" type="button" aria-label="Aufgaben">
        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M9 11l3 3L22 4"/><path d="M21 12v7a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h11"/></svg>
        <span class="sk-side__badge sk-hidden" id="sk-quest-badge">0</span>
    </button>
    <button class="sk-side__btn" data-panel="notifications" type="button" aria-label="Benachrichtigungen">
        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M18 8A6 6 0 0 0 6 8c0 7-3 9-3 9h18s-3-2-3-9"/><path d="M13.7 21a2 2 0 0 1-3.4 0"/></svg>
        <span class="sk-side__badge sk-hidden" id="sk-notify-badge">0</span>
    </button>
    <button class="sk-side__btn" data-panel="settings" type="button" aria-label="Einstellungen">
        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="3"/><path d="M19.4 15a1.65 1.65 0 0 0 .33 1.82l.06.06a2 2 0 1 1-2.83 2.83l-.06-.06a1.65 1.65 0 0 0-1.82-.33 1.65 1.65 0 0 0-1 1.51V21a2 2 0 1 1-4 0v-.09A1.65 1.65 0 0 0 9 19.4a1.65 1.65 0 0 0-1.82.33l-.06.06a2 2 0 1 1-2.83-2.83l.06-.06a1.65 1.65 0 0 0 .33-1.82 1.65 1.65 0 0 0-1.51-1H3a2 2 0 1 1 0-4h.09A1.65 1.65 0 0 0 4.6 9a1.65 1.65 0 0 0-.33-1.82l-.06-.06a2 2 0 1 1 2.83-2.83l.06.06A1.65 1.65 0 0 0 9 4.6a1.65 1.65 0 0 0 1-1.51V3a2 2 0 1 1 4 0v.09a1.65 1.65 0 0 0 1 1.51 1.65 1.65 0 0 0 1.82-.33l.06-.06a2 2 0 1 1 2.83 2.83l-.06.06a1.65 1.65 0 0 0-.33 1.82V9a1.65 1.65 0 0 0 1.51 1H21a2 2 0 1 1 0 4h-.09a1.65 1.65 0 0 0-1.51 1z"/></svg>
    </button>
</div>

<!-- Untere Navigation -->
<nav class="sk-nav" aria-label="Hauptnavigation">
    <button class="sk-nav__btn is-active" data-panel="map" type="button">
        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polygon points="1 6 1 22 8 18 16 22 23 18 23 2 16 6 8 2 1 6"/><line x1="8" y1="2" x2="8" y2="18"/><line x1="16" y1="6" x2="16" y2="22"/></svg>
        <span>Karte</span>
    </button>
    <button class="sk-nav__btn" data-panel="build" type="button">
        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M14.7 6.3a1 1 0 0 0 0 1.4l1.6 1.6a1 1 0 0 0 1.4 0l3.77-3.77a6 6 0 0 1-7.94 7.94l-6.91 6.91a2.12 2.12 0 0 1-3-3l6.91-6.91a6 6 0 0 1 7.94-7.94l-3.76 3.76z"/></svg>
        <span>Bauen</span>
    </button>
    <button class="sk-nav__btn" data-panel="logistics" type="button">
        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="1" y="3" width="15" height="13"/><polygon points="16 8 20 8 23 11 23 16 16 16 16 8"/><circle cx="5.5" cy="18.5" r="2.5"/><circle cx="18.5" cy="18.5" r="2.5"/></svg>
        <span>Transport</span>
    </button>
    <button class="sk-nav__btn" data-panel="storage" type="button">
        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M21 8v13H3V8"/><path d="M1 3h22v5H1z"/><path d="M10 12h4"/></svg>
        <span>Lager</span>
    </button>
    <button class="sk-nav__btn" data-panel="army" type="button">
        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M14.5 17.5 3 6V3h3l11.5 11.5"/><path d="m13 19 6-6"/><path d="m16 16 4 4"/><path d="M19 21l2-2"/><path d="M21 3h-3L6.5 14.5"/><path d="m5 19 6-6"/><path d="m8 16-4 4"/><path d="M5 21 3 19"/></svg>
        <span>Armee</span>
    </button>
    <button class="sk-nav__btn" data-panel="more" type="button">
        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="1"/><circle cx="19" cy="12" r="1"/><circle cx="5" cy="12" r="1"/></svg>
        <span>Mehr</span>
    </button>
</nav>

<!-- Baumodus-Leiste -->
<div class="sk-buildbar" id="sk-buildbar">
    <div class="sk-buildbar__info" id="sk-buildbar-info"><strong>Gebäude setzen</strong>Tippe auf ein freies Feld.</div>
    <button class="sk-btn sk-btn--small sk-btn--ghost" id="sk-build-cancel" type="button">Abbrechen</button>
    <button class="sk-btn sk-btn--small sk-btn--green" id="sk-build-confirm" type="button">Hier bauen</button>
</div>

<!-- Bottom-Sheet -->
<div class="sk-backdrop" id="sk-backdrop"></div>
<section class="sk-sheet" id="sk-sheet" role="dialog" aria-modal="false" aria-labelledby="sk-sheet-title">
    <div class="sk-sheet__grip"></div>
    <header class="sk-sheet__head">
        <img class="sk-sheet__icon sk-hidden" id="sk-sheet-icon" src="" alt="">
        <div class="sk-grow">
            <h2 class="sk-sheet__title" id="sk-sheet-title">…</h2>
            <div class="sk-sheet__sub" id="sk-sheet-sub"></div>
        </div>
        <button class="sk-sheet__close" id="sk-sheet-close" type="button" aria-label="Schliessen">✕</button>
    </header>
    <div class="sk-sheet__body" id="sk-sheet-body"></div>
</section>

<!-- Kampfoberfläche -->
<div class="sk-battle" id="sk-battle">
    <canvas class="sk-battle__canvas" id="sk-battle-canvas"></canvas>
    <div class="sk-battle__hud">
        <span class="sk-pill" id="sk-battle-timer">0 s</span>
        <span class="sk-pill sk-pill--green" id="sk-battle-squad">0 Einheiten</span>
        <span class="sk-grow"></span>
        <button class="sk-btn sk-btn--small sk-btn--danger" id="sk-battle-quit" type="button">Abbrechen</button>
    </div>
    <div class="sk-battle__abilities" id="sk-battle-abilities"></div>
</div>

<div class="sk-toasts" id="sk-toasts" aria-live="polite"></div>
<noscript>
    <div class="sk-simple"><div class="sk-simple__card">
        <h1>JavaScript nötig</h1>
        <p>Für die Spielwelt wird JavaScript benötigt. Bitte aktiviere es in deinem Browser.</p>
        <p><a href="<?= e(Url::to('?p=profil')) ?>">Zum Profil ohne JavaScript</a></p>
    </div></div>
</noscript>
