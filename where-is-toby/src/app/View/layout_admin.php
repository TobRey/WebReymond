<?php
/** @var string $content */
use App\Core\View;
$nav = $nav ?? '';
$items = [
    'dashboard'   => ['/admin', 'Uebersicht'],
    'cases'       => ['/admin/faelle', 'Faelle'],
    'media'       => ['/admin/medien', 'Medien'],
    'players'     => ['/admin/spieler', 'Spieler'],
    'ai'          => ['/admin/ki', 'KI'],
    'settings'    => ['/admin/einstellungen', 'Einstellungen'],
    'backup'      => ['/admin/sicherung', 'Sicherung'],
    'logs'        => ['/admin/protokolle', 'Protokolle'],
    'diagnostics' => ['/admin/diagnose', 'Diagnose'],
    'account'     => ['/admin/konto', 'Konto'],
];
?><!doctype html>
<html lang="de" data-theme="dark">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="color-scheme" content="dark">
<meta name="robots" content="noindex, nofollow">
<title><?= View::e($title ?? 'Adminbereich') ?> &middot; WHERE IS TOBY?</title>
<link rel="stylesheet" href="<?= View::asset('css/base.css') ?>">
<link rel="stylesheet" href="<?= View::asset('css/admin.css') ?>">
<link rel="icon" href="<?= View::asset('img/ui/favicon.svg') ?>" type="image/svg+xml">
</head>
<body class="page page--admin">
<div class="grain" aria-hidden="true"></div>
<div class="admin-shell">
    <aside class="admin-side">
        <a class="brand brand--small" href="<?= View::url('/admin') ?>">
            <span class="brand__seal" aria-hidden="true">FBI</span>
            <span class="brand__text"><strong>Adminbereich</strong><small>WHERE IS TOBY?</small></span>
        </a>
        <nav>
            <?php foreach ($items as $key => [$href, $label]): ?>
                <a href="<?= View::url($href) ?>" class="<?= $nav === $key ? 'is-active' : '' ?>"><?= View::e($label) ?></a>
            <?php endforeach; ?>
        </nav>
        <div class="admin-side__foot">
            <a href="<?= View::url('/faelle') ?>">Zum Spiel</a>
            <form method="post" action="<?= View::url('/logout') ?>">
                <input type="hidden" name="_csrf" value="<?= View::e($csrf) ?>">
                <button type="submit" class="linkish">Abmelden</button>
            </form>
        </div>
    </aside>
    <main class="admin-main">
        <header class="admin-head">
            <h1><?= View::e($title ?? '') ?></h1>
            <div class="admin-head__meta">
                <span><?= View::e($adminUser['username'] ?? '') ?></span>
                <span class="chip chip--muted">v<?= View::e(WIT_VERSION) ?></span>
            </div>
        </header>
        <?php if (!empty($mustChangePassword)): ?>
            <div class="alert alert--warn">
                <strong>Startpasswort aktiv.</strong> Bitte unter <a href="<?= View::url('/admin/konto') ?>">Konto</a> ein eigenes Passwort setzen.
            </div>
        <?php endif; ?>
        <?php if (!empty($flashOk)): ?><div class="alert alert--ok"><?= View::e($flashOk) ?></div><?php endif; ?>
        <?php if (!empty($flashError)): ?><div class="alert alert--error"><?= View::e($flashError) ?></div><?php endif; ?>
        <?= $content ?>
    </main>
</div>
<script id="wit-admin-bootstrap" type="application/json"><?= View::js([
    'base' => View::url(''),
    'csrf' => $csrf,
]) ?></script>
<script type="module" src="<?= View::asset('js/admin.js') ?>"></script>
</body>
</html>
