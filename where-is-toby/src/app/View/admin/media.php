<?php use App\Core\View; use App\Service\UploadService; ?>
<div data-admin-page="media">
    <section class="panel-box">
        <h2>Datei hochladen</h2>
        <form data-role="upload-form" class="field-grid">
            <label class="full">Datei
                <input type="file" name="file" required accept=".jpg,.jpeg,.png,.gif,.webp,.svg,.mp3,.wav,.ogg,.mp4,.webm,.pdf">
                <span class="field-help">Erlaubt: <?= View::e(implode(', ', $extensions)) ?> · maximal <?= (int)round($maxBytes / 1048576) ?> MB.
                Bilder werden neu berechnet, Dateinamen zufaellig gesetzt, SVG bereinigt.</span>
            </label>
            <label>Titel
                <input type="text" name="title" maxlength="140" placeholder="z. B. Portrait Peter Swanson">
            </label>
            <label>Kategorie
                <select name="category">
                    <option value="bild">Bild</option>
                    <option value="portrait">Portrait</option>
                    <option value="szene">Szene / Tatort</option>
                    <option value="dokument">Dokument</option>
                    <option value="audio">Audio</option>
                    <option value="video">Video</option>
                </select>
            </label>
            <label class="check full">
                <input type="checkbox" name="rights_confirmed" value="1" required>
                Ich bestaetige, dass ich dieses Material verwenden darf (eigene Aufnahme, freie Lizenz oder Erlaubnis der Rechteinhaber).
            </label>
            <div class="full toolbar">
                <button class="btn btn--primary" type="submit">Hochladen</button>
                <span class="hint" data-role="upload-status"></span>
            </div>
        </form>
    </section>

    <section class="panel-box">
        <h2>Fallgrafiken automatisch erzeugen</h2>
        <?php if (!$gdAvailable): ?>
            <div class="alert alert--warn">Die PHP-Erweiterung <code>gd</code> ist nicht aktiv. Die automatische Bildgenerierung ist deaktiviert.</div>
        <?php else: ?>
            <p class="hint">Aus einem hochgeladenen Portrait entstehen automatisch Vermisstenplakat, FBI-Aktenkarte,
                Profilbild, Kontaktbild, Fall-Cover und eine Schwarz-Weiss-Dokumentversion - alles im gleichen dunklen Stil.
                <?= $fontsOk ? '' : ' (Hinweis: TrueType-Schriften nicht verfuegbar, es wird eine einfache Schrift genutzt.)' ?>
            </p>
            <form data-role="generate-form" class="field-grid">
                <label>Quellbild
                    <select name="id" required>
                        <option value="">-- Portrait waehlen --</option>
                        <?php foreach ($media as $item): ?>
                            <?php if (!in_array($item['extension'] ?? '', ['jpg', 'jpeg', 'png', 'webp', 'gif'], true)) continue; ?>
                            <option value="<?= View::e($item['id']) ?>"><?= View::e($item['title']) ?></option>
                        <?php endforeach; ?>
                    </select>
                </label>
                <label>Name der Person<input type="text" name="name" maxlength="120" placeholder="Peter Swanson"></label>
                <label>Alter<input type="text" name="age" maxlength="10" placeholder="17"></label>
                <label>Zuletzt gesehen<input type="text" name="last_seen" maxlength="80" placeholder="11.10.2024, 23:14 Uhr"></label>
                <label>Ort<input type="text" name="location" maxlength="80" placeholder="Millbrook, Vermont"></label>
                <label>Groesse<input type="text" name="height" maxlength="40" placeholder="178 cm"></label>
                <label>Kleidung<input type="text" name="clothing" maxlength="80" placeholder="Dunkelgruene Jacke"></label>
                <label>Fallnummer<input type="text" name="case_code" maxlength="40" placeholder="WIT-2024-1011"></label>
                <label>Kontakt<input type="text" name="contact" maxlength="60" value="1-800-CALL-FBI"></label>
                <label>Cover-Titel<input type="text" name="title" maxlength="60" placeholder="WHERE IS TOBY?"></label>
                <label>Cover-Untertitel<input type="text" name="subtitle" maxlength="80" placeholder="FBI FIELD INVESTIGATION"></label>
                <label class="full">Aktenvermerk (fuer die Dokumentversion)
                    <textarea name="note" maxlength="400" rows="2"></textarea>
                </label>
                <div class="full toolbar">
                    <button class="btn btn--primary" type="submit">Grafiken erzeugen</button>
                    <span class="hint" data-role="generate-status"></span>
                </div>
            </form>
        <?php endif; ?>
    </section>

    <section class="panel-box">
        <h2>Medienbibliothek</h2>
        <p class="hint">Pfad zur Verwendung im Fall-Editor: <code>uploads/media/DATEINAME</code>.
            Die Dateien werden ausschliesslich ueber den PHP-Endpunkt <code>/medien/...</code> ausgeliefert.</p>
        <div class="media-grid" data-role="media-grid">
            <?php foreach ($media as $item): ?>
                <article class="media-item" data-media="<?= View::e($item['id']) ?>">
                    <?php if (in_array($item['extension'] ?? '', ['jpg', 'jpeg', 'png', 'webp', 'gif', 'svg'], true)): ?>
                        <img src="<?= View::url('/medien/' . $item['file']) ?>" alt="" loading="lazy">
                    <?php else: ?>
                        <div style="aspect-ratio:4/3;display:grid;place-items:center;background:#05070a;font-family:var(--mono)"><?= View::e(strtoupper($item['extension'] ?? '?')) ?></div>
                    <?php endif; ?>
                    <div class="media-item__body">
                        <strong><?= View::e($item['title']) ?></strong>
                        <code>uploads/media/<?= View::e($item['file']) ?></code>
                        <div class="hint"><?= number_format(($item['size'] ?? 0) / 1024, 0, ',', '.') ?> KB · <?= View::e($item['category'] ?? '') ?></div>
                    </div>
                    <div class="media-item__actions">
                        <button class="btn btn--small" data-action="copy-path" data-path="uploads/media/<?= View::e($item['file']) ?>">Pfad kopieren</button>
                        <button class="btn btn--small btn--danger" data-action="media-delete" data-id="<?= View::e($item['id']) ?>">Loeschen</button>
                    </div>
                </article>
            <?php endforeach; ?>
            <?php if ($media === []): ?><p class="hint">Noch keine Dateien hochgeladen.</p><?php endif; ?>
        </div>
    </section>
</div>
