<?php
/** @var int $players @var int $active @var int $banned @var int $newToday @var array $top @var array $audit @var array $storage */
use SkyKingdoms\Core\Csrf;
use SkyKingdoms\Core\Num;
use SkyKingdoms\Core\Url;
?>
<div class="sk-card">
    <h2>Zahlen</h2>
    <div class="sk-stat-grid" style="display:grid;grid-template-columns:repeat(auto-fit,minmax(130px,1fr));gap:10px">
        <div class="sk-stat"><div class="sk-stat__label">Spieler</div><div class="sk-stat__value"><?= e(Num::full($players)) ?></div></div>
        <div class="sk-stat"><div class="sk-stat__label">Heute aktiv</div><div class="sk-stat__value"><?= e(Num::full($active)) ?></div></div>
        <div class="sk-stat"><div class="sk-stat__label">Neu (24 h)</div><div class="sk-stat__value"><?= e(Num::full($newToday)) ?></div></div>
        <div class="sk-stat"><div class="sk-stat__label">Gesperrt</div><div class="sk-stat__value"><?= e(Num::full($banned)) ?></div></div>
        <div class="sk-stat"><div class="sk-stat__label">Dateien</div><div class="sk-stat__value"><?= e(Num::full($storage['files'])) ?></div></div>
        <div class="sk-stat"><div class="sk-stat__label">Belegt</div><div class="sk-stat__value"><?= e(Num::compact($storage['bytes'] / 1048576)) ?> MB</div></div>
    </div>
</div>

<div class="sk-card" style="margin-top:16px">
    <h2>Schnellzugriff</h2>
    <div class="sk-row">
        <a class="sk-btn sk-btn--small" href="<?= e(Url::to('admin/?p=players')) ?>">Spieler suchen</a>
        <a class="sk-btn sk-btn--small" href="<?= e(Url::to('admin/?p=announce')) ?>">Ankündigung</a>
        <a class="sk-btn sk-btn--small sk-btn--gold" href="<?= e(Url::to('admin/?p=backup')) ?>">Sicherung herunterladen</a>
        <form method="post" action="<?= e(Url::to('admin/?p=ranking')) ?>" style="display:inline">
            <?= Csrf::field() ?><input type="hidden" name="action" value="ranking">
            <button class="sk-btn sk-btn--small" type="submit">Rangliste neu berechnen</button>
        </form>
    </div>
</div>

<div class="sk-card" style="margin-top:16px">
    <h2>Stärkste Königreiche</h2>
    <table class="sk-table sk-table--cards">
        <thead><tr><th>#</th><th>Spieler</th><th>Punkte</th><th>Stufe</th></tr></thead>
        <tbody>
        <?php foreach ($top as $index => $entry): ?>
            <tr>
                <td data-label="Platz"><?= e((string) ($index + 1)) ?></td>
                <td data-label="Spieler"><a href="<?= e(Url::to('admin/?p=player&id=' . $entry['id'])) ?>"><?= e($entry['name']) ?></a></td>
                <td data-label="Punkte"><?= e(Num::full((int) $entry['score'])) ?></td>
                <td data-label="Stufe"><?= e((string) $entry['level']) ?></td>
            </tr>
        <?php endforeach; ?>
        <?php if ($top === []): ?><tr><td colspan="4" class="sk-muted">Noch keine Spieler.</td></tr><?php endif; ?>
        </tbody>
    </table>
</div>

<div class="sk-card" style="margin-top:16px">
    <h2>Letzte Vorgänge</h2>
    <table class="sk-table sk-table--cards">
        <thead><tr><th>Zeit</th><th>Aktion</th><th>Beschreibung</th></tr></thead>
        <tbody>
        <?php foreach ($audit as $entry): ?>
            <tr>
                <td data-label="Zeit"><?= e(date('H:i:s', (int) $entry['ts'])) ?></td>
                <td data-label="Aktion"><code><?= e((string) $entry['action']) ?></code></td>
                <td data-label="Beschreibung"><?= e((string) $entry['message']) ?></td>
            </tr>
        <?php endforeach; ?>
        <?php if ($audit === []): ?><tr><td colspan="3" class="sk-muted">Noch keine Einträge heute.</td></tr><?php endif; ?>
        </tbody>
    </table>
    <p><a href="<?= e(Url::to('admin/?p=logs')) ?>">Alle Protokolle ansehen</a></p>
</div>
