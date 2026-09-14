<?php
use App\Core\View;
$case = $state['case'];
$panels = [
    ['id' => 'akte',     'label' => 'Fallakte',   'key' => '1', 'icon' => 'file'],
    ['id' => 'personen', 'label' => 'Personen',   'key' => '2', 'icon' => 'people'],
    ['id' => 'beweise',  'label' => 'Beweise',    'key' => '3', 'icon' => 'evidence'],
    ['id' => 'geraete',  'label' => 'Geraete',    'key' => '4', 'icon' => 'device'],
    ['id' => 'wand',     'label' => 'Wand',       'key' => '5', 'icon' => 'board'],
    ['id' => 'karte',    'label' => 'Karte',      'key' => '6', 'icon' => 'map'],
    ['id' => 'zeit',     'label' => 'Zeitleiste', 'key' => '7', 'icon' => 'clock'],
    ['id' => 'notizen',  'label' => 'Notizen',    'key' => '8', 'icon' => 'note'],
    ['id' => 'bericht',  'label' => 'Bericht',    'key' => '9', 'icon' => 'report'],
];
?>
<div class="term" id="term">
    <header class="term__bar">
        <div class="term__id">
            <span class="seal">FBI</span>
            <div>
                <strong><?= View::e($case['title']) ?></strong>
                <small><?= View::e($case['code']) ?> &middot; <?= View::e($case['location']) ?> &middot; <?= View::e($case['incident_date']) ?> &middot; <?= View::e($agentName) ?></small>
            </div>
        </div>
        <div class="term__status">
            <div class="stat" title="Gesicherte Beweise">
                <span class="stat__label">Beweise</span>
                <span class="stat__value" id="stat-evidence">0/0</span>
            </div>
            <div class="stat" title="Fortschritt">
                <span class="stat__label">Stand</span>
                <span class="stat__value" id="stat-progress">0 %</span>
            </div>
            <?php if (!empty($gameplay['showTimer'])): ?>
            <div class="stat" title="Bearbeitungszeit">
                <span class="stat__label">Zeit</span>
                <span class="stat__value" id="stat-time">00:00</span>
            </div>
            <?php endif; ?>
        </div>
        <div class="term__tools">
            <button class="tool tool--hint" id="btn-hint" title="Hinweis anfordern (H)" aria-label="Hinweis anfordern">
                <svg viewBox="0 0 24 24" aria-hidden="true"><path d="M9 21h6v-1H9v1zm3-20a7 7 0 0 0-4 12.7V17h8v-3.3A7 7 0 0 0 12 1z"/></svg>
                <span class="tool__badge" id="hint-count"><?= (int)($state['progress']['hints_left'] ?? 0) ?></span>
            </button>
            <button class="tool" id="btn-audio" title="Ton an/aus" aria-label="Ton umschalten">
                <svg viewBox="0 0 24 24" aria-hidden="true"><path d="M4 9v6h4l5 5V4L8 9H4z"/></svg>
            </button>
            <button class="tool" id="btn-settings" title="Einstellungen" aria-label="Einstellungen">
                <svg viewBox="0 0 24 24" aria-hidden="true"><path d="M12 8a4 4 0 1 0 0 8 4 4 0 0 0 0-8zm9 4-2 1.6.3 2.5-2.4.8-1.2 2.2-2.5-.4L12 21l-1.2-2.3-2.5.4L7 16.9l-2.4-.8.3-2.5L3 12l2-1.6-.4-2.5L7 7.1l1.3-2.2 2.5.4L12 3l1.2 2.3 2.5-.4L17 7.1l2.4.8-.3 2.5L21 12z"/></svg>
            </button>
            <a class="tool" href="<?= View::url('/faelle') ?>" title="Fall verlassen" aria-label="Fall verlassen">
                <svg viewBox="0 0 24 24" aria-hidden="true"><path d="M10 17l1.4-1.4L8.8 13H20v-2H8.8l2.6-2.6L10 7l-5 5 5 5zM4 5h6V3H4a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h6v-2H4V5z"/></svg>
            </a>
        </div>
    </header>

    <div class="term__body">
        <nav class="rail" aria-label="Bereiche">
            <?php foreach ($panels as $panel): ?>
                <button class="rail__item" data-panel="<?= View::e($panel['id']) ?>" title="<?= View::e($panel['label']) ?> (<?= $panel['key'] ?>)">
                    <span class="rail__icon" data-icon="<?= View::e($panel['icon']) ?>" aria-hidden="true"></span>
                    <span class="rail__label"><?= View::e($panel['label']) ?></span>
                    <span class="rail__dot" data-dot="<?= View::e($panel['id']) ?>" hidden></span>
                </button>
            <?php endforeach; ?>
        </nav>

        <main class="stage" id="panel-root" tabindex="-1">
            <div class="stage__loading">Terminal wird geladen ...</div>
        </main>
    </div>

    <div class="toasts" id="toasts" aria-live="polite"></div>
</div>

<div class="overlay" id="overlay" hidden>
    <div class="overlay__box" role="dialog" aria-modal="true" aria-labelledby="overlay-title">
        <header class="overlay__head">
            <h2 id="overlay-title"></h2>
            <button class="overlay__close" id="overlay-close" aria-label="Schliessen">&times;</button>
        </header>
        <div class="overlay__body" id="overlay-body"></div>
    </div>
</div>

<div class="horror-layer" id="horror-layer" aria-hidden="true"></div>

<?php if (empty($ageConfirmed)): ?>
<div class="gate" id="age-gate">
    <div class="gate__box">
        <p class="eyebrow">Inhaltswarnung</p>
        <h2>Dieser Fall ist ab 18 Jahren</h2>
        <p><?= View::e($case['content_warning'] ?: 'Dieses Spiel enthaelt Gewaltdarstellungen, Schilderungen von Toetungsdelikten, Blut, psychologischen Horror und beklemmende Szenen.') ?></p>
        <ul class="list">
            <li>Alle Personen, Orte, Geraete und Ereignisse sind frei erfunden.</li>
            <li>Simulierte Logins, Handys und Computer sind reine Spielelemente. Es werden keine realen Systeme angesprochen.</li>
            <li>Schreckmomente, Bildeffekte und Lautstaerke lassen sich jederzeit in den Einstellungen abschalten.</li>
            <li>Wenn dich Themen wie Vermisstenfaelle oder Gewalt belasten, spiele diesen Fall bitte nicht.</li>
        </ul>
        <div class="gate__actions">
            <button class="btn btn--primary" id="age-accept">Ich bin 18 oder aelter - Ermittlung starten</button>
            <a class="btn btn--ghost" href="<?= View::url('/faelle') ?>">Abbrechen</a>
        </div>
    </div>
</div>
<?php endif; ?>

<div class="intro" id="intro" hidden>
    <div class="intro__box">
        <p class="eyebrow">Einsatzbefehl</p>
        <h2 id="intro-title"></h2>
        <div id="intro-body"></div>
        <button class="btn btn--primary" id="intro-start">Ermittlung aufnehmen</button>
    </div>
</div>
