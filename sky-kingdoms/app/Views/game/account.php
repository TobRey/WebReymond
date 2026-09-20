<?php
/** @var array $user @var array $errors @var string $done */
use SkyKingdoms\Core\App;
use SkyKingdoms\Core\Csrf;
use SkyKingdoms\Core\Url;
use SkyKingdoms\Core\View;

$settings = (array) ($user['settings'] ?? []);
?>
<?= View::render('partials/brand', ['subtitle' => 'Kontoeinstellungen']) ?>

<?php if ($done !== ''): ?>
    <div class="sk-alert sk-alert--ok"><span><?= e($done) ?></span></div>
<?php endif; ?>

<div class="sk-card">
    <h2>Darstellung und Ton</h2>
    <form method="post" action="<?= e(Url::to('?p=konto')) ?>">
        <?= Csrf::field() ?>
        <input type="hidden" name="action" value="settings">

        <label class="sk-check"><input type="checkbox" name="sound" value="1" <?= !empty($settings['sound']) ? 'checked' : '' ?>><span>Soundeffekte</span></label>
        <label class="sk-check"><input type="checkbox" name="music" value="1" <?= !empty($settings['music']) ? 'checked' : '' ?>><span>Hintergrundmusik</span></label>
        <label class="sk-check"><input type="checkbox" name="animations" value="1" <?= !empty($settings['animations']) ? 'checked' : '' ?>><span>Animationen</span></label>
        <label class="sk-check"><input type="checkbox" name="haptics" value="1" <?= !empty($settings['haptics']) ? 'checked' : '' ?>><span>Vibration bei Berührung</span></label>

        <div class="sk-field" style="margin-top:12px">
            <label for="quality">Grafikqualität</label>
            <select id="quality" name="quality">
                <option value="auto"   <?= ($settings['quality'] ?? 'auto') === 'auto' ? 'selected' : '' ?>>Automatisch erkennen</option>
                <option value="high"   <?= ($settings['quality'] ?? '') === 'high' ? 'selected' : '' ?>>Hoch</option>
                <option value="medium" <?= ($settings['quality'] ?? '') === 'medium' ? 'selected' : '' ?>>Mittel</option>
                <option value="low"    <?= ($settings['quality'] ?? '') === 'low' ? 'selected' : '' ?>>Sparsam (schwächere Geräte)</option>
            </select>
        </div>

        <button class="sk-btn sk-btn--block" type="submit">Speichern</button>
    </form>
</div>

<div class="sk-card" style="margin-top:16px">
    <h2>Passwort ändern</h2>
    <form method="post" action="<?= e(Url::to('?p=konto')) ?>" novalidate>
        <?= Csrf::field() ?>
        <input type="hidden" name="action" value="password">

        <div class="sk-field <?= isset($errors['old_password']) ? 'sk-field--error' : '' ?>">
            <label for="old_password">Bisheriges Passwort</label>
            <input id="old_password" name="old_password" type="password" required autocomplete="current-password">
            <?php if (isset($errors['old_password'])): ?><div class="sk-field__error"><?= e($errors['old_password']) ?></div><?php endif; ?>
        </div>
        <div class="sk-field <?= isset($errors['new_password']) ? 'sk-field--error' : '' ?>">
            <label for="new_password">Neues Passwort</label>
            <input id="new_password" name="new_password" type="password" required autocomplete="new-password" data-strength>
            <div class="sk-strength" data-score="0"><span></span><span></span><span></span><span></span></div>
            <?php if (isset($errors['new_password'])): ?><div class="sk-field__error"><?= e($errors['new_password']) ?></div><?php endif; ?>
        </div>
        <div class="sk-field <?= isset($errors['new_password_confirm']) ? 'sk-field--error' : '' ?>">
            <label for="new_password_confirm">Neues Passwort wiederholen</label>
            <input id="new_password_confirm" name="new_password_confirm" type="password" required autocomplete="new-password">
            <?php if (isset($errors['new_password_confirm'])): ?><div class="sk-field__error"><?= e($errors['new_password_confirm']) ?></div><?php endif; ?>
        </div>

        <button class="sk-btn sk-btn--block" type="submit">Passwort ändern</button>
    </form>
</div>

<div class="sk-card" style="margin-top:16px">
    <h2>Konto löschen</h2>
    <div class="sk-alert sk-alert--warn"><span>Dein Königreich, alle Berichte und dein Profil werden unwiderruflich entfernt.</span></div>
    <form method="post" action="<?= e(Url::to('?p=konto')) ?>" novalidate>
        <?= Csrf::field() ?>
        <input type="hidden" name="action" value="delete">

        <div class="sk-field <?= isset($errors['delete_password']) ? 'sk-field--error' : '' ?>">
            <label for="delete_password">Passwort zur Bestätigung</label>
            <input id="delete_password" name="delete_password" type="password" autocomplete="current-password">
            <?php if (isset($errors['delete_password'])): ?><div class="sk-field__error"><?= e($errors['delete_password']) ?></div><?php endif; ?>
        </div>
        <div class="sk-field <?= isset($errors['delete_confirm']) ? 'sk-field--error' : '' ?>">
            <label for="delete_confirm">Tippe LÖSCHEN</label>
            <input id="delete_confirm" name="delete_confirm" type="text" autocomplete="off">
            <?php if (isset($errors['delete_confirm'])): ?><div class="sk-field__error"><?= e($errors['delete_confirm']) ?></div><?php endif; ?>
        </div>

        <button class="sk-btn sk-btn--block sk-btn--danger" type="submit">Konto endgültig löschen</button>
    </form>
</div>

<div class="sk-row" style="margin-top:16px">
    <a class="sk-btn sk-grow" href="<?= e(Url::to('?p=game')) ?>">Zum Königreich</a>
    <form method="post" action="<?= e(Url::to('?p=logout')) ?>" class="sk-grow" style="display:flex">
        <?= Csrf::field() ?>
        <button class="sk-btn sk-btn--danger sk-grow" type="submit">Abmelden</button>
    </form>
</div>
<script src="<?= e(Url::asset('js/strength.js')) ?>" nonce="<?= e(\SkyKingdoms\Core\Security::nonce()) ?>" defer></script>
