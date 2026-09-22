<?php
// YouGBT – App-Shell. Alle URLs sind relativ zum tatsächlichen Installationsordner.
declare(strict_types=1);
require __DIR__ . '/lib/bootstrap.php';
yg_security_headers();
header('Content-Type: text/html; charset=utf-8');
header('Cache-Control: no-cache');
$base = htmlspecialchars(yg_base_path(), ENT_QUOTES, 'UTF-8');
$v = YG_VERSION;
$configured = yg_is_configured();
?><!doctype html>
<html lang="de" data-theme="dark">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
<meta name="color-scheme" content="dark light">
<meta name="theme-color" content="#0b0620">
<base href="<?= $base ?>">
<title>YouGBT</title>
<link rel="icon" href="assets/icon.svg" type="image/svg+xml">
<link rel="stylesheet" href="assets/app.css?v=<?= $v ?>">
</head>
<body data-configured="<?= $configured ? '1' : '0' ?>">
<div class="bg" aria-hidden="true">
  <div class="bg-layer bg-grid" data-depth="0.15"></div>
  <div class="bg-layer" data-depth="0.35"><span class="orb o1"></span><span class="orb o2"></span></div>
  <div class="bg-layer" data-depth="0.6"><span class="orb o3"></span><span class="orb o4"></span></div>
  <div class="bg-layer stars" data-depth="0.8"></div>
</div>
<header class="topbar">
  <button class="brand" id="brand" type="button" aria-label="YouGBT Home">
    <span class="logo-mark">Y</span><span class="logo-text">You<b>GBT</b></span>
  </button>
  <div class="top-actions">
    <button class="icon-btn" id="btn-lang" type="button" aria-label="Sprache / Language">DE</button>
    <button class="icon-btn" id="btn-motion" type="button" aria-label="Bewegung reduzieren">✦</button>
    <button class="icon-btn" id="btn-theme" type="button" aria-label="Hell/Dunkel">☾</button>
  </div>
</header>
<main id="app" class="app" aria-live="polite"></main>
<div id="toast" class="toast" role="status" aria-live="polite"></div>
<div id="modal-root"></div>
<canvas id="fx" aria-hidden="true"></canvas>
<noscript><p class="noscript">YouGBT benötigt JavaScript.</p></noscript>
<script src="assets/i18n.js?v=<?= $v ?>"></script>
<script src="assets/app.js?v=<?= $v ?>"></script>
</body>
</html>
