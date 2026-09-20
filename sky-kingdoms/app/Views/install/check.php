<?php
/** @var array $report @var bool $ready */
use SkyKingdoms\Core\Url;
?>
<div class="sk-brand">
    <img class="sk-brand__logo" src="<?= e(Url::asset('img/logo.svg')) ?>" alt="">
    <h1 class="sk-brand__name">Sky Kingdoms</h1>
    <p class="sk-brand__tagline">Installation – Schritt 1 von 5</p>
</div>

<?= \SkyKingdoms\Core\View::render('partials/steps', ['step' => 1]) ?>

<div class="sk-card">
    <h2>Systemprüfung</h2>
    <p class="sk-muted">Hier siehst du, ob dieser Webspace alles mitbringt. Sky Kingdoms braucht
        <strong>keine Datenbank</strong> – alle Spielstände liegen als Dateien im Ordner <code>storage/data</code>.</p>

    <?php foreach ($report['groups'] as $group => $checks): ?>
        <div class="sk-group-title"><?= e($group) ?></div>
        <ul class="sk-check-list">
            <?php foreach ($checks as $check): ?>
                <li>
                    <span class="sk-check-list__icon <?= $check['ok'] ? 'is-ok' : ($check['optional'] ? 'is-warn' : 'is-bad') ?>">
                        <?= $check['ok'] ? '✓' : ($check['optional'] ? '!' : '×') ?>
                    </span>
                    <span class="sk-grow">
                        <span class="sk-check-list__label"><?= e($check['label']) ?></span>
                        <span class="sk-check-list__value"><?= e($check['value']) ?></span>
                        <?php if (!$check['ok']): ?>
                            <span class="sk-check-list__hint"><?= e($check['hint']) ?></span>
                        <?php endif; ?>
                    </span>
                </li>
            <?php endforeach; ?>
        </ul>
    <?php endforeach; ?>

    <?php if ($ready): ?>
        <div class="sk-alert sk-alert--ok"><span>Alles bereit. Du kannst mit der Installation beginnen.</span></div>
        <a class="sk-btn sk-btn--block sk-btn--green" href="<?= e(Url::to('install/?step=2')) ?>">Weiter zu den Einstellungen</a>
    <?php else: ?>
        <div class="sk-alert sk-alert--error">
            <span>Einige Punkte sind noch offen. Behebe sie und lade die Seite neu –
                die Hinweise unter den roten Punkten erklären, was zu tun ist.</span>
        </div>
        <a class="sk-btn sk-btn--block" href="<?= e(Url::to('install/?step=1')) ?>">Erneut prüfen</a>
    <?php endif; ?>
</div>
