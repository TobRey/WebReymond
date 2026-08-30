<?php
use WebAtze\Core\Csrf;

/** @var string $error */
?>
<div class="wa-login">
    <form class="wa-login__card" method="post" action="" autocomplete="on">
        <?= Csrf::field() ?>

        <h1 class="wa-login__title">Anmelden</h1>

        <?php if (($error ?? '') !== ''): ?>
            <p class="wa-login__error" role="alert"><?= e($error) ?></p>
        <?php endif; ?>

        <label class="wa-label" for="username">Benutzername</label>
        <input class="wa-input" type="text" id="username" name="username"
               autocomplete="username" required autofocus
               maxlength="64" spellcheck="false" autocapitalize="none">

        <label class="wa-label" for="password">Passwort</label>
        <input class="wa-input" type="password" id="password" name="password"
               autocomplete="current-password" required maxlength="200">

        <button type="submit" class="wa-btn wa-btn--primary wa-btn--block">Weiter</button>
    </form>
</div>
