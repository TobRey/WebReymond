<?php defined('WEBATZE') || exit; ?>
<?php
/** @var string $brand @var string $nonce @var string $base @var string $loginError */
?>
<!doctype html>
<html lang="de">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex, nofollow, noarchive">
    <title>Anmelden</title>
    <link rel="stylesheet" href="admin.css">
</head>
<body class="k-bare">
    <main class="k-bare__inner">
        <form class="k-card k-login" method="post" action="<?= e($base) ?>">
            <input type="hidden" name="action" value="login">
            <?= \WebAtzeKit\Csrf::field() ?>

            <h1 class="k-login__title"><?= e($brand) ?></h1>
            <p class="k-login__sub">Bearbeitungsbereich</p>

            <?php if ($loginError !== ''): ?>
                <p class="k-note k-note--error" role="alert"><?= e($loginError) ?></p>
            <?php endif; ?>

            <label class="k-label" for="username">Benutzername</label>
            <input class="k-input" type="text" id="username" name="username"
                   autocomplete="username" required autofocus maxlength="64"
                   spellcheck="false" autocapitalize="none">

            <label class="k-label" for="password">Passwort</label>
            <input class="k-input" type="password" id="password" name="password"
                   autocomplete="current-password" required maxlength="200">

            <button class="k-btn k-btn--primary k-btn--block" type="submit">Anmelden</button>
        </form>

        <p class="k-bare__foot">
            <a href="index.html">Zur Website</a>
        </p>
    </main>
</body>
</html>
