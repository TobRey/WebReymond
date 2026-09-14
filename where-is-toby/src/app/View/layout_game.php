<?php
/** @var string $content */
use App\Core\View;
?><!doctype html>
<html lang="de" data-theme="dark">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover, user-scalable=no">
<meta name="color-scheme" content="dark">
<meta name="robots" content="noindex, nofollow">
<title><?= View::e($title ?? 'Ermittlungsterminal') ?></title>
<link rel="stylesheet" href="<?= View::asset('css/base.css') ?>">
<link rel="stylesheet" href="<?= View::asset('css/game.css') ?>">
<link rel="icon" href="<?= View::asset('img/ui/favicon.svg') ?>" type="image/svg+xml">
</head>
<body class="page page--game" data-reduced-motion="<?= !empty($userSettings['reduced_motion']) ? '1' : '0' ?>">
<div class="grain" aria-hidden="true"></div>
<div class="crt" aria-hidden="true"></div>
<?= $content ?>
<script id="wit-bootstrap" type="application/json"><?= View::js([
    'base'     => View::url(''),
    'csrf'     => $csrf,
    'caseId'   => $caseId ?? '',
    'state'    => $state ?? [],
    'agent'    => $agentName ?? 'Agent',
    'settings' => $userSettings ?? [],
    'gameplay' => $gameplay ?? [],
    'chatMode' => $chatMode ?? 'offline',
    'ageConfirmed' => $ageConfirmed ?? false,
    'isAdmin'  => $isAdmin ?? false,
]) ?></script>
<script type="module" src="<?= View::asset('js/game.js') ?>"></script>
</body>
</html>
