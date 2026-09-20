<?php
/** @var array $result */
use SkyKingdoms\Core\Url;

$check = (array) ($result['selfcheck'] ?? []);
?>
<div class="sk-brand">
    <img class="sk-brand__logo" src="<?= e(Url::asset('img/logo.svg')) ?>" alt="">
    <h1 class="sk-brand__name">Geschafft!</h1>
    <p class="sk-brand__tagline"><?= e((string) ($result['name'] ?? 'Sky Kingdoms')) ?> ist einsatzbereit.</p>
</div>

<?= \SkyKingdoms\Core\View::render('partials/steps', ['step' => 5]) ?>

<div class="sk-card">
    <div class="sk-alert sk-alert--ok">
        <span>Die Installation ist abgeschlossen. Der Assistent hat sich selbst gesperrt und kann nicht erneut ausgeführt werden.</span>
    </div>

    <?php if (!empty($check)): ?>
        <?php if (!($check['protected'] ?? true)): ?>
            <div class="sk-alert sk-alert--error"><span><?= e((string) $check['note']) ?></span></div>
        <?php elseif (!($check['tested'] ?? false)): ?>
            <div class="sk-alert sk-alert--warn"><span><?= e((string) $check['note']) ?><br><code><?= e((string) $check['url']) ?></code></span></div>
        <?php else: ?>
            <div class="sk-alert sk-alert--ok"><span><?= e((string) $check['note']) ?></span></div>
        <?php endif; ?>
    <?php endif; ?>

    <h2>Und jetzt?</h2>
    <ol style="padding-left:1.2em;line-height:1.8">
        <li>Melde dich mit <strong><?= e((string) ($result['admin'] ?? '')) ?></strong> an.</li>
        <li>Lösche zur Sicherheit den Ordner <code>install/</code> über den cPanel-Dateimanager.</li>
        <li>Im Adminbereich kannst du Spielname, Farben, Balancewerte und E-Mail-Versand anpassen.</li>
    </ol>

    <a class="sk-btn sk-btn--block sk-btn--green" href="<?= e(Url::to('?p=login')) ?>">Zum Spiel</a>
    <p class="sk-center" style="margin-top:12px"><a href="<?= e(Url::to('admin/')) ?>">Zum Adminbereich</a></p>
</div>
