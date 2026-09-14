<?php use App\Core\View; ?>
<section class="auth">
    <form class="card card--form" method="post" action="<?= View::url('/registrieren') ?>">
        <h1>Konto anlegen</h1>
        <?php if (!empty($error)): ?><div class="alert alert--error"><?= View::e($error) ?></div><?php endif; ?>
        <input type="hidden" name="_csrf" value="<?= View::e($csrf) ?>">
        <label>Benutzername
            <input type="text" name="username" value="<?= View::e($values['username'] ?? '') ?>" required minlength="3" maxlength="32" pattern="[A-Za-z0-9_.\-]{3,32}">
        </label>
        <label>E-Mail (optional, nur zur Wiedererkennung)
            <input type="email" name="email" value="<?= View::e($values['email'] ?? '') ?>" maxlength="190" autocomplete="email">
        </label>
        <label>Passwort (mindestens 10 Zeichen, drei Zeichenarten)
            <input type="password" name="password" required minlength="10" maxlength="200" autocomplete="new-password">
        </label>
        <label>Passwort wiederholen
            <input type="password" name="password_repeat" required minlength="10" maxlength="200" autocomplete="new-password">
        </label>
        <label class="check">
            <input type="checkbox" name="age_confirm" value="1" required>
            Ich bin mindestens 18 Jahre alt und akzeptiere die Inhaltswarnung (Gewalt, Horror, verstoerende Themen).
        </label>
        <button class="btn btn--primary" type="submit">Konto anlegen</button>
        <div class="card__foot"><a href="<?= View::url('/login') ?>">Zurueck zur Anmeldung</a></div>
    </form>
</section>
