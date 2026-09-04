<?php
/**
 * Rahmen des Adminbereichs.
 *
 * Bewusst dieselben Design-Tokens wie die öffentliche Website – der
 * Verwaltungsbereich soll sich wie ein Teil davon anfühlen, nicht wie
 * ein fremdes Werkzeug.
 */

use WebAtze\Core\{Assets, Config, Csrf};

/** @var string $content */
/** @var string $nonce */
/** @var array|null $authUser */
/** @var array $flash */
/** @var string $currentPath */

$title = $title ?? 'Verwaltung';
$base = '/' . trim((string) Config::get('create_path', 'create'), '/');

// Zeichen an den Menuepunkten: eine Zahl je Eintrag, an dem etwas auf
// mich wartet. Zwanzig Menuepunkte einzeln durchzuklicken tut niemand
// jeden Tag - eine Supportfrage lag deshalb schon einmal drei Tage.
$zeichen = \WebAtze\Domain\Notices::all();

// Die Navigation in zwei Bloecken: erst das Tagesgeschaeft mit Kunden,
// dann das Werkzeug fuer die Websites. Wer morgens hereinkommt, will
// wissen, was ansteht - nicht, welche Vorlage es gibt.
$nav = [
    ['/start', 'Übersicht', 'grid'],
    ['/kunden', 'Kunden', 'users'],
    ['/kalender', 'Kalender', 'calendar'],
    ['/kundensuche', 'Kundensuche', 'search'],
    ['/potenzielle-kunden', 'Potenzielle Kunden', 'inbox'],
    ['/buchhaltung', 'Buchhaltung', 'coins'],
    ['/rechnungen', 'Rechnungen', 'tag'],
    ['/vertraege', 'Verträge', 'refresh'],
    ['/mitarbeitende', 'Mitarbeitende', 'user'],
    ['/websites', 'Websites', 'globe'],
    ['/neu', 'Neue Website', 'plus'],
    ['/wartung', 'Wartungscenter', 'shield'],
    ['/passwoerter', 'Passwörter', 'key'],
    ['/anfragen', 'Anfragen', 'inbox'],
    ['/support', 'Support', 'chat'],
    ['/fragebogen', 'Fragebögen', 'document'],
    ['/referenzen', 'Referenzen', 'star'],
    ['/zahlen', 'Besucher', 'chart'],
    ['/kosten', 'Kosten', 'coins'],
    ['/frontend-fix', 'Frontend-Fix', 'wand'],
    ['/intranet', 'Intranet', 'note'],
    ['/protokoll', 'Protokoll', 'list'],
    ['/einstellungen', 'Einstellungen', 'gear'],
];
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
    <title><?= e($title) ?> · Verwaltung</title>
    <meta name="theme-color" content="#06060f">
    <link rel="icon" href="/assets/img/favicon.svg" type="image/svg+xml">
    <link rel="stylesheet" href="<?= e(Assets::url('admin.css')) ?>">
    <script nonce="<?= e($nonce) ?>">document.documentElement.classList.remove('no-js');</script>
    <script type="module" src="<?= e(Assets::url('admin.js')) ?>" defer></script>
    <script nonce="<?= e($nonce) ?>">window.WA_CSRF = <?= json_out(Csrf::token()) ?>;</script>
</head>
<body class="wa-admin">

<a class="wa-skip" href="#admin-inhalt">Direkt zum Inhalt</a>

<?php /*
    Das Menue auf dem Handy haengt bewusst nicht an JavaScript. Ein
    Verwaltungsbereich, dessen Navigation verschwindet, sobald ein
    Skript nicht laedt - zwischengespeicherte alte Datei, blockierte
    Adresse, abgeschaltetes JavaScript - ist unbedienbar. Deshalb
    schaltet ein Kaestchen die Leiste, rein ueber CSS. Das Skript
    ergaenzt nur noch das Verdunkeln und die Escape-Taste.
*/ ?>
<input type="checkbox" id="wa-admin-nav" class="wa-admin__nav-state" aria-label="Menue anzeigen">

<aside class="wa-admin__side">
    <a class="wa-admin__brand" href="<?= e($base) ?>/start">
        <?= View_partial('partials/logo', ['size' => 1.8, 'markOnly' => true]) ?>
        <span>WebAtze</span>
    </a>

    <nav class="wa-admin__nav" aria-label="Verwaltung">
        <?php foreach ($nav as [$path, $label, $icon]): ?>
            <?php $href = $base . $path; ?>
            <?php
                /* Nicht nur str_starts_with: '/create/kundensuche' beginnt
                   mit '/create/kunden', und dann leuchteten beide Eintraege.
                   Es zaehlt der Eintrag selbst oder ein Pfad darunter. */
                $hier = $currentPath === $href || str_starts_with($currentPath, $href . '/');
            ?>
            <?php $meldung = $zeichen[$path] ?? null; ?>
            <a class="wa-admin__link" href="<?= e($href) ?>"
               <?= $hier ? 'aria-current="page"' : '' ?>>
                <?= View_partial('partials/admin-icons', ['name' => $icon]) ?>
                <span><?= e($label) ?></span>
                <?php if ($meldung !== null): ?>
                    <?php /* Die Zahl allein sagt einem Screenreader nichts -
                             deshalb steht daneben, worum es geht. */ ?>
                    <span class="wa-admin__zeichen<?= $meldung['dringend'] ? ' wa-admin__zeichen--dringend' : '' ?>"
                          aria-hidden="true"><?= (int) $meldung['anzahl'] > 99 ? '99+' : (int) $meldung['anzahl'] ?></span>
                    <span class="wa-sr"><?= (int) $meldung['anzahl'] ?> <?= e($meldung['was']) ?></span>
                <?php endif; ?>
            </a>
        <?php endforeach; ?>
    </nav>

    <div class="wa-admin__side-foot">
        <a class="wa-admin__link" href="/" target="_blank" rel="noopener">
            <?= View_partial('partials/admin-icons', ['name' => 'external']) ?>
            <span>Website ansehen</span>
        </a>
        <form method="post" action="<?= e($base) ?>/abmelden">
            <?= Csrf::field() ?>
            <button type="submit" class="wa-admin__link wa-admin__link--button">
                <?= View_partial('partials/admin-icons', ['name' => 'logout']) ?>
                <span>Abmelden<?= $authUser ? ' (' . e($authUser['username']) . ')' : '' ?></span>
            </button>
        </form>
    </div>
</aside>

<div class="wa-admin__main">
    <header class="wa-admin__topbar">
        <label for="wa-admin-nav" class="wa-burger wa-admin__burger" data-admin-nav-toggle>
            <span></span><span></span><span></span>
        </label>
        <h1 class="wa-admin__title"><?= e($title) ?></h1>
        <?php if (!empty($headerActions)): ?>
            <div class="wa-admin__actions"><?= $headerActions ?></div>
        <?php endif; ?>
    </header>

    <main id="admin-inhalt" class="wa-admin__content">
        <?php foreach ($flash as $message): ?>
            <div class="wa-note wa-note--<?= e($message['type'] === 'error' ? 'danger' : $message['type']) ?>" role="status">
                <div><?= e($message['message']) ?></div>
            </div>
        <?php endforeach; ?>

        <?= $content ?>
    </main>
</div>

</body>
</html>
