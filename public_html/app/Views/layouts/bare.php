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
/** @var array $flash */

$title = $title ?? 'Anmelden';
?>
<!doctype html>
<html lang="de" class="no-js">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex, nofollow, noarchive">
    <?php /* "same-origin" statt "no-referrer": Die geheime Adresse verlässt
             diese Seite trotzdem nie. "no-referrer" hingegen lässt Browser
             bei jedem Formular "Origin: null" senden – dann scheitert die
             Herkunftsprüfung und keine einzige Schaltfläche funktioniert. */ ?>
    <meta name="referrer" content="same-origin">
    <title><?= e($title) ?></title>
    <meta name="theme-color" content="#06060f">
    <link rel="icon" href="data:,">
    <link rel="stylesheet" href="<?= e(Assets::url('admin.css')) ?>">
    <script nonce="<?= e($nonce) ?>">document.documentElement.classList.remove('no-js');</script>
</head>
<body class="wa-bare">
    <main class="wa-bare__main">
        <?php /* Auch hier müssen Meldungen ankommen. Sonst täte ein
                 abgelehnter Anmeldeversuch scheinbar gar nichts. */ ?>
        <?php foreach ($flash ?? [] as $message): ?>
            <div class="wa-note wa-note--<?= e($message['type'] === 'error' ? 'danger' : $message['type']) ?>"
                 role="alert"><div><?= e($message['message']) ?></div></div>
        <?php endforeach; ?>

        <?= $content ?>
    </main>
</body>
</html>
