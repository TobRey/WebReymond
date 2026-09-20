<?php
/** @var bool $invalid @var string $token @var array $errors */
use SkyKingdoms\Core\Csrf;
use SkyKingdoms\Core\Url;
use SkyKingdoms\Core\View;
?>
<?= View::render('partials/brand', ['subtitle' => 'Neues Passwort festlegen']) ?>

<div class="sk-card">
    <?php if ($invalid): ?>
        <div class="sk-alert sk-alert--error"><span>Dieser Link ist abgelaufen oder ungültig.</span></div>
        <a class="sk-btn sk-btn--block" href="<?= e(Url::to('?p=forgot')) ?>">Neuen Link anfordern</a>
    <?php else: ?>
        <form method="post" action="<?= e(Url::to('?p=reset')) ?>" novalidate>
            <?= Csrf::field() ?>
            <input type="hidden" name="token" value="<?= e($token) ?>">

            <div class="sk-field <?= isset($errors['password']) ? 'sk-field--error' : '' ?>">
                <label for="password">Neues Passwort</label>
                <input id="password" name="password" type="password" required autocomplete="new-password" data-strength>
                <div class="sk-strength" data-score="0"><span></span><span></span><span></span><span></span></div>
                <?php if (isset($errors['password'])): ?><div class="sk-field__error"><?= e($errors['password']) ?></div><?php endif; ?>
            </div>

            <div class="sk-field <?= isset($errors['password_confirm']) ? 'sk-field--error' : '' ?>">
                <label for="password_confirm">Neues Passwort wiederholen</label>
                <input id="password_confirm" name="password_confirm" type="password" required autocomplete="new-password">
                <?php if (isset($errors['password_confirm'])): ?><div class="sk-field__error"><?= e($errors['password_confirm']) ?></div><?php endif; ?>
            </div>

            <button class="sk-btn sk-btn--block sk-btn--green" type="submit">Passwort speichern</button>
        </form>
    <?php endif; ?>
</div>
<script src="<?= e(Url::asset('js/strength.js')) ?>" nonce="<?= e(\SkyKingdoms\Core\Security::nonce()) ?>" defer></script>
