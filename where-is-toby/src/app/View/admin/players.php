<?php use App\Core\View; ?>
<div data-admin-page="players">
    <section class="panel-box">
        <h2>Neues Konto anlegen</h2>
        <form data-role="user-create" class="field-grid">
            <label>Benutzername<input type="text" name="username" required minlength="3" maxlength="32" pattern="[A-Za-z0-9_.\-]{3,32}"></label>
            <label>Passwort<input type="password" name="password" required minlength="10" maxlength="200" autocomplete="new-password"></label>
            <label>E-Mail (optional)<input type="email" name="email" maxlength="190"></label>
            <label>Rolle
                <select name="role"><option value="player">Spieler</option><option value="admin">Administrator</option></select>
            </label>
            <label class="check full"><input type="checkbox" name="must_change" value="1"> Passwortwechsel beim ersten Login erzwingen</label>
            <div class="full toolbar"><button class="btn btn--primary" type="submit">Konto anlegen</button><span class="hint" data-role="user-status"></span></div>
        </form>
    </section>

    <section class="panel-box">
        <h2>Konten (<?= count($players) ?>)</h2>
        <table class="table">
            <thead><tr><th>Konto</th><th>Rolle</th><th>Letzter Login</th><th>Spielstaende</th><th>Aktionen</th></tr></thead>
            <tbody>
            <?php foreach ($players as $player): ?>
                <tr data-user="<?= View::e($player['id']) ?>">
                    <td>
                        <strong><?= View::e($player['username']) ?></strong><br>
                        <small class="hint"><?= View::e($player['email'] ?? '') ?><?= !empty($player['locked_until']) ? ' · gesperrt' : '' ?></small>
                    </td>
                    <td><span class="chip <?= ($player['role'] ?? '') === 'admin' ? 'chip--ok' : '' ?>"><?= View::e($player['role'] ?? 'player') ?></span></td>
                    <td class="hint mono"><?= View::e(substr((string)($player['last_login'] ?? '-'), 0, 16)) ?></td>
                    <td class="hint">
                        <?php foreach ($progress[$player['id']] ?? [] as $entry): ?>
                            <?= View::e($entry['case']) ?><?= $entry['completed'] ? ' (abgeschlossen ' . View::e($entry['rank']) . ')' : ' (laufend)' ?><br>
                        <?php endforeach; ?>
                        <?php if (($progress[$player['id']] ?? []) === []): ?>-<?php endif; ?>
                    </td>
                    <td>
                        <div class="toolbar" style="margin:0">
                            <button class="btn btn--small" data-action="user-edit" data-id="<?= View::e($player['id']) ?>"
                                    data-username="<?= View::e($player['username']) ?>"
                                    data-role="<?= View::e($player['role'] ?? 'player') ?>"
                                    data-email="<?= View::e($player['email'] ?? '') ?>"
                                    data-agent="<?= View::e($player['agent_name'] ?? '') ?>">Bearbeiten</button>
                            <button class="btn btn--small" data-action="user-reset" data-id="<?= View::e($player['id']) ?>">Fortschritt loeschen</button>
                            <button class="btn btn--small btn--danger" data-action="user-delete" data-id="<?= View::e($player['id']) ?>" data-username="<?= View::e($player['username']) ?>">Konto loeschen</button>
                        </div>
                    </td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </section>

    <div class="panel-box" data-role="user-edit-box" hidden>
        <h2>Konto bearbeiten: <span data-role="edit-name"></span></h2>
        <form data-role="user-edit-form" class="field-grid">
            <input type="hidden" name="id">
            <label>Rolle<select name="role"><option value="player">Spieler</option><option value="admin">Administrator</option></select></label>
            <label>Agentenname<input type="text" name="agent_name" maxlength="60"></label>
            <label>E-Mail<input type="email" name="email" maxlength="190"></label>
            <label>Neues Passwort (optional)<input type="password" name="password" minlength="10" maxlength="200" autocomplete="new-password"></label>
            <label class="check"><input type="checkbox" name="must_change" value="1"> Passwortwechsel erzwingen</label>
            <label class="check"><input type="checkbox" name="unlock" value="1"> Kontosperre aufheben</label>
            <div class="full toolbar">
                <button class="btn btn--primary" type="submit">Speichern</button>
                <button class="btn" type="button" data-action="user-edit-cancel">Abbrechen</button>
            </div>
        </form>
    </div>
</div>
