<?php
declare(strict_types=1);

header('Content-Type: text/html; charset=utf-8');
header('X-Content-Type-Options: nosniff');
header('Referrer-Policy: same-origin');
header('X-Frame-Options: SAMEORIGIN');
header("Content-Security-Policy: default-src 'self'; img-src 'self' data:; style-src 'self' 'unsafe-inline'; script-src 'self'; connect-src 'self'; font-src 'self'; base-uri 'self'; form-action 'self'; frame-ancestors 'self'");

$v = (string) @filemtime(__DIR__ . '/assets/app.js') . @filemtime(__DIR__ . '/assets/app.css');
?><!doctype html>
<html lang="de-CH">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover, interactive-widget=resizes-content">
  <meta name="theme-color" content="#7b3cff">
  <meta name="description" content="Gleiche Hirnzelle – das alberne Gedankenlese-Spiel für genau zwei Personen.">
  <meta property="og:title" content="Gleiche Hirnzelle 🧠">
  <meta property="og:description" content="Teilt ihr euch eine Hirnzelle? Spielt es zu zweit heraus!">
  <title>Gleiche Hirnzelle</title>
  <link rel="icon" href="assets/icon.svg" type="image/svg+xml">
  <link rel="preload" href="assets/fonts/fredoka-latin-wght-normal.woff2" as="font" type="font/woff2" crossorigin>
  <link rel="stylesheet" href="assets/app.css?v=<?= htmlspecialchars($v, ENT_QUOTES) ?>">
</head>
<body>
  <div class="bg" aria-hidden="true">
    <span class="blob b1"></span><span class="blob b2"></span><span class="blob b3"></span><span class="blob b4"></span>
  </div>

  <div class="netbar" id="netbar" role="status" aria-live="polite" hidden>
    <span class="netbar-dot"></span><span id="netbar-text">Verbindung unterbrochen – ich versuche es weiter …</span>
  </div>

  <main id="app" class="app" aria-live="polite">
    <div class="screen center">
      <div class="brain-loader"><span>🧠</span></div>
      <noscript><p class="card">Bitte aktiviere JavaScript, um zu spielen.</p></noscript>
    </div>
  </main>

  <div class="toast" id="toast" role="alert" hidden></div>
  <canvas id="confetti" class="confetti" aria-hidden="true"></canvas>

  <script src="assets/qrcode.js?v=<?= htmlspecialchars($v, ENT_QUOTES) ?>"></script>
  <script src="assets/app.js?v=<?= htmlspecialchars($v, ENT_QUOTES) ?>"></script>
</body>
</html>
