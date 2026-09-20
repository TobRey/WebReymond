<?php
/** @var array $data @var array $errors */
use SkyKingdoms\Core\App;
use SkyKingdoms\Core\Csrf;
use SkyKingdoms\Core\Url;
?>
<div class="sk-brand">
    <img class="sk-brand__logo" src="<?= e(Url::asset('img/logo.svg')) ?>" alt="">
    <h1 class="sk-brand__name">Administratorkonto</h1>
    <p class="sk-brand__tagline">Schritt 3 von 5</p>
</div>

<?= \SkyKingdoms\Core\View::render('partials/steps', ['step' => 3]) ?>

<div class="sk-card">
    <p class="sk-muted">Dieses Konto ist gleichzeitig dein Spielerkonto und dein Zugang zum Adminbereich.</p>

    <form method="post" action="<?= e(Url::to('install/?step=3')) ?>" novalidate>
        <?= Csrf::field() ?>

        <div class="sk-field <?= isset($errors['username']) ? 'sk-field--error' : '' ?>">
            <label for="username">Spielername</label>
            <input id="username" name="username" type="text" required autocomplete="username"
                   maxlength="<?= e((string) App::config('username_max_length', 20)) ?>"
                   value="<?= e($data['username'] ?? '') ?>">
            <?php if (isset($errors['username'])): ?><div class="sk-field__error"><?= e($errors['username']) ?></div><?php endif; ?>
        </div>

        <div class="sk-field <?= isset($errors['email']) ? 'sk-field--error' : '' ?>">
            <label for="email">E-Mail-Adresse</label>
            <input id="email" name="email" type="email" required autocomplete="email" value="<?= e($data['email'] ?? '') ?>">
            <?php if (isset($errors['email'])): ?><div class="sk-field__error"><?= e($errors['email']) ?></div><?php endif; ?>
        </div>

        <div class="sk-field <?= isset($errors['password']) ? 'sk-field--error' : '' ?>">
            <label for="password">Passwort</label>
            <input id="password" name="password" type="password" required autocomplete="new-password" data-strength>
            <div class="sk-strength" data-score="0"><span></span><span></span><span></span><span></span></div>
            <?php if (isset($errors['password'])): ?><div class="sk-field__error"><?= e($errors['password']) ?></div><?php endif; ?>
            <div class="sk-field__hint">Mindestens <?= e((string) App::config('password_min_length', 10)) ?> Zeichen, davon drei verschiedene Arten (Gross, Klein, Ziffern, Sonderzeichen).</div>
        </div>

        <div class="sk-field <?= isset($errors['password_confirm']) ? 'sk-field--error' : '' ?>">
            <label for="password_confirm">Passwort wiederholen</label>
            <input id="password_confirm" name="password_confirm" type="password" required autocomplete="new-password">
            <?php if (isset($errors['password_confirm'])): ?><div class="sk-field__error"><?= e($errors['password_confirm']) ?></div><?php endif; ?>
        </div>

        <button class="sk-btn sk-btn--block sk-btn--green" type="submit">Weiter zur Übersicht</button>
    </form>
</div>

<script src="<?= e(Url::asset('js/strength.js')) ?>" nonce="<?= e(\SkyKingdoms\Core\Security::nonce()) ?>" defer></script>
