<?php use App\Core\View; ?>
<div data-admin-page="backups">
    <section class="panel-box">
        <h2>Neue Sicherung</h2>
        <?php if (!$zipAvailable): ?>
            <div class="alert alert--warn">Die PHP-Erweiterung <code>zip</code> fehlt. Sicherungen werden als JSON-Datei erstellt (nur Datenverzeichnis).</div>
        <?php endif; ?>
        <p class="hint">Gesichert werden Konten, Faelle, Spielstaende, Einstellungen und Protokolle. Sitzungen und Zwischenspeicher werden ausgelassen.</p>
        <form data-role="backup-form" class="toolbar">
            <label class="check"><input type="checkbox" name="include_uploads" value="1"> Uploads (Medien) mitsichern</label>
            <button class="btn btn--primary" type="submit">Sicherung erstellen</button>
            <span class="hint" data-role="backup-status"></span>
        </form>
    </section>

    <section class="panel-box">
        <h2>Vorhandene Sicherungen</h2>
        <table class="table">
            <thead><tr><th>Datei</th><th>Groesse</th><th>Erstellt</th><th>Aktionen</th></tr></thead>
            <tbody>
            <?php foreach ($backups as $backup): ?>
                <tr>
                    <td class="mono"><?= View::e($backup['file']) ?></td>
                    <td><?= number_format($backup['size'] / 1024, 0, ',', '.') ?> KB</td>
                    <td class="hint"><?= View::e(substr($backup['created'], 0, 16)) ?></td>
                    <td>
                        <div class="toolbar" style="margin:0">
                            <a class="btn btn--small" href="<?= View::url('/api/admin/backup/download?file=' . urlencode($backup['file'])) ?>">Herunterladen</a>
                            <button class="btn btn--small" data-action="backup-restore" data-file="<?= View::e($backup['file']) ?>">Wiederherstellen</button>
                            <button class="btn btn--small btn--danger" data-action="backup-delete" data-file="<?= View::e($backup['file']) ?>">Loeschen</button>
                        </div>
                    </td>
                </tr>
            <?php endforeach; ?>
            <?php if ($backups === []): ?><tr><td colspan="4" class="hint">Noch keine Sicherung vorhanden.</td></tr><?php endif; ?>
            </tbody>
        </table>
        <p class="hint">Beim Wiederherstellen wird zuvor automatisch eine Sicherheitskopie des aktuellen Stands erstellt.</p>
    </section>
</div>
