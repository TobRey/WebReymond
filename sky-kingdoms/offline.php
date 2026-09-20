<?php
/** Wird angezeigt, wenn keine Verbindung besteht. */

declare(strict_types=1);

define('SK_ENTRY_DEPTH', 0);
require __DIR__ . '/app/bootstrap.php';

use SkyKingdoms\Core\App;
use SkyKingdoms\Core\Url;

http_response_code(200);
?>
<!doctype html>
<html lang="de">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1,viewport-fit=cover">
<title>Offline – <?= e(App::config('name', 'Sky Kingdoms')) ?></title>
<link rel="stylesheet" href="<?= e(Url::asset('css/app.css')) ?>">
</head>
<body class="sk-simple">
<main class="sk-simple__card">
    <img src="<?= e(Url::asset('img/logo.svg')) ?>" alt="" width="96" height="96" style="margin:0 auto 12px">
    <h1>Keine Verbindung</h1>
    <p>Dein Königreich wartet auf dich – sobald wieder Netz da ist. Deine Produktion läuft
        auf dem Server weiter und wird beim nächsten Besuch nachgerechnet.</p>
    <p><a class="sk-btn" href="<?= e(Url::to('?p=game')) ?>">Erneut versuchen</a></p>
</main>
</body>
</html>
