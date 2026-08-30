<?php
/**
 * Einstellungen.
 *
 * Oben steht, was auf der Website erscheint. Unten steht, ob technisch
 * alles läuft – diese Prüfung ist bewusst gnadenlos, damit ein fehlendes
 * Stück nicht erst dann auffällt, wenn ein Kunde wartet.
 */

use WebAtze\Core\{Config, Csrf};

/** @var array $settings @var array|null $user @var bool $aiConfigured */
/** @var bool $imprintComplete @var string $cronHint @var array $diagnostics */

$base = '/' . trim((string) Config::get('create_path', 'create'), '/');
$s = static fn (string $key): string => (string) ($settings[$key] ?? '');

$problems = 0;
foreach ($diagnostics as $check) {
    if (empty($check['ok'])) {
        $problems++;
    }
}
?>

<?php if ($problems > 0): ?>
    <div class="wa-note wa-note--warning">
        <div>
            <?= $problems === 1
                ? 'Ein Punkt der Selbstprüfung ist offen.'
                : $problems . ' Punkte der Selbstprüfung sind offen.' ?>
            Sie stehen weiter unten – solange sie offen sind, fehlt der Anwendung etwas.
        </div>
    </div>
<?php endif; ?>

<?php if (!$imprintComplete): ?>
    <div class="wa-note wa-note--warning">
        <div>
            Für ein vollständiges Impressum fehlen noch Angaben. Ohne sie ist die Website
            in der Schweiz und in der EU nicht rechtskonform.
        </div>
    </div>
<?php endif; ?>

<?php /* ------------------------------------------------------------- Angaben */ ?>
<section class="wa-panel">
    <div class="wa-panel__head">
        <h2 class="wa-panel__title">Angaben auf der Website</h2>
        <p class="wa-panel__hint">
            Diese Werte erscheinen im Kontaktbereich, im Fussbereich und im Impressum.
        </p>
    </div>

    <form class="wa-form" method="post" action="<?= e($base) ?>/einstellungen">
        <?= Csrf::field() ?>
        <input type="hidden" name="action" value="settings">

        <fieldset class="wa-fieldset">
            <legend class="wa-fieldset__legend">Firma</legend>

            <div class="wa-grid-2">
                <div class="wa-field">
                    <label class="wa-label" for="company_name">Firmenname</label>
                    <input class="wa-input" type="text" id="company_name" name="company_name"
                           value="<?= e($s('company_name')) ?>">
                </div>
                <div class="wa-field">
                    <label class="wa-label" for="owner_name">Inhaber</label>
                    <input class="wa-input" type="text" id="owner_name" name="owner_name"
                           value="<?= e($s('owner_name')) ?>">
                </div>
            </div>

            <div class="wa-grid-2">
                <div class="wa-field">
                    <label class="wa-label" for="street">Strasse und Nummer</label>
                    <input class="wa-input" type="text" id="street" name="street"
                           value="<?= e($s('street')) ?>">
                </div>
                <div class="wa-field">
                    <label class="wa-label" for="zip_city">PLZ und Ort</label>
                    <input class="wa-input" type="text" id="zip_city" name="zip_city"
                           value="<?= e($s('zip_city')) ?>">
                </div>
            </div>

            <div class="wa-grid-2">
                <div class="wa-field">
                    <label class="wa-label" for="country">Land</label>
                    <input class="wa-input" type="text" id="country" name="country"
                           value="<?= e($s('country')) ?>">
                </div>
                <div class="wa-field">
                    <label class="wa-label" for="uid">UID / Handelsregister</label>
                    <input class="wa-input" type="text" id="uid" name="uid"
                           value="<?= e($s('uid')) ?>"
                           placeholder="CHE-123.456.789">
                    <span class="wa-label__hint">Nur nötig, wenn im Register eingetragen.</span>
                </div>
            </div>
        </fieldset>

        <fieldset class="wa-fieldset">
            <legend class="wa-fieldset__legend">Erreichbarkeit</legend>

            <div class="wa-grid-2">
                <div class="wa-field">
                    <label class="wa-label" for="email">E-Mail</label>
                    <input class="wa-input" type="email" id="email" name="email"
                           value="<?= e($s('email')) ?>">
                    <span class="wa-label__hint">An diese Adresse gehen die Anfragen aus dem Formular.</span>
                </div>
                <div class="wa-field">
                    <label class="wa-label" for="phone">Telefon</label>
                    <input class="wa-input" type="text" id="phone" name="phone"
                           value="<?= e($s('phone')) ?>">
                </div>
            </div>

            <div class="wa-grid-2">
                <div class="wa-field">
                    <label class="wa-label" for="whatsapp">WhatsApp</label>
                    <input class="wa-input" type="text" id="whatsapp" name="whatsapp"
                           value="<?= e($s('whatsapp')) ?>"
                           placeholder="+41 79 000 00 00">
                </div>
                <div class="wa-field">
                    <label class="wa-label" for="instagram">Instagram</label>
                    <input class="wa-input" type="text" id="instagram" name="instagram"
                           value="<?= e($s('instagram')) ?>">
                </div>
            </div>

            <div class="wa-grid-2">
                <div class="wa-field">
                    <label class="wa-label" for="linkedin">LinkedIn</label>
                    <input class="wa-input" type="text" id="linkedin" name="linkedin"
                           value="<?= e($s('linkedin')) ?>">
                </div>
                <div class="wa-field">
                    <label class="wa-label" for="hero_available">Auftragslage im Aufmacher</label>
                    <select class="wa-select" id="hero_available" name="hero_available">
                        <option value="1" <?= $s('hero_available') === '1' ? 'selected' : '' ?>>
                            „Nimmt neue Projekte an" anzeigen
                        </option>
                        <option value="0" <?= $s('hero_available') === '0' ? 'selected' : '' ?>>
                            nicht anzeigen
                        </option>
                    </select>
                </div>
            </div>
        </fieldset>

        <fieldset class="wa-fieldset">
            <legend class="wa-fieldset__legend">Impressum</legend>
            <div class="wa-field">
                <label class="wa-label" for="imprint_extra">Zusätzlicher Text</label>
                <textarea class="wa-textarea" id="imprint_extra" name="imprint_extra"
                          rows="6"><?= e($s('imprint_extra')) ?></textarea>
                <span class="wa-label__hint">
                    Wird unter den Pflichtangaben ausgegeben – etwa Haftungsausschluss oder
                    Angaben zur Aufsichtsbehörde.
                </span>
            </div>
        </fieldset>

        <div class="wa-form__actions">
            <button type="submit" class="wa-btn wa-btn--primary">Einstellungen speichern</button>
        </div>
    </form>
</section>

<?php /* ------------------------------------------------------------ Passwort */ ?>
<section class="wa-panel">
    <div class="wa-panel__head">
        <h2 class="wa-panel__title">Passwort ändern</h2>
        <p class="wa-panel__hint">
            Angemeldet als <strong><?= e((string) ($user['username'] ?? '')) ?></strong>.
            Nach der Änderung werden alle Sitzungen beendet – auch diese hier.
        </p>
    </div>

    <form class="wa-form" method="post" action="<?= e($base) ?>/einstellungen" autocomplete="off">
        <?= Csrf::field() ?>
        <input type="hidden" name="action" value="password">

        <div class="wa-field">
            <label class="wa-label" for="current_password">Bisheriges Passwort</label>
            <input class="wa-input" type="password" id="current_password" name="current_password"
                   autocomplete="current-password" required>
        </div>

        <div class="wa-grid-2">
            <div class="wa-field">
                <label class="wa-label" for="new_password">Neues Passwort</label>
                <input class="wa-input" type="password" id="new_password" name="new_password"
                       autocomplete="new-password" minlength="10" required>
                <span class="wa-label__hint">Mindestens 10 Zeichen.</span>
            </div>
            <div class="wa-field">
                <label class="wa-label" for="repeat_password">Wiederholen</label>
                <input class="wa-input" type="password" id="repeat_password" name="repeat_password"
                       autocomplete="new-password" minlength="10" required>
            </div>
        </div>

        <div class="wa-form__actions">
            <button type="submit" class="wa-btn">Passwort ändern</button>
        </div>
    </form>
</section>

<?php /* ----------------------------------------------------------- Cronjob */ ?>
<section class="wa-panel">
    <div class="wa-panel__head">
        <h2 class="wa-panel__title">Cronjob</h2>
        <p class="wa-panel__hint">
            In cPanel unter „Erweitert → Cron-Jobs" einmal pro Minute ausführen. Ohne ihn
            werden Aufträge nur beim Anlegen bearbeitet und alte Vorschauen nie aufgeräumt.
        </p>
    </div>
    <pre class="wa-code"><code><?= e($cronHint) ?></code></pre>
</section>

<?php /* ------------------------------------------------------ Selbstprüfung */ ?>
<section class="wa-panel">
    <div class="wa-panel__head">
        <h2 class="wa-panel__title">Selbstprüfung</h2>
        <p class="wa-panel__hint">
            <?php if ($aiConfigured): ?>
                Der Anthropic-Schlüssel ist hinterlegt – der Generator arbeitet mit echten Texten.
            <?php else: ?>
                Ohne Anthropic-Schlüssel läuft der Generator im Übungsmodus: Struktur und
                Vorlagen entstehen, die Texte sind Platzhalter.
            <?php endif; ?>
        </p>
    </div>

    <div class="wa-table-wrap">
        <table class="wa-table">
            <thead><tr><th></th><th>Punkt</th><th>Stand</th><th>Wozu</th></tr></thead>
            <tbody>
            <?php foreach ($diagnostics as $check): ?>
                <tr>
                    <td>
                        <span class="wa-badge wa-badge--<?= !empty($check['ok']) ? 'done' : 'failed' ?>">
                            <?= !empty($check['ok']) ? 'ok' : 'offen' ?>
                        </span>
                    </td>
                    <td><?= e((string) $check['label']) ?></td>
                    <td><?= e((string) $check['value']) ?></td>
                    <td><?= e((string) $check['hint']) ?></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</section>
