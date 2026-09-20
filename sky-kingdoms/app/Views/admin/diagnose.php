<?php
/** @var array $report @var array $storage @var array $check */
use SkyKingdoms\Core\Num;
use SkyKingdoms\Core\Url;
?>
<div class="sk-card">
    <h2>Systemdiagnose</h2>

    <?php if (!($check['protected'] ?? true)): ?>
        <div class="sk-alert sk-alert--error"><span><?= e((string) $check['note']) ?></span></div>
    <?php elseif (!($check['tested'] ?? false)): ?>
        <div class="sk-alert sk-alert--warn"><span><?= e((string) $check['note']) ?><br>
            <code><?= e((string) $check['url']) ?></code></span></div>
    <?php else: ?>
        <div class="sk-alert sk-alert--ok"><span><?= e((string) $check['note']) ?></span></div>
    <?php endif; ?>

    <?php foreach ($report['groups'] as $group => $checks): ?>
        <div class="sk-group-title"><?= e($group) ?></div>
        <ul class="sk-check-list">
            <?php foreach ($checks as $item): ?>
                <li>
                    <span class="sk-check-list__icon <?= $item['ok'] ? 'is-ok' : ($item['optional'] ? 'is-warn' : 'is-bad') ?>">
                        <?= $item['ok'] ? '✓' : ($item['optional'] ? '!' : '×') ?></span>
                    <span class="sk-grow">
                        <span class="sk-check-list__label"><?= e($item['label']) ?></span>
                        <span class="sk-check-list__value"><?= e($item['value']) ?></span>
                        <?php if (!$item['ok']): ?><span class="sk-check-list__hint"><?= e($item['hint']) ?></span><?php endif; ?>
                    </span>
                </li>
            <?php endforeach; ?>
        </ul>
    <?php endforeach; ?>
</div>

<div class="sk-card" style="margin-top:16px">
    <h2>Datenablage</h2>
    <table class="sk-table sk-table--cards">
        <tbody>
        <tr><td data-label="Ordner"><strong>Ordner</strong></td><td data-label=" "><code><?= e((string) $storage['path']) ?></code></td></tr>
        <tr><td data-label="Dateien"><strong>Dateien</strong></td><td data-label=" "><?= e(Num::full((int) $storage['files'])) ?></td></tr>
        <tr><td data-label="Belegt"><strong>Belegt</strong></td><td data-label=" "><?= e(Num::compact($storage['bytes'] / 1048576)) ?> MB</td></tr>
        <tr><td data-label="Frei"><strong>Frei auf dem Datenträger</strong></td><td data-label=" "><?= e(Num::compact($storage['free'] / 1073741824)) ?> GB</td></tr>
        <tr><td data-label="PHP"><strong>PHP-Version</strong></td><td data-label=" "><?= e(PHP_VERSION) ?></td></tr>
        <tr><td data-label="Limit"><strong>Speicherlimit</strong></td><td data-label=" "><?= e((string) ini_get('memory_limit')) ?></td></tr>
        <tr><td data-label="Zeit"><strong>Maximale Laufzeit</strong></td><td data-label=" "><?= e((string) ini_get('max_execution_time')) ?> s</td></tr>
        </tbody>
    </table>

    <a class="sk-btn sk-btn--block sk-btn--gold" style="margin-top:14px" href="<?= e(Url::to('admin/?p=backup')) ?>">
        Sicherung aller Spielstände herunterladen
    </a>
    <p class="sk-field__hint">Die Sicherung enthält alle Konten und Königreiche als ZIP.
        Bewahre sie sicher auf – sie enthält personenbezogene Daten.</p>
</div>
