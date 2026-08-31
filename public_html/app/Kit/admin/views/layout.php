<?php defined('WEBATZE') || exit; ?>
<?php
/**
 * Der Rahmen des Bearbeitungsbereichs.
 *
 * Die Farben kommen aus dem Theme der Kundenwebsite – der
 * Bearbeitungsbereich sieht deshalb bei jedem Kunden anders aus und
 * passt jedes Mal zur eigenen Seite.
 */

/** @var \WebAtzeKit\Store $store @var \WebAtzeKit\Assistant $assistant */
/** @var string $brand @var string $base @var string $nonce @var array $flash */
/** @var string $view @var string $currentPath @var int $sectionId */

use WebAtzeKit\Csrf;
use WebAtze\Templates\Schema;

$site = $store->all();
$pages = $store->pages();
$colours = $site['theme']['colors'] ?? [];

$leads = \WebAtzeKit\Store::readGuarded(DATA_DIR . '/leads.php');
$unread = 0;
foreach ($leads as $lead) {
    if (empty($lead['read'])) {
        $unread++;
    }
}

$nav = [
    'seiten' => ['Seiten', count($pages)],
    'anfragen' => ['Anfragen', $unread],
];

// Buchungen gibt es nur, wenn die Website welche annimmt. Ein leerer
// Menüpunkt, hinter dem nichts steht, ist schlimmer als keiner.
$setup = is_file(DATA_DIR . '/buchung.php') ? (array) (require DATA_DIR . '/buchung.php') : [];
$bookings = [];

if ($setup !== []) {
    $bookings = \WebAtzeKit\Store::readGuarded(DATA_DIR . '/buchungen.php');

    $offen = 0;
    $heute = date('Y-m-d');

    foreach ($bookings as $booking) {
        if ((string) ($booking['tag'] ?? '') >= $heute
            && (string) ($booking['zustand'] ?? 'offen') !== 'abgesagt') {
            $offen++;
        }
    }

    $nav['buchungen'] = ['Buchungen', $offen];
}

$nav['sicherungen'] = ['Frühere Stände', 0];

/** Die Farben der Kundenwebsite als Variablen für diese Oberfläche. */
$vars = [];
foreach ([
    'primary' => '--k-accent',
    'primary_dark' => '--k-accent-dark',
    'on_primary' => '--k-on-accent',
    'text' => '--k-text',
    'text_muted' => '--k-text-muted',
    'border' => '--k-border',
    'surface' => '--k-surface',
    'bg_soft' => '--k-bg',
] as $key => $var) {
    if (!empty($colours[$key])) {
        $vars[] = $var . ':' . $colours[$key];
    }
}
?>
<!doctype html>
<html lang="<?= e($site['locale'] ?? 'de') ?>">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex, nofollow, noarchive">
    <meta name="referrer" content="same-origin">
    <title><?= e($brand) ?> · Bearbeiten</title>
    <link rel="stylesheet" href="admin.css">
    <?php if ($vars !== []): ?>
        <style><?= ':root{' . e(implode(';', $vars)) . '}' ?></style>
    <?php endif; ?>
</head>
<body class="k-app">

<header class="k-top">
    <span class="k-top__brand"><?= e($brand) ?></span>

    <nav class="k-top__nav">
        <?php foreach ($nav as $key => [$label, $count]): ?>
            <a class="k-top__link<?= $view === $key ? ' is-active' : '' ?>"
               href="?ansicht=<?= e($key) ?>">
                <?= e($label) ?>
                <?php if ($count > 0 && ($key === 'anfragen' || $key === 'buchungen')): ?>
                    <span class="k-dot"><?= (int) $count ?></span>
                <?php endif; ?>
            </a>
        <?php endforeach; ?>
    </nav>

    <div class="k-top__end">
        <a class="k-btn k-btn--quiet k-btn--sm" href="index.html" target="_blank" rel="noopener">
            Website ansehen
        </a>
        <form method="post" action="<?= e($base) ?>">
            <?= Csrf::field() ?>
            <input type="hidden" name="action" value="logout">
            <button class="k-btn k-btn--quiet k-btn--sm" type="submit">Abmelden</button>
        </form>
    </div>
</header>

<main class="k-main">
    <?php foreach ($flash as $message): ?>
        <p class="k-note k-note--<?= e($message['type'] === 'error' ? 'error' : $message['type']) ?>"
           role="status"><?= e($message['message']) ?></p>
    <?php endforeach; ?>

    <?php
    $file = __DIR__ . '/' . basename($view) . '.php';
    require is_file($file) ? $file : __DIR__ . '/seiten.php';
    ?>
</main>

<?php /* Das einzige Skript hier: eine Nachfrage vor Schritten, die etwas
         ersetzen. Es trägt den Nonce aus der Content-Security-Policy –
         ohne den würde es nicht ausgeführt. */ ?>
<script nonce="<?= e($nonce) ?>">
document.addEventListener('submit', function (event) {
  var frage = event.target.getAttribute('data-confirm');
  if (frage && !window.confirm(frage)) { event.preventDefault(); }
});
</script>

</body>
</html>
