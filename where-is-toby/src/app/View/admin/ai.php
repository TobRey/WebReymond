<?php use App\Core\View; $ai = $config['ai']; ?>
<div data-admin-page="ai">
    <section class="panel-box">
        <h2>Status</h2>
        <p>
            Aktueller Dialogmodus:
            <span class="chip <?= $chatMode === 'offline' ? 'chip--warn' : 'chip--ok' ?>"><?= $chatMode === 'offline' ? 'Offline (regelbasiert)' : 'KI aktiv' ?></span>
        </p>
        <p class="hint">
            Im Offline-Modus antworten alle NPCs ueber vorbereitete Dialogregeln, Schluesselwoerter und Beweiszustaende.
            Der mitgelieferte Fall bleibt dabei vollstaendig loesbar. Mit einem KI-Anbieter antworten die Figuren frei.
        </p>
        <?php if (is_array($ai['last_test'] ?? null)): ?>
            <div class="alert <?= ($ai['last_test']['ok'] ?? false) ? 'alert--ok' : 'alert--warn' ?>">
                Letzter Test (<?= View::e(substr((string)$ai['last_test']['at'], 0, 16)) ?>): <?= View::e($ai['last_test']['message']) ?>
            </div>
        <?php endif; ?>
    </section>

    <section class="panel-box">
        <h2>Vorlagen fuer kostenlose Anbieter</h2>
        <p class="hint">Kostenlose Kontingente und Modellnamen aendern sich haeufig. Vorlage anklicken, danach Modellnamen pruefen.</p>
        <div class="grid grid--2">
            <?php foreach ($presets as $preset): ?>
                <article class="tile">
                    <h3><?= View::e($preset['name']) ?></h3>
                    <p><?= View::e($preset['hint']) ?></p>
                    <div class="toolbar" style="margin-top:.6rem">
                        <button class="btn btn--small" data-action="ai-preset"
                                data-provider="<?= View::e($preset['provider']) ?>"
                                data-base="<?= View::e($preset['base_url']) ?>"
                                data-model="<?= View::e($preset['model']) ?>">Vorlage uebernehmen</button>
                    </div>
                </article>
            <?php endforeach; ?>
        </div>
    </section>

    <form class="panel-box" data-role="ai-form">
        <h2>Verbindung</h2>
        <div class="field-grid">
            <label>Anbieter
                <select name="provider">
                    <option value="offline" <?= $ai['provider'] === 'offline' ? 'selected' : '' ?>>Offline-Modus (regelbasiert)</option>
                    <option value="gemini" <?= $ai['provider'] === 'gemini' ? 'selected' : '' ?>>Gemini-kompatibel</option>
                    <option value="openai_compatible" <?= $ai['provider'] === 'openai_compatible' ? 'selected' : '' ?>>OpenAI-kompatibel</option>
                </select>
            </label>
            <label>Basis-URL
                <input type="text" name="base_url" value="<?= View::e($ai['base_url']) ?>" maxlength="300" placeholder="https://...">
                <span class="field-help">Leer lassen fuer den Standard des Anbieters.</span>
            </label>
            <label>Modellname
                <input type="text" name="model" value="<?= View::e($ai['model']) ?>" maxlength="140" placeholder="z. B. gemini-2.0-flash">
            </label>
            <label>API-Schluessel
                <input type="password" name="api_key" maxlength="400" autocomplete="new-password" placeholder="<?= ($ai['api_key_set'] ?? false) ? '•••••• gespeichert' : 'nicht gesetzt' ?>">
                <span class="field-help">Wird verschluesselt gespeichert und nie an den Browser gesendet.
                    <?php if ($ai['api_key_set'] ?? false): ?><button class="linkish" type="button" data-action="ai-clear-key">Schluessel entfernen</button><?php endif; ?>
                </span>
            </label>
            <label>Timeout (Sekunden)<input type="number" name="timeout" value="<?= (int)$ai['timeout'] ?>" min="5" max="120"></label>
            <label>Wiederholungen<input type="number" name="retries" value="<?= (int)$ai['retries'] ?>" min="0" max="5"></label>
            <label>Maximale Antwortlaenge (Tokens)<input type="number" name="max_tokens" value="<?= (int)$ai['max_tokens'] ?>" min="64" max="2048"></label>
            <label>Temperatur<input type="text" name="temperature" value="<?= View::e($ai['temperature']) ?>" maxlength="5"></label>
            <label>Anfragen pro Minute<input type="number" name="rate_per_minute" value="<?= (int)$ai['rate_per_minute'] ?>" min="1" max="120"></label>
            <label>Anfragen pro Stunde<input type="number" name="rate_per_hour" value="<?= (int)$ai['rate_per_hour'] ?>" min="10" max="5000"></label>
            <label class="check full">
                <input type="checkbox" name="fallback_offline" value="1" <?= ($ai['fallback_offline'] ?? true) ? 'checked' : '' ?>>
                Bei KI-Ausfall automatisch auf das regelbasierte Dialogsystem zurueckfallen (empfohlen)
            </label>
        </div>
        <div class="toolbar">
            <button class="btn btn--primary" type="submit">Speichern</button>
            <button class="btn" type="button" data-action="ai-test">Verbindung testen</button>
            <span class="hint" data-role="ai-status"></span>
        </div>
    </form>

    <section class="panel-box">
        <h2>KI-Vorschlaege fuer Fallinhalte</h2>
        <p class="hint">Alle Vorschlaege sind Entwuerfe. Nichts wird automatisch uebernommen - jeder Text muss geprueft,
            bearbeitet und bestaetigt werden. Die Funktion steht im Fall-Editor an den passenden Feldern zur Verfuegung.</p>
    </section>
</div>
