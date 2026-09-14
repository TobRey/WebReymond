<?php use App\Core\View; ?>
<section class="auth">
    <form class="card card--form" method="post" action="<?= View::url('/login') ?>" autocomplete="on">
        <h1>Anmeldung</h1>
        <p class="hint">Zugang zum Ermittlungsterminal.</p>
        <?php if (!empty($error)): ?><div class="alert alert--error"><?= View::e($error) ?></div><?php endif; ?>
        <input type="hidden" name="_csrf" value="<?= View::e($csrf) ?>">
        <label>Benutzername oder E-Mail
            <input type="text" name="username" required maxlength="100" autocomplete="username" autofocus>
        </label>
        <label>Passwort
            <input type="password" name="password" required maxlength="200" autocomplete="current-password">
        </label>
        <button class="btn btn--primary" type="submit">Anmelden</button>
        <div class="card__foot">
            <?php if (!empty($allowRegister)): ?><a href="<?= View::url('/registrieren') ?>">Konto anlegen</a><?php endif; ?>
            <?php if (!empty($allowGuests)): ?>
                <form method="post" action="<?= View::url('/gast') ?>" class="inline-form">
                    <input type="hidden" name="_csrf" value="<?= View::e($csrf) ?>">
                    <button class="linkish" type="submit">Ohne Konto spielen</button>
                </form>
            <?php endif; ?>
        </div>
    </form>
</section>
