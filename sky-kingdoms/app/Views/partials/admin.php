<?php
/**
 * Layout des Adminbereichs – nutzt dasselbe responsive System wie das Spiel.
 * @var string $content @var string $title
 */

use SkyKingdoms\Core\App;
use SkyKingdoms\Core\Auth;
use SkyKingdoms\Core\Csrf;
use SkyKingdoms\Core\Security;
use SkyKingdoms\Core\Session;
use SkyKingdoms\Core\Url;

$gameName = (string) App::config('name', 'Sky Kingdoms');
$theme    = (array) App::config('theme', []);
$nonce    = Security::nonce();
$flash    = Session::takeFlash();
$current  = (string) ($_GET['p'] ?? 'dashboard');

$pages = [
    'dashboard' => 'Übersicht',
    'players'   => 'Spieler',
    'balance'   => 'Balance',
    'quests'    => 'Aufgaben',
    'announce'  => 'Ankündigung',
    'ranking'   => 'Rangliste',
    'logs'      => 'Protokolle',
    'settings'  => 'Einstellungen',
    'diagnose'  => 'Diagnose',
];
?>
<!doctype html>
<html lang="de">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
<meta name="theme-color" content="<?= e($theme['sky_top'] ?? '#5ec6ff') ?>">
<title><?= e($title ?? 'Admin') ?> – <?= e($gameName) ?> Admin</title>
<link rel="icon" href="<?= e(Url::asset('img/logo.svg')) ?>" type="image/svg+xml">
<link rel="stylesheet" href="<?= e(Url::asset('css/app.css')) ?>">
<style nonce="<?= e($nonce) ?>">
:root{
--sk-primary:<?= e($theme['primary'] ?? '#3f8cff') ?>;
--sk-secondary:<?= e($theme['secondary'] ?? '#ffb43f') ?>;
--sk-accent:<?= e($theme['accent'] ?? '#7ee3a6') ?>;
--sk-danger:<?= e($theme['danger'] ?? '#ff5c6c') ?>;
--sk-sky-top:#2b3f63;--sk-sky-bottom:#45608f;
}
body.sk-admin{background:linear-gradient(180deg,#22314e,#38507a);min-height:100dvh}
.sk-admin__nav{display:flex;gap:6px;overflow-x:auto;padding:4px;background:rgba(10,19,34,.35);
    border-radius:999px;margin-bottom:18px;scrollbar-width:none}
.sk-admin__nav::-webkit-scrollbar{display:none}
.sk-admin__nav a{flex:none;padding:10px 16px;border-radius:999px;text-decoration:none;font-weight:700;
    font-size:.88rem;color:rgba(255,255,255,.8);white-space:nowrap;min-height:44px;display:flex;align-items:center}
.sk-admin__nav a.is-active{background:#fff;color:var(--sk-primary)}
.sk-admin__head{display:flex;align-items:center;gap:12px;margin-bottom:16px;color:#fff;flex-wrap:wrap}
.sk-admin__head h1{margin:0;font-size:1.4rem}
.sk-admin__head a{color:#fff}
</style>
</head>
<body class="sk-admin sk-sky sk-sky--wide">
<div class="sk-sky__content">

    <header class="sk-admin__head">
        <img src="<?= e(Url::asset('img/logo.svg')) ?>" alt="" width="42" height="42">
        <div class="sk-grow">
            <h1><?= e($gameName) ?> – Verwaltung</h1>
            <div style="font-size:.85rem;opacity:.85">
                Angemeldet als <?= e((string) (Auth::user()['username'] ?? '')) ?>
            </div>
        </div>
        <a class="sk-btn sk-btn--small sk-btn--ghost" href="<?= e(Url::to('?p=game')) ?>">Zum Spiel</a>
    </header>

    <nav class="sk-admin__nav">
        <?php foreach ($pages as $key => $label): ?>
            <a href="<?= e(Url::to('admin/?p=' . $key)) ?>" class="<?= $current === $key ? 'is-active' : '' ?>"><?= e($label) ?></a>
        <?php endforeach; ?>
    </nav>

    <?php if (App::config('maintenance', false)): ?>
        <div class="sk-alert sk-alert--warn"><span><strong>Wartungsmodus ist aktiv.</strong>
            Nur Administratoren können das Spiel öffnen.</span></div>
    <?php endif; ?>

    <?php foreach ($flash as $item): ?>
        <div class="sk-alert sk-alert--<?= e($item['type'] === 'error' ? 'error' : ($item['type'] === 'ok' ? 'ok' : 'info')) ?>">
            <span><?= e($item['message']) ?></span>
        </div>
    <?php endforeach; ?>

    <?= $content ?>

    <p class="sk-foot"><?= e($gameName) ?> · Version <?= e(SK_VERSION) ?> · PHP <?= e(PHP_VERSION) ?></p>
</div>
</body>
</html>
