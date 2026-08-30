<?php
/**
 * Rahmen aller öffentlichen Seiten.
 *
 * Reihenfolge im Kopf mit Absicht:
 *   1. Zeichensatz und Ansichtsfenster
 *   2. Kritisches CSS direkt eingebettet – die Seite erscheint sofort gestaltet
 *   3. Theme-Entscheidung noch vor dem ersten Zeichnen (kein Aufblitzen)
 *   4. Das grosse CSS und das JavaScript erst danach
 */

use WebAtze\Core\{Assets, Config, I18n, Security, Settings};

/** @var string $content */
/** @var string $locale */
/** @var string $nonce */
/** @var string $currentPath */
/** @var array $siteSettings */

$title = $title ?? __t('meta.tagline');
$description = $description ?? __t('meta.description');
$pageClass = $pageClass ?? '';
$showHero = $showHero ?? false;
$canonical = Config::url(ltrim($currentPath, '/'));
$other = $locale === 'de' ? 'en' : 'de';
?>
<!doctype html>
<html lang="<?= e($locale) ?>" class="no-js">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">

    <title><?= e($title) ?> · WebAtze</title>
    <meta name="description" content="<?= e($description) ?>">
    <meta name="theme-color" content="#06060f">
    <meta name="color-scheme" content="dark light">

    <link rel="canonical" href="<?= e($canonical) ?>">
    <link rel="alternate" hreflang="de" href="<?= e(Config::url(ltrim(I18n::alternateUrl($currentPath, 'de'), '/'))) ?>">
    <link rel="alternate" hreflang="en" href="<?= e(Config::url(ltrim(I18n::alternateUrl($currentPath, 'en'), '/'))) ?>">
    <link rel="alternate" hreflang="x-default" href="<?= e(Config::url(ltrim(I18n::alternateUrl($currentPath, 'de'), '/'))) ?>">

    <meta property="og:type" content="website">
    <meta property="og:site_name" content="WebAtze">
    <meta property="og:title" content="<?= e($title) ?>">
    <meta property="og:description" content="<?= e($description) ?>">
    <meta property="og:url" content="<?= e($canonical) ?>">
    <meta property="og:locale" content="<?= e($locale === 'de' ? 'de_CH' : 'en_GB') ?>">
    <meta name="twitter:card" content="summary_large_image">

    <link rel="icon" href="/assets/img/favicon.svg" type="image/svg+xml">
    <link rel="apple-touch-icon" href="/assets/img/icon-180.png">
    <link rel="manifest" href="/manifest.webmanifest">

    <?php
    // Die Schriften werden gleich gebraucht – vorab anfordern spart einen
    // Rückfrageweg und verhindert, dass Text kurz in Ersatzschrift steht.
    ?>
    <link rel="preload" href="/assets/fonts/outfit-latin.woff2" as="font" type="font/woff2" crossorigin>
    <link rel="preload" href="/assets/fonts/inter-latin.woff2" as="font" type="font/woff2" crossorigin>

    <link rel="stylesheet" href="<?= e(Assets::url('app.css')) ?>">

    <?php // Theme setzen, bevor das erste Bild gezeichnet wird. ?>
    <script nonce="<?= e($nonce) ?>">
        document.documentElement.classList.remove('no-js');
        try {
            if (localStorage.getItem('webatze-theme') === 'light') {
                document.documentElement.setAttribute('data-theme', 'light');
                document.querySelector('meta[name="theme-color"]').setAttribute('content', '#ffffff');
            }
        } catch (e) { /* privates Fenster – dann eben dunkel */ }
    </script>

    <script type="module" src="<?= e(Assets::url('app.js')) ?>" defer></script>

    <?php // Angaben für Suchmaschinen, ohne sichtbaren Text ?>
    <script type="application/ld+json" nonce="<?= e($nonce) ?>">
    <?= json_out([
        '@context' => 'https://schema.org',
        '@type' => 'ProfessionalService',
        'name' => 'WebAtze',
        'description' => $description,
        'url' => Config::url(),
        'areaServed' => 'CH',
        'serviceType' => ['Webdesign', 'Website-Erstellung', 'Website-Redesign', 'Webhosting-Beratung'],
        'email' => $siteSettings['email'] ?? null,
        'telephone' => $siteSettings['phone'] ?? null,
        'address' => array_filter([
            '@type' => 'PostalAddress',
            'streetAddress' => $siteSettings['street'] ?? null,
            'addressLocality' => $siteSettings['zip_city'] ?? null,
            'addressCountry' => 'CH',
        ]),
    ]) ?>
    </script>
</head>
<body class="<?= e($pageClass) ?>">

    <a class="wa-skip" href="#inhalt"><?= e(__t('nav.skip')) ?></a>

    <?php // Der Balken oben zeigt, wie weit man auf der Seite ist. ?>
    <div class="wa-reading" aria-hidden="true">
        <span data-reading-progress></span>
    </div>

    <?php if ($showHero): ?>
        <div class="wa-canvas" data-hero-stage aria-hidden="true"></div>
    <?php endif; ?>

    <div class="wa-noise" aria-hidden="true"></div>

    <?= View_partial('partials/header', ['locale' => $locale, 'currentPath' => $currentPath, 'other' => $other]) ?>

    <main id="inhalt" class="wa-page">
        <?= $content ?>
    </main>

    <?= View_partial('partials/footer', ['locale' => $locale, 'siteSettings' => $siteSettings]) ?>

</body>
</html>
