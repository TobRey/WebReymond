<?php
/** @var array $ranking */
use SkyKingdoms\Core\Csrf;
use SkyKingdoms\Core\Num;
use SkyKingdoms\Core\Url;
?>
<div class="sk-card">
    <h2>Rangliste</h2>
    <p class="sk-muted">Momentaufnahme vom
        <?= e($ranking['ts'] ? date('d.m.Y H:i:s', (int) $ranking['ts']) : 'noch nie') ?> ·
        <?= e(Num::full((int) ($ranking['total'] ?? 0))) ?> Königreiche</p>

    <form method="post" action="<?= e(Url::to('admin/?p=ranking')) ?>" style="margin-bottom:14px">
        <?= Csrf::field() ?>
        <input type="hidden" name="action" value="ranking">
        <button class="sk-btn sk-btn--small" type="submit">Jetzt neu berechnen</button>
    </form>

    <table class="sk-table sk-table--cards">
        <thead><tr><th>#</th><th>Spieler</th><th>Punkte</th><th>Stufe</th><th>Inseln</th></tr></thead>
        <tbody>
        <?php foreach (array_slice((array) ($ranking['entries'] ?? []), 0, 100) as $index => $entry): ?>
            <tr>
                <td data-label="Platz"><?= e((string) ($index + 1)) ?></td>
                <td data-label="Spieler"><a href="<?= e(Url::to('admin/?p=player&id=' . $entry['id'])) ?>"><?= e((string) $entry['name']) ?></a></td>
                <td data-label="Punkte"><?= e(Num::full((int) $entry['score'])) ?></td>
                <td data-label="Stufe"><?= e((string) $entry['level']) ?></td>
                <td data-label="Inseln"><?= e((string) ($entry['islands'] ?? 0)) ?></td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
</div>
