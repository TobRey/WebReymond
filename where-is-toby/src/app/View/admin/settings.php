<?php use App\Core\View; $site = $config['site']; $game = $config['gameplay']; $security = $config['security']; ?>
<form data-admin-page="settings" data-role="settings-form">
    <section class="panel-box">
        <h2>Seite</h2>
        <div class="field-grid">
            <label>Name<input type="text" name="site_name" value="<?= View::e($site['name']) ?>" maxlength="120"></label>
            <label>Untertitel<input type="text" name="tagline" value="<?= View::e($site['tagline']) ?>" maxlength="160"></label>
            <label class="check"><input type="checkbox" name="allow_register" value="1" <?= $site['allow_register'] ? 'checked' : '' ?>> Registrierung erlauben</label>
            <label class="check"><input type="checkbox" name="allow_guests" value="1" <?= $site['allow_guests'] ? 'checked' : '' ?>> Gastmodus erlauben</label>
            <label class="full">Impressum<textarea name="imprint" rows="5" maxlength="4000"><?= View::e($site['imprint']) ?></textarea></label>
            <label class="full">Datenschutzhinweis<textarea name="privacy" rows="7" maxlength="6000"><?= View::e($site['privacy']) ?></textarea></label>
        </div>
    </section>

    <section class="panel-box">
        <h2>Spielregeln</h2>
        <div class="field-grid">
            <label>Hinweise pro Fall (Spieler)
                <input type="number" name="hints_per_case" value="<?= (int)$game['hints_per_case'] ?>" min="0" max="10">
                <span class="field-help">Administratoren haben immer unbegrenzt viele Hinweise.</span>
            </label>
            <label>Horror-Intensitaet
                <select name="horror_intensity">
                    <?php foreach (['mild' => 'mild', 'normal' => 'normal', 'intense' => 'intensiv'] as $value => $label): ?>
                        <option value="<?= $value ?>" <?= $game['horror_intensity'] === $value ? 'selected' : '' ?>><?= View::e($label) ?></option>
                    <?php endforeach; ?>
                </select>
            </label>
            <label class="check"><input type="checkbox" name="jumpscares" value="1" <?= $game['jumpscares'] ? 'checked' : '' ?>> Schreckmomente erlauben</label>
            <label class="check"><input type="checkbox" name="show_timer" value="1" <?= $game['show_timer'] ? 'checked' : '' ?>> Spielzeit anzeigen</label>
            <label>Speicherintervall (Sekunden)<input type="number" name="autosave_seconds" value="<?= (int)$game['autosave_seconds'] ?>" min="5" max="120"></label>
            <label>Standardfall<input type="text" name="default_case" value="<?= View::e($game['default_case']) ?>" maxlength="64"></label>
        </div>
    </section>

    <section class="panel-box">
        <h2>Sicherheit</h2>
        <div class="field-grid">
            <label>Fehlversuche bis Kontosperre<input type="number" name="max_login_attempts" value="<?= (int)$security['max_login_attempts'] ?>" min="3" max="20"></label>
            <label>Sperrdauer (Minuten)<input type="number" name="lockout_minutes" value="<?= (int)$security['lockout_minutes'] ?>" min="1" max="120"></label>
            <label>Chatnachrichten pro Minute<input type="number" name="chat_per_minute" value="<?= (int)$security['chat_per_minute'] ?>" min="3" max="60"></label>
            <label>API-Anfragen pro Minute<input type="number" name="api_per_minute" value="<?= (int)$security['api_per_minute'] ?>" min="30" max="600"></label>
            <label>Registrierungen pro Stunde und IP<input type="number" name="registration_per_hour" value="<?= (int)$security['registration_per_hour'] ?>" min="1" max="100"></label>
        </div>
    </section>

    <div class="toolbar">
        <button class="btn btn--primary" type="submit">Einstellungen speichern</button>
        <span class="hint" data-role="settings-status"></span>
    </div>
</form>
