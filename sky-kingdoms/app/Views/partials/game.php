<?php
/**
 * Grundgerüst der Spieloberfläche.
 * Alles Weitere baut das JavaScript aus den API-Daten auf.
 *
 * @var string $content
 * @var array  $user
 */

use SkyKingdoms\Core\App;
use SkyKingdoms\Core\Csrf;
use SkyKingdoms\Core\Security;
use SkyKingdoms\Core\Url;
use SkyKingdoms\Http\Controllers\GameController;

$gameName = (string) App::config('name', 'Sky Kingdoms');
$theme    = (array) App::config('theme', []);
$nonce    = Security::nonce();
$boot     = GameController::bootData($user);
$boot['csrf'] = Csrf::token();
$boot['base'] = Url::to('');
$boot['api']  = Url::to('api/');
$boot['assets'] = Url::to('assets/');
$boot['v']    = Url::assetVersion();
?>
<!doctype html>
<html lang="de">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1, maximum-scale=1, user-scalable=no, viewport-fit=cover">
<meta name="theme-color" content="#0b1424">
<meta name="color-scheme" content="dark">
<meta name="mobile-web-app-capable" content="yes">
<meta name="apple-mobile-web-app-capable" content="yes">
<meta name="apple-mobile-web-app-status-bar-style" content="black-translucent">
<title><?= e($gameName) ?></title>
<link rel="icon" href="<?= e(Url::asset('img/logo.svg')) ?>" type="image/svg+xml">
<link rel="apple-touch-icon" href="<?= e(Url::asset('icons/icon-192.png')) ?>">
<link rel="manifest" href="<?= e(Url::to('manifest.php')) ?>">
<link rel="stylesheet" href="<?= e(Url::asset('css/app.css')) ?>">
<link rel="stylesheet" href="<?= e(Url::asset('css/game.css')) ?>">
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
<body class="sk-game">

<div class="sk-stage">
    <canvas id="sk-canvas" aria-label="Spielwelt"></canvas>
</div>

<?= $content ?>

<div class="sk-loader" id="sk-loader">
    <div class="sk-loader__inner">
        <img class="sk-loader__logo" src="<?= e(Url::asset('img/logo.svg')) ?>" alt="">
        <div style="font-family:var(--sk-font-display);font-size:1.4rem;font-weight:800"><?= e($gameName) ?></div>
        <div class="sk-loader__bar"><div class="sk-loader__fill" id="sk-loader-fill"></div></div>
        <div class="sk-loader__text" id="sk-loader-text">Königreich wird geladen …</div>
    </div>
</div>

<script nonce="<?= e($nonce) ?>">
window.SK_BOOT = <?= json_encode($boot, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_AMP) ?>;
</script>
<script src="<?= e(Url::asset('js/app.js')) ?>" nonce="<?= e($nonce) ?>" type="module"></script>
</body>
</html>
