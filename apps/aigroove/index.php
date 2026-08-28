<?php
/**
 * AI Groove – Anwendungs-Shell.
 *
 * Die Datei liefert ausschliesslich statisches HTML aus und setzt Sicherheits-Header.
 * Es gibt keine Datenbank, keine Sessions und keine Benutzerkonten.
 */

declare(strict_types=1);

// Version fuer Cache-Busting. Bei jedem Deployment erhoehen.
const AIG_VERSION = '1.1.1';

$v = AIG_VERSION;

// --- Sicherheits-Header ------------------------------------------------------
// script-src bewusst ohne 'unsafe-inline' und ohne 'unsafe-eval':
// die Anwendung enthaelt kein einziges Inline-Script.
$csp = implode('; ', [
    "default-src 'self'",
    "base-uri 'self'",
    "object-src 'none'",
    "frame-ancestors 'none'",
    "form-action 'self'",
    "script-src 'self'",
    "worker-src 'self' blob:",
    "child-src 'self' blob:",
    "style-src 'self' 'unsafe-inline'",
    "img-src 'self' data: blob:",
    "media-src 'self' blob: data:",
    "font-src 'self'",
    // Keine fremden Ziele: die KI laeuft lokal, es gibt keine Anfragen nach aussen.
    "connect-src 'self' blob:",
    "manifest-src 'self'",
]);

header('Content-Security-Policy: ' . $csp);
header('X-Content-Type-Options: nosniff');
header('Referrer-Policy: no-referrer');
header('X-Frame-Options: DENY');
header('Permissions-Policy: microphone=(self), camera=(), geolocation=(), payment=(), usb=()');
header('Cross-Origin-Opener-Policy: same-origin');
header('Cache-Control: no-cache, must-revalidate');
header('Content-Type: text/html; charset=utf-8');

/** Relativer Pfad zur Anwendung (funktioniert im Webroot und im Unterordner). */
$base = rtrim(str_replace('\\', '/', dirname($_SERVER['SCRIPT_NAME'] ?? '/')), '/') . '/';
if ($base === '//') {
    $base = '/';
}
?>
<!DOCTYPE html>
<html lang="de" data-theme="dark">
<head>
<meta charset="utf-8">
<title>AI Groove — Musikproduktion im Browser</title>
<meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover, user-scalable=no, maximum-scale=1">
<meta name="description" content="AI Groove: Launchpad, Step Sequencer, Piano Roll, Arrangement, Mixer und KI-Sample-Generator – direkt im Browser.">
<meta name="theme-color" content="#0b0d14" media="(prefers-color-scheme: dark)">
<meta name="theme-color" content="#f4f5fb" media="(prefers-color-scheme: light)">
<meta name="color-scheme" content="dark light">
<meta name="mobile-web-app-capable" content="yes">
<meta name="apple-mobile-web-app-capable" content="yes">
<meta name="apple-mobile-web-app-status-bar-style" content="black-translucent">
<meta name="apple-mobile-web-app-title" content="AI Groove">
<meta name="format-detection" content="telephone=no">
<base href="<?= htmlspecialchars($base, ENT_QUOTES, 'UTF-8') ?>">
<link rel="manifest" href="manifest.webmanifest">
<link rel="icon" href="assets/icons/icon.svg" type="image/svg+xml">
<link rel="icon" href="assets/icons/favicon-32.png" sizes="32x32" type="image/png">
<link rel="icon" href="assets/icons/icon-192.png" sizes="192x192" type="image/png">
<link rel="apple-touch-icon" href="assets/icons/apple-touch-icon.png">
<link rel="stylesheet" href="assets/css/tokens.css?v=<?= $v ?>">
<link rel="stylesheet" href="assets/css/base.css?v=<?= $v ?>">
<link rel="stylesheet" href="assets/css/components.css?v=<?= $v ?>">
<link rel="stylesheet" href="assets/css/dashboard.css?v=<?= $v ?>">
<link rel="stylesheet" href="assets/css/studio.css?v=<?= $v ?>">
<link rel="stylesheet" href="assets/css/responsive.css?v=<?= $v ?>">
</head>
<body>
<noscript>
    <div class="noscript">
        <h1>AI Groove benötigt JavaScript</h1>
        <p>Bitte aktiviere JavaScript in deinem Browser, um AI Groove zu verwenden.</p>
    </div>
</noscript>

<div id="boot" class="boot">
    <div class="boot__mark" aria-hidden="true"></div>
    <p class="boot__text">AI&nbsp;Groove wird geladen …</p>
</div>

<div id="app" class="app" hidden></div>

<div id="overlay-root" class="overlay-root"></div>
<div id="toast-root" class="toast-root" role="status" aria-live="polite"></div>

<script type="module" src="assets/js/main.js?v=<?= $v ?>"></script>
</body>
</html>
