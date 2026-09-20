<?php
/** @var array $settings */
use SkyKingdoms\Core\App;
use SkyKingdoms\Core\Csrf;
use SkyKingdoms\Core\Url;

$theme = (array) App::config('theme', []);
$mail  = (array) App::config('mail', []);
?>
<div class="sk-card">
    <h2>Spiel</h2>
    <form method="post" action="<?= e(Url::to('admin/?p=settings')) ?>">
        <?= Csrf::field() ?>
        <input type="hidden" name="action" value="settings">

        <div class="sk-field"><label for="name">Spielname</label>
            <input id="name" name="name" maxlength="40" value="<?= e((string) App::config('name', '')) ?>"></div>
        <div class="sk-field"><label for="tagline">Untertitel</label>
            <input id="tagline" name="tagline" maxlength="80" value="<?= e((string) App::config('tagline', '')) ?>"></div>
        <div class="sk-field"><label for="logo">Eigenes Logo (Pfad im Projekt, leer = mitgeliefertes)</label>
            <input id="logo" name="logo" maxlength="200" placeholder="assets/img/logo-eigen.svg"
                   value="<?= e((string) App::config('logo', '')) ?>"></div>

        <div class="sk-field"><label for="registration_open">Registrierung</label>
            <select id="registration_open" name="registration_open">
                <option value="1" <?= App::config('registration_open', true) ? 'selected' : '' ?>>offen</option>
                <option value="0" <?= App::config('registration_open', true) ? '' : 'selected' ?>>geschlossen</option>
            </select></div>

        <div class="sk-field"><label for="offline">Offline-Berechnung (Stunden)</label>
            <input id="offline" name="offline" type="number" min="1" max="168"
                   value="<?= e((string) (int) (App::config('max_offline_seconds', 86400) / 3600)) ?>"></div>
        <div class="sk-field"><label for="newbie">Neulingsschutz (Stunden)</label>
            <input id="newbie" name="newbie" type="number" min="0" max="336"
                   value="<?= e((string) (int) (App::config('newbie_protection', 259200) / 3600)) ?>"></div>
        <div class="sk-field"><label for="attacks">Angriffe pro Verteidiger und Tag</label>
            <input id="attacks" name="attacks" type="number" min="1" max="20"
                   value="<?= e((string) App::config('attacks_per_defender', 3)) ?>"></div>

        <h3>Farben</h3>
        <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(130px,1fr));gap:10px">
            <?php foreach ([
                'primary' => 'Hauptfarbe', 'secondary' => 'Akzent (Gold)', 'accent' => 'Erfolg',
                'danger' => 'Warnung', 'sky_top' => 'Himmel oben', 'sky_bottom' => 'Himmel unten',
            ] as $key => $label): ?>
                <div class="sk-field" style="margin:0">
                    <label for="c_<?= e($key) ?>"><?= e($label) ?></label>
                    <input id="c_<?= e($key) ?>" name="<?= e($key) ?>" type="color"
                           value="<?= e((string) ($theme[$key] ?? '#3f8cff')) ?>" style="height:48px;padding:4px">
                </div>
            <?php endforeach; ?>
        </div>

        <h3 style="margin-top:20px">E-Mail-Versand</h3>
        <p class="sk-muted">Ohne E-Mail-Versand setzt du Passwörter im Spielerbereich manuell zurück.</p>
        <div class="sk-field"><label for="mail_enabled">Versand</label>
            <select id="mail_enabled" name="mail_enabled">
                <option value="0" <?= empty($mail['enabled']) ? 'selected' : '' ?>>aus</option>
                <option value="1" <?= !empty($mail['enabled']) ? 'selected' : '' ?>>an</option>
            </select></div>
        <div class="sk-field"><label for="mail_transport">Verfahren</label>
            <select id="mail_transport" name="mail_transport">
                <option value="mail" <?= ($mail['transport'] ?? 'mail') === 'mail' ? 'selected' : '' ?>>PHP-mail()</option>
                <option value="smtp" <?= ($mail['transport'] ?? '') === 'smtp' ? 'selected' : '' ?>>SMTP</option>
            </select></div>
        <div class="sk-field"><label for="mail_from">Absenderadresse</label>
            <input id="mail_from" name="mail_from" type="email" value="<?= e((string) ($mail['from'] ?? '')) ?>"></div>
        <div class="sk-field"><label for="mail_from_name">Absendername</label>
            <input id="mail_from_name" name="mail_from_name" value="<?= e((string) ($mail['from_name'] ?? '')) ?>"></div>
        <div class="sk-field"><label for="smtp_host">SMTP-Server</label>
            <input id="smtp_host" name="smtp_host" value="<?= e((string) ($mail['smtp_host'] ?? '')) ?>"></div>
        <div class="sk-row">
            <div class="sk-field sk-grow"><label for="smtp_port">Port</label>
                <input id="smtp_port" name="smtp_port" type="number" value="<?= e((string) ($mail['smtp_port'] ?? 587)) ?>"></div>
            <div class="sk-field sk-grow"><label for="smtp_secure">Verschlüsselung</label>
                <select id="smtp_secure" name="smtp_secure">
                    <option value="tls" <?= ($mail['smtp_secure'] ?? 'tls') === 'tls' ? 'selected' : '' ?>>TLS</option>
                    <option value="ssl" <?= ($mail['smtp_secure'] ?? '') === 'ssl' ? 'selected' : '' ?>>SSL</option>
                    <option value=""    <?= ($mail['smtp_secure'] ?? '') === '' ? 'selected' : '' ?>>keine</option>
                </select></div>
        </div>
        <div class="sk-field"><label for="smtp_user">SMTP-Benutzer</label>
            <input id="smtp_user" name="smtp_user" value="<?= e((string) ($mail['smtp_user'] ?? '')) ?>"></div>
        <div class="sk-field"><label for="smtp_pass">SMTP-Passwort (leer lassen = unverändert)</label>
            <input id="smtp_pass" name="smtp_pass" type="password" autocomplete="new-password"></div>

        <button class="sk-btn sk-btn--block" type="submit">Einstellungen speichern</button>
    </form>
</div>

<div class="sk-card" style="margin-top:16px">
    <h2>Wartungsmodus</h2>
    <form method="post" action="<?= e(Url::to('admin/?p=settings')) ?>">
        <?= Csrf::field() ?>
        <input type="hidden" name="action" value="maintenance">
        <div class="sk-field"><label for="maintenance">Zustand</label>
            <select id="maintenance" name="maintenance">
                <option value="0" <?= App::config('maintenance', false) ? '' : 'selected' ?>>aus – alle können spielen</option>
                <option value="1" <?= App::config('maintenance', false) ? 'selected' : '' ?>>an – nur Administratoren</option>
            </select></div>
        <div class="sk-field"><label for="note">Hinweistext</label>
            <input id="note" name="note" maxlength="200" value="<?= e((string) App::config('maintenance_note', '')) ?>"></div>
        <button class="sk-btn sk-btn--small" type="submit">Speichern</button>
    </form>
</div>
