<?php
/** @var array $errors @var bool $sent @var string $note @var bool $mail */
use SkyKingdoms\Core\Csrf;
use SkyKingdoms\Core\Url;
use SkyKingdoms\Core\View;
?>
<?= View::render('partials/brand', ['subtitle' => 'Passwort vergessen']) ?>

<div class="sk-card">
    <?php if ($sent): ?>
        <div class="sk-alert sk-alert--ok"><span><?= e($note) ?></span></div>
        <a class="sk-btn sk-btn--block" href="<?= e(Url::to('?p=login')) ?>">Zurück zur Anmeldung</a>
    <?php else: ?>
        <?php if (!$mail): ?>
            <div class="sk-alert sk-alert--info">
                <span>Auf diesem Server ist kein E-Mail-Versand eingerichtet. Deine Anfrage wird trotzdem
                    vermerkt – der Administrator kann das Passwort im Adminbereich zurücksetzen.</span>
            </div>
        <?php endif; ?>

        <form method="post" action="<?= e(Url::to('?p=forgot')) ?>" novalidate>
            <?= Csrf::field() ?>
            <div class="sk-field <?= isset($errors['email']) ? 'sk-field--error' : '' ?>">
                <label for="email">E-Mail-Adresse deines Kontos</label>
                <input id="email" name="email" type="email" required autocomplete="email">
                <?php if (isset($errors['email'])): ?><div class="sk-field__error"><?= e($errors['email']) ?></div><?php endif; ?>
            </div>
            <button class="sk-btn sk-btn--block" type="submit">Zurücksetzen anfordern</button>
        </form>
        <p class="sk-center" style="margin-top:14px"><a href="<?= e(Url::to('?p=login')) ?>">Zurück zur Anmeldung</a></p>
    <?php endif; ?>
</div>
