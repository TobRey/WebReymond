<?php
/** @var array $entries @var int $page @var int $pages @var int $total */
use WebAtze\Core\Config;
$base = '/' . trim((string) Config::get('create_path', 'create'), '/');
?>
<section class="wa-panel">
    <div class="wa-panel__head">
        <h2 class="wa-panel__title">Protokoll</h2>
        <p class="wa-panel__hint">
            Jede Verwaltungsaktion hinterlässt eine Zeile: wer, was, wann, woher.
            <?= number_format($total) ?> Einträge.
        </p>
    </div>

    <?php if ($entries === []): ?>
        <div class="wa-empty-state"><p>Noch nichts protokolliert.</p></div>
    <?php else: ?>
        <div class="wa-table-wrap">
            <table class="wa-table">
                <thead><tr><th>Zeitpunkt</th><th>Wer</th><th>Was</th><th>Betrifft</th><th>Herkunft</th></tr></thead>
                <tbody>
                <?php foreach ($entries as $entry): ?>
                    <tr>
                        <td><?= e(date('d.m.Y H:i:s', strtotime((string) $entry['created_at']))) ?></td>
                        <td><?= e((string) $entry['actor']) ?></td>
                        <td><code><?= e((string) $entry['action']) ?></code></td>
                        <td class="is-wide"><?= e((string) $entry['subject']) ?></td>
                        <td><?= e((string) $entry['ip']) ?></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>

        <?php if ($pages > 1): ?>
            <div class="wa-row">
                <?php if ($page > 1): ?>
                    <a class="wa-btn wa-btn--sm" href="<?= e($base) ?>/protokoll?seite=<?= $page - 1 ?>">Neuere</a>
                <?php endif; ?>
                <span class="wa-panel__hint">Seite <?= $page ?> von <?= $pages ?></span>
                <?php if ($page < $pages): ?>
                    <a class="wa-btn wa-btn--sm" href="<?= e($base) ?>/protokoll?seite=<?= $page + 1 ?>">Ältere</a>
                <?php endif; ?>
            </div>
        <?php endif; ?>
    <?php endif; ?>
</section>
