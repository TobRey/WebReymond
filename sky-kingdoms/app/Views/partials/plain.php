<?php
/**
 * Layout für Seiten ohne Spielfläche: Anmeldung, Registrierung, Installer,
 * Adminbereich. Himmel als Hintergrund, Inhalt als Karte darüber.
 *
 * @var string $content
 * @var string $title
 * @var bool   $wide
 */

use SkyKingdoms\Core\App;
use SkyKingdoms\Core\Security;
use SkyKingdoms\Core\Session;
use SkyKingdoms\Core\Url;

$gameName = (string) App::config('name', 'Sky Kingdoms');
$theme    = (array) App::config('theme', []);
$nonce    = Security::nonce();
$flash    = Session::takeFlash();
$wide     = $wide ?? false;
?>
<!doctype html>
<html lang="de">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
<meta name="theme-color" content="<?= e($theme['sky_top'] ?? '#5ec6ff') ?>">
<meta name="color-scheme" content="light">
<title><?= e($title ?? '') ?> – <?= e($gameName) ?></title>
<link rel="icon" href="<?= e(Url::asset('img/logo.svg')) ?>" type="image/svg+xml">
<link rel="apple-touch-icon" href="<?= e(Url::asset('icons/icon-192.png')) ?>">
<link rel="manifest" href="<?= e(Url::to('manifest.php')) ?>">
<link rel="stylesheet" href="<?= e(Url::asset('css/app.css')) ?>">
<style nonce="<?= e($nonce) ?>">
:root{
--sk-primary:<?= e($theme['primary'] ?? '#3f8cff') ?>;
--sk-secondary:<?= e($theme['secondary'] ?? '#ffb43f') ?>;
--sk-accent:<?= e($theme['accent'] ?? '#7ee3a6') ?>;
--sk-danger:<?= e($theme['danger'] ?? '#ff5c6c') ?>;
--sk-sky-top:<?= e($theme['sky_top'] ?? '#5ec6ff') ?>;
--sk-sky-bottom:<?= e($theme['sky_bottom'] ?? '#b9e9ff') ?>;
}
</style>
</head>
<body class="sk-sky<?= $wide ? ' sk-sky--wide' : '' ?>">
<canvas class="sk-sky__canvas" id="sk-sky-canvas" aria-hidden="true"></canvas>

<div class="sk-sky__content">
    <?php foreach ($flash as $item): ?>
        <div class="sk-alert sk-alert--<?= e($item['type'] === 'error' ? 'error' : ($item['type'] === 'ok' ? 'ok' : 'info')) ?>">
            <span><?= e($item['message']) ?></span>
        </div>
    <?php endforeach; ?>

    <?= $content ?>

    <p class="sk-foot"><?= e($gameName) ?> · Version <?= e(SK_VERSION) ?></p>
</div>

<script src="<?= e(Url::asset('js/sky.js')) ?>" nonce="<?= e($nonce) ?>" defer></script>
</body>
</html>
