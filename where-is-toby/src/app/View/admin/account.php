<?php use App\Core\View; ?>
<div data-admin-page="account">
    <section class="panel-box">
        <h2>Administratorkonto</h2>
        <dl class="datalist">
            <dt>Benutzername</dt><dd><?= View::e($account['username']) ?></dd>
            <dt>E-Mail</dt><dd><?= View::e($account['email'] ?: '-') ?></dd>
            <dt>Konto seit</dt><dd><?= View::e(substr((string)$account['created_at'], 0, 10)) ?></dd>
            <dt>Letzter Login</dt><dd><?= View::e(substr((string)($account['last_login'] ?? '-'), 0, 16)) ?></dd>
        </dl>
        <?php if (!empty($account['must_change_password'])): ?>
            <div class="alert alert--warn">Es ist noch das Startpasswort aktiv. Bitte jetzt aendern.</div>
        <?php endif; ?>
    </section>

    <section class="panel-box">
        <h2>Passwort aendern</h2>
        <form data-role="password-form" class="field-grid">
            <label>Aktuelles Passwort<input type="password" name="current_password" required maxlength="200" autocomplete="current-password"></label>
            <label>Neues Passwort<input type="password" name="password" required minlength="10" maxlength="200" autocomplete="new-password"></label>
            <label>Wiederholen<input type="password" name="password_repeat" required minlength="10" maxlength="200" autocomplete="new-password"></label>
            <div class="full toolbar">
                <button class="btn btn--primary" type="submit">Passwort speichern</button>
                <span class="hint" data-role="password-status"></span>
            </div>
        </form>
        <p class="hint">Mindestens 10 Zeichen und drei der vier Zeichenarten (Klein-, Grossbuchstaben, Zahlen, Sonderzeichen).
            Das Passwort wird ausschliesslich als Hash (password_hash) gespeichert.</p>
    </section>
</div>
