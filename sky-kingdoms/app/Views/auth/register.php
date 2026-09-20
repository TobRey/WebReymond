<?php
/** @var array $errors @var array $values */
use SkyKingdoms\Core\App;
use SkyKingdoms\Core\Csrf;
use SkyKingdoms\Core\Security;
use SkyKingdoms\Core\Url;
use SkyKingdoms\Core\View;
?>
<?= View::render('partials/brand', ['subtitle' => 'Gründe dein eigenes Himmelsreich']) ?>

<div class="sk-card">
    <?= View::render('partials/authtabs', ['active' => 'register']) ?>

    <form method="post" action="<?= e(Url::to('?p=register')) ?>" novalidate>
        <?= Csrf::field() ?>

        <div class="sk-field <?= isset($errors['username']) ? 'sk-field--error' : '' ?>">
            <label for="username">Spielername</label>
            <input id="username" name="username" type="text" required autocomplete="username" autocapitalize="none"
                   maxlength="<?= e((string) App::config('username_max_length', 20)) ?>"
                   value="<?= e($values['username'] ?? '') ?>">
            <?php if (isset($errors['username'])): ?><div class="sk-field__error"><?= e($errors['username']) ?></div><?php endif; ?>
        </div>

        <div class="sk-field <?= isset($errors['email']) ? 'sk-field--error' : '' ?>">
            <label for="email">E-Mail-Adresse</label>
            <input id="email" name="email" type="email" required autocomplete="email" value="<?= e($values['email'] ?? '') ?>">
            <?php if (isset($errors['email'])): ?><div class="sk-field__error"><?= e($errors['email']) ?></div><?php endif; ?>
            <div class="sk-field__hint">Wird nur zum Zurücksetzen des Passworts verwendet.</div>
        </div>

        <div class="sk-field <?= isset($errors['kingdom']) ? 'sk-field--error' : '' ?>">
            <label for="kingdom">Name deines Königreichs <span class="sk-muted">(freiwillig)</span></label>
            <input id="kingdom" name="kingdom" type="text" maxlength="30" value="<?= e($values['kingdom'] ?? '') ?>">
            <?php if (isset($errors['kingdom'])): ?><div class="sk-field__error"><?= e($errors['kingdom']) ?></div><?php endif; ?>
        </div>

        <div class="sk-field <?= isset($errors['password']) ? 'sk-field--error' : '' ?>">
            <label for="password">Passwort</label>
            <input id="password" name="password" type="password" required autocomplete="new-password" data-strength>
            <div class="sk-strength" data-score="0"><span></span><span></span><span></span><span></span></div>
            <?php if (isset($errors['password'])): ?><div class="sk-field__error"><?= e($errors['password']) ?></div><?php endif; ?>
            <div class="sk-field__hint">Mindestens <?= e((string) App::config('password_min_length', 10)) ?> Zeichen aus drei verschiedenen Zeichenarten.</div>
        </div>

        <div class="sk-field <?= isset($errors['password_confirm']) ? 'sk-field--error' : '' ?>">
            <label for="password_confirm">Passwort wiederholen</label>
            <input id="password_confirm" name="password_confirm" type="password" required autocomplete="new-password">
            <?php if (isset($errors['password_confirm'])): ?><div class="sk-field__error"><?= e($errors['password_confirm']) ?></div><?php endif; ?>
        </div>

        <label class="sk-check <?= isset($errors['rules']) ? 'sk-field--error' : '' ?>">
            <input type="checkbox" name="rules" value="1" required>
            <span>Ich spiele fair und verzichte auf Mehrfachkonten.</span>
        </label>
        <?php if (isset($errors['rules'])): ?><div class="sk-field__error"><?= e($errors['rules']) ?></div><?php endif; ?>

        <button class="sk-btn sk-btn--block sk-btn--green" type="submit" style="margin-top:14px">Königreich gründen</button>
    </form>
</div>

<script src="<?= e(Url::asset('js/strength.js')) ?>" nonce="<?= e(Security::nonce()) ?>" defer></script>
