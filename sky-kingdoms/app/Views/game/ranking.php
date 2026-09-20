<?php
/** @var array $data @var string $me */
use SkyKingdoms\Core\Num;
use SkyKingdoms\Core\Url;
use SkyKingdoms\Core\View;
?>
<?= View::render('partials/brand', ['subtitle' => 'Rangliste']) ?>

<div class="sk-card">
    <p class="sk-muted">Stand: <?= e(date('d.m.Y H:i', (int) $data['ts'])) ?> · <?= e(Num::full((int) $data['total'])) ?> Königreiche</p>

    <table class="sk-table sk-table--cards">
        <thead><tr><th>Platz</th><th>Spieler</th><th>Punkte</th><th>Stufe</th><th>Inseln</th></tr></thead>
        <tbody>
        <?php foreach ($data['entries'] as $entry): ?>
            <tr<?= $entry['id'] === $me ? ' style="font-weight:800;background:color-mix(in srgb,var(--sk-secondary) 15%,#fff)"' : '' ?>>
                <td data-label="Platz"><?= e((string) $entry['rank']) ?></td>
                <td data-label="Spieler"><?= e((string) $entry['name']) ?></td>
                <td data-label="Punkte"><?= e(Num::full((int) $entry['score'])) ?></td>
                <td data-label="Stufe"><?= e((string) $entry['level']) ?></td>
                <td data-label="Inseln"><?= e((string) $entry['islands']) ?></td>
            </tr>
        <?php endforeach; ?>
        <?php if ($data['entries'] === []): ?>
            <tr><td colspan="5" class="sk-center sk-muted">Noch keine Einträge.</td></tr>
        <?php endif; ?>
        </tbody>
    </table>

    <div class="sk-row" style="justify-content:space-between;margin-top:16px">
        <?php if ($data['page'] > 1): ?>
            <a class="sk-btn sk-btn--small" href="<?= e(Url::to('?p=rangliste&seite=' . ($data['page'] - 1))) ?>">Zurück</a>
        <?php else: ?><span></span><?php endif; ?>
        <span class="sk-muted">Seite <?= e((string) $data['page']) ?> von <?= e((string) $data['pages']) ?></span>
        <?php if ($data['page'] < $data['pages']): ?>
            <a class="sk-btn sk-btn--small" href="<?= e(Url::to('?p=rangliste&seite=' . ($data['page'] + 1))) ?>">Weiter</a>
        <?php else: ?><span></span><?php endif; ?>
    </div>

    <a class="sk-btn sk-btn--block" style="margin-top:16px" href="<?= e(Url::to('?p=game')) ?>">Zum Königreich</a>
</div>
