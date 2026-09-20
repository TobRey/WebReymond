<?php
/** @var array $quests @var array $achievements */
use SkyKingdoms\Core\Csrf;
use SkyKingdoms\Core\Url;
?>
<div class="sk-card">
    <h2>Aufgaben</h2>
    <p class="sk-muted">Gespeichert in <code>storage/data/meta/quests.json</code>.
        Zum Anpassen die Datei bearbeiten oder hier auf die Vorgaben zurücksetzen.</p>

    <table class="sk-table sk-table--cards">
        <thead><tr><th>Kennung</th><th>Art</th><th>Name</th><th>Ziel</th><th>Belohnung</th></tr></thead>
        <tbody>
        <?php foreach ($quests as $quest): ?>
            <tr>
                <td data-label="Kennung"><code><?= e((string) $quest['id']) ?></code></td>
                <td data-label="Art"><?= e(($quest['type'] ?? '') === 'daily' ? 'täglich' : 'langfristig') ?></td>
                <td data-label="Name"><?= e((string) $quest['name']) ?></td>
                <td data-label="Ziel"><?= e((string) $quest['metric']) ?> ≥ <?= e((string) $quest['goal']) ?></td>
                <td data-label="Belohnung"><?= e(json_encode($quest['reward'] ?? [], JSON_UNESCAPED_UNICODE)) ?></td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>

    <h2 style="margin-top:20px">Erfolge</h2>
    <table class="sk-table sk-table--cards">
        <thead><tr><th>Kennung</th><th>Name</th><th>Ziel</th></tr></thead>
        <tbody>
        <?php foreach ($achievements as $achievement): ?>
            <tr>
                <td data-label="Kennung"><code><?= e((string) $achievement['id']) ?></code></td>
                <td data-label="Name"><?= e((string) $achievement['name']) ?></td>
                <td data-label="Ziel"><?= e((string) $achievement['metric']) ?> ≥ <?= e((string) $achievement['goal']) ?></td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>

    <form method="post" action="<?= e(Url::to('admin/?p=quests')) ?>" style="margin-top:16px"
          onsubmit="return confirm('Aufgaben und Erfolge auf die Vorgaben zurücksetzen?')">
        <?= Csrf::field() ?>
        <input type="hidden" name="action" value="quests_reset">
        <button class="sk-btn sk-btn--small sk-btn--danger" type="submit">Auf Vorgaben zurücksetzen</button>
    </form>
</div>
