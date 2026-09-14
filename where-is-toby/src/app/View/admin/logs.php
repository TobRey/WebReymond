<?php use App\Core\View; ?>
<div data-admin-page="logs">
    <section class="panel-box">
        <div class="toolbar">
            <label style="display:flex;gap:.5rem;align-items:center">Datei
                <select data-role="log-file">
                    <?php foreach ($files as $file): ?>
                        <option value="<?= View::e($file) ?>" <?= $file === $current ? 'selected' : '' ?>><?= View::e($file) ?></option>
                    <?php endforeach; ?>
                    <?php if ($files === []): ?><option value="app.log">app.log</option><?php endif; ?>
                </select>
            </label>
            <button class="btn btn--small" data-action="log-reload">Aktualisieren</button>
            <button class="btn btn--small btn--danger" data-action="log-clear">Datei leeren</button>
            <span class="spacer"></span>
            <input type="search" data-role="log-filter" placeholder="Filter ...">
        </div>
        <table class="log-table">
            <tbody data-role="log-body">
            <?php foreach ($entries as $entry): ?>
                <tr>
                    <td class="hint"><?= View::e(substr((string)($entry['ts'] ?? ''), 0, 19)) ?></td>
                    <td class="log-level <?= View::e($entry['level'] ?? '') ?>"><?= View::e($entry['level'] ?? '') ?></td>
                    <td><?= View::e($entry['msg'] ?? '') ?></td>
                    <td class="hint"><?= View::e(json_encode($entry['context'] ?? [], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)) ?></td>
                </tr>
            <?php endforeach; ?>
            <?php if ($entries === []): ?><tr><td class="hint">Keine Eintraege.</td></tr><?php endif; ?>
            </tbody>
        </table>
    </section>
</div>
