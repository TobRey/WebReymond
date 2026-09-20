<?php
/** @var array $players @var string $query */
use SkyKingdoms\Core\Num;
use SkyKingdoms\Core\Url;
?>
<div class="sk-card">
    <h2>Spieler</h2>
    <form method="get" action="<?= e(Url::to('admin/')) ?>" class="sk-row" style="margin-bottom:14px">
        <input type="hidden" name="p" value="players">
        <input class="sk-grow" name="q" value="<?= e($query) ?>" placeholder="Name oder Kennung"
               style="min-height:48px;border-radius:12px;border:1.5px solid rgba(16,26,46,.12);padding:0 14px">
        <button class="sk-btn sk-btn--small" type="submit">Suchen</button>
    </form>

    <table class="sk-table sk-table--cards">
        <thead><tr><th>Spieler</th><th>Punkte</th><th>Stufe</th><th>Zuletzt online</th><th>Status</th></tr></thead>
        <tbody>
        <?php foreach ($players as $player): ?>
            <tr>
                <td data-label="Spieler">
                    <a href="<?= e(Url::to('admin/?p=player&id=' . $player['id'])) ?>"><strong><?= e($player['name']) ?></strong></a>
                    <div class="sk-muted" style="font-size:.8rem"><?= e($player['kingdom'] ?? '') ?></div>
                </td>
                <td data-label="Punkte"><?= e(Num::full((int) $player['score'])) ?></td>
                <td data-label="Stufe"><?= e((string) $player['level']) ?></td>
                <td data-label="Zuletzt"><?= e($player['last_seen'] ? date('d.m.Y H:i', (int) $player['last_seen']) : '–') ?></td>
                <td data-label="Status">
                    <?php if (!empty($player['banned'])): ?>
                        <span class="sk-pill sk-pill--red">gesperrt</span>
                    <?php else: ?>
                        <span class="sk-pill sk-pill--green">aktiv</span>
                    <?php endif; ?>
                </td>
            </tr>
        <?php endforeach; ?>
        <?php if ($players === []): ?>
            <tr><td colspan="5" class="sk-muted">Keine Spieler gefunden.</td></tr>
        <?php endif; ?>
        </tbody>
    </table>
</div>
