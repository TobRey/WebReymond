<?php
/**
 * CASH – privates Finanzsystem. Liefert die App-Hülle aus; alle Daten kommen über api.php.
 */
declare(strict_types=1);

const CASH_ENTRY = true;
require __DIR__ . '/app/bootstrap.php';

Http::forceHttps();
Http::securityHeaders(true);
header('Content-Type: text/html; charset=utf-8');

$v = rawurlencode(CASH_VERSION . '-' . substr((string)@filemtime(__DIR__ . '/assets/app.js'), -6));
?><!doctype html>
<html lang="de-CH">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
<meta name="robots" content="noindex, nofollow">
<meta name="theme-color" content="#F5F8F6">
<meta name="apple-mobile-web-app-capable" content="yes">
<meta name="mobile-web-app-capable" content="yes">
<meta name="apple-mobile-web-app-status-bar-style" content="default">
<meta name="apple-mobile-web-app-title" content="CASH">
<meta name="format-detection" content="telephone=no">
<title>CASH</title>
<link rel="manifest" href="manifest.webmanifest">
<link rel="icon" href="assets/icon.svg" type="image/svg+xml">
<link rel="apple-touch-icon" href="assets/icon-180.png">
<link rel="stylesheet" href="assets/app.css?v=<?= htmlspecialchars($v, ENT_QUOTES) ?>">
</head>
<body>
<div id="app" aria-live="polite">
  <div class="boot"><div class="boot-logo">CASH</div></div>
</div>
<noscript><p class="noscript">CASH benötigt JavaScript.</p></noscript>
<script src="assets/app.js?v=<?= htmlspecialchars($v, ENT_QUOTES) ?>" defer></script>
</body>
</html>
