<?php
/** @var array $settings @var array $admin */
use SkyKingdoms\Core\Csrf;
use SkyKingdoms\Core\Session;
use SkyKingdoms\Core\Url;

$errors = Session::get('install.errors', []);
Session::forget('install.errors');
?>
<div class="sk-brand">
    <img class="sk-brand__logo" src="<?= e(Url::asset('img/logo.svg')) ?>" alt="">
    <h1 class="sk-brand__name">Alles bereit?</h1>
    <p class="sk-brand__tagline">Schritt 4 von 5</p>
</div>

<?= \SkyKingdoms\Core\View::render('partials/steps', ['step' => 4]) ?>

<div class="sk-card">
    <?php if (isset($errors['system'])): ?>
        <div class="sk-alert sk-alert--error"><span><?= e($errors['system']) ?></span></div>
    <?php endif; ?>

    <table class="sk-table sk-table--cards">
        <tbody>
        <tr><td data-label="Spielname"><strong>Spielname</strong></td><td data-label=" "><?= e($settings['name']) ?></td></tr>
        <tr><td data-label="Zeitzone"><strong>Zeitzone</strong></td><td data-label=" "><?= e($settings['timezone']) ?></td></tr>
        <tr><td data-label="Offline"><strong>Offline-Zeitraum</strong></td><td data-label=" "><?= e((string) $settings['offline']) ?> Stunden</td></tr>
        <tr><td data-label="Registrierung"><strong>Registrierung</strong></td><td data-label=" "><?= $settings['open'] === '1' ? 'offen' : 'geschlossen' ?></td></tr>
        <tr><td data-label="Administrator"><strong>Administrator</strong></td><td data-label=" "><?= e($admin['username']) ?></td></tr>
        <tr><td data-label="E-Mail"><strong>E-Mail</strong></td><td data-label=" "><?= e($admin['email']) ?></td></tr>
        <tr><td data-label="Datenablage"><strong>Datenablage</strong></td><td data-label=" ">storage/data (Dateien, keine Datenbank)</td></tr>
        <tr><td data-label="Adresse"><strong>Adresse</strong></td><td data-label=" "><?= e(Url::detectAppUrl()) ?></td></tr>
        </tbody>
    </table>

    <form method="post" action="<?= e(Url::to('install/?step=4')) ?>">
        <?= Csrf::field() ?>
        <button class="sk-btn sk-btn--block sk-btn--green" type="submit">Jetzt installieren</button>
    </form>
    <p class="sk-center" style="margin-top:12px">
        <a href="<?= e(Url::to('install/?step=2')) ?>">Zurück und ändern</a>
    </p>
</div>
