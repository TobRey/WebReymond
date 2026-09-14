<?php
/** @var string $content */
use App\Core\View;
$base = View::url('/');
?><!doctype html>
<html lang="de" data-theme="dark">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
<meta name="color-scheme" content="dark">
<meta name="description" content="WHERE IS TOBY? - realistisches FBI-Ermittlungsspiel mit KI-Verhoeren, Beweisanalyse und Horror-Elementen.">
<meta name="robots" content="noindex, nofollow">
<title><?= View::e($title ?? 'WHERE IS TOBY?') ?></title>
<link rel="stylesheet" href="<?= View::asset('css/base.css') ?>">
<link rel="icon" href="<?= View::asset('img/ui/favicon.svg') ?>" type="image/svg+xml">
</head>
<body class="page page--public">
<div class="grain" aria-hidden="true"></div>
<header class="topbar">
    <a class="brand" href="<?= View::url('/') ?>">
        <span class="brand__seal" aria-hidden="true">FBI</span>
        <span class="brand__text">
            <strong><?= View::e($siteName ?? 'WHERE IS TOBY?') ?></strong>
            <small><?= View::e($tagline ?? '') ?></small>
        </span>
    </a>
    <nav class="topnav">
        <?php if (!empty($user)): ?>
            <a href="<?= View::url('/faelle') ?>">Faelle</a>
            <a href="<?= View::url('/konto') ?>">Konto</a>
            <?php if (!empty($isAdmin)): ?><a href="<?= View::url('/admin') ?>">Admin</a><?php endif; ?>
            <form method="post" action="<?= View::url('/logout') ?>" class="inline-form">
                <input type="hidden" name="_csrf" value="<?= View::e($csrf) ?>">
                <button type="submit" class="linkish">Abmelden</button>
            </form>
        <?php else: ?>
            <a href="<?= View::url('/login') ?>">Anmelden</a>
        <?php endif; ?>
    </nav>
</header>

<?php if (!empty($flashOk)): ?><div class="flash flash--ok"><?= View::e($flashOk) ?></div><?php endif; ?>
<?php if (!empty($flashError)): ?><div class="flash flash--error"><?= View::e($flashError) ?></div><?php endif; ?>

<main class="wrap">
<?= $content ?>
</main>

<footer class="footer">
    <div>
        <a href="<?= View::url('/impressum') ?>">Impressum</a>
        <a href="<?= View::url('/datenschutz') ?>">Datenschutz</a>
        <a href="<?= View::url('/hilfe') ?>">Hilfe</a>
    </div>
    <p>Alle Personen, Orte, Geraete und Ereignisse sind frei erfunden. Simulierte Logins und Geraete sind reine Spielelemente. Ab 18 Jahren.</p>
</footer>
</body>
</html>
