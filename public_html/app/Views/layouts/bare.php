<?php
/**
 * Nackter Rahmen ohne Navigation – für die Anmeldung.
 *
 * Bewusst ohne Logo und ohne Firmennamen: Wer die Adresse zufällig findet,
 * soll nicht sofort wissen, wozu sie gehört.
 */

use WebAtze\Core\Assets;

/** @var string $content */
/** @var string $nonce */

$title = $title ?? 'Anmelden';
?>
<!doctype html>
<html lang="de" class="no-js">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex, nofollow, noarchive">
    <meta name="referrer" content="no-referrer">
    <title><?= e($title) ?></title>
    <meta name="theme-color" content="#06060f">
    <link rel="icon" href="data:,">
    <link rel="stylesheet" href="<?= e(Assets::url('admin.css')) ?>">
    <script nonce="<?= e($nonce) ?>">document.documentElement.classList.remove('no-js');</script>
</head>
<body class="wa-bare">
    <main class="wa-bare__main">
        <?= $content ?>
    </main>
</body>
</html>
