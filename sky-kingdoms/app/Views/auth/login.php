<?php
/** @var array $errors @var array $values */
use SkyKingdoms\Core\Csrf;
use SkyKingdoms\Core\Url;
use SkyKingdoms\Core\View;
?>
<?= View::render('partials/brand') ?>

<div class="sk-card">
    <?= View::render('partials/authtabs', ['active' => 'login']) ?>

    <?php if (isset($errors['login'])): ?>
        <div class="sk-alert sk-alert--error"><span><?= e($errors['login']) ?></span></div>
    <?php endif; ?>

    <form method="post" action="<?= e(Url::to('?p=login')) ?>" novalidate>
        <?= Csrf::field() ?>

        <div class="sk-field">
            <label for="login">Spielername oder E-Mail</label>
            <input id="login" name="login" type="text" required autocomplete="username"
                   autocapitalize="none" value="<?= e($values['login'] ?? '') ?>">
        </div>

        <div class="sk-field">
            <label for="password">Passwort</label>
            <input id="password" name="password" type="password" required autocomplete="current-password">
        </div>

        <label class="sk-check">
            <input type="checkbox" name="remember" value="1">
            <span>Angemeldet bleiben</span>
        </label>

        <button class="sk-btn sk-btn--block sk-btn--gold" type="submit" style="margin-top:14px">Ins Königreich</button>
    </form>

    <p class="sk-center sk-muted" style="margin:16px 0 0;font-size:.9rem">
        <a href="<?= e(Url::to('?p=forgot')) ?>">Passwort vergessen?</a>
    </p>
</div>
