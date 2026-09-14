<?php use App\Core\View; ?>
<section class="stack">
    <article class="card">
        <h1>Mein Konto</h1>
        <dl class="datalist">
            <dt>Benutzername</dt><dd><?= View::e($account['username'] ?? '') ?></dd>
            <dt>Agentenname</dt><dd><?= View::e($account['agent_name'] ?? '') ?></dd>
            <dt>Rolle</dt><dd><?= View::e($account['role'] ?? 'player') ?></dd>
            <dt>Konto seit</dt><dd><?= View::e(substr((string)($account['created_at'] ?? ''), 0, 10)) ?></dd>
            <dt>Abgeschlossene Faelle</dt><dd><?= (int)($account['stats']['cases_completed'] ?? 0) ?></dd>
            <dt>Bester Rang</dt><dd><?= View::e($account['stats']['best_rank'] ?? '-') ?></dd>
        </dl>
        <?php if (!empty($isGuest)): ?>
            <p class="alert alert--warn">Du spielst als Gast. Der Fortschritt haengt an dieser Browser-Sitzung und geht beim Abmelden verloren.
            Lege ein <a href="<?= View::url('/registrieren') ?>">Konto</a> an, um ihn dauerhaft zu speichern.</p>
        <?php endif; ?>
    </article>

    <?php if (empty($isGuest)): ?>
    <article class="card">
        <h2>Passwort aendern</h2>
        <?php if (!empty($account['must_change_password'])): ?>
            <div class="alert alert--warn">Bitte aendere jetzt das Startpasswort.</div>
        <?php endif; ?>
        <form method="post" action="<?= View::url('/konto/passwort') ?>" class="form-grid">
            <input type="hidden" name="_csrf" value="<?= View::e($csrf) ?>">
            <label>Aktuelles Passwort<input type="password" name="current_password" required maxlength="200" autocomplete="current-password"></label>
            <label>Neues Passwort<input type="password" name="password" required minlength="10" maxlength="200" autocomplete="new-password"></label>
            <label>Wiederholen<input type="password" name="password_repeat" required minlength="10" maxlength="200" autocomplete="new-password"></label>
            <button class="btn btn--primary" type="submit">Passwort speichern</button>
        </form>
    </article>
    <?php endif; ?>

    <article class="card">
        <h2>Spielstaende</h2>
        <?php if ($progress === []): ?>
            <p class="hint">Noch keine gespeicherten Faelle.</p>
        <?php else: ?>
            <table class="table">
                <thead><tr><th>Fall</th><th>Stand</th><th>Zeit</th><th>Rang</th></tr></thead>
                <tbody>
                <?php foreach ($progress as $entry): ?>
                    <tr>
                        <td><?= View::e($cases[$entry['case_id']]['title'] ?? $entry['case_id']) ?></td>
                        <td><?= $entry['completed_at'] !== null ? 'abgeschlossen' : 'laufend' ?></td>
                        <td><?= gmdate('H:i:s', (int)($entry['playtime'] ?? 0)) ?></td>
                        <td><?= View::e($entry['result']['rank'] ?? '-') ?></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        <?php endif; ?>
    </article>

    <article class="card card--danger">
        <h2>Konto loeschen</h2>
        <p>Dabei werden Konto, Spielstaende, Notizen und Gespraeche unwiderruflich entfernt.</p>
        <form method="post" action="<?= View::url('/konto/loeschen') ?>" class="form-grid">
            <input type="hidden" name="_csrf" value="<?= View::e($csrf) ?>">
            <label>Zur Bestaetigung <code>LOESCHEN</code> eingeben
                <input type="text" name="confirm" required maxlength="20" pattern="LOESCHEN">
            </label>
            <button class="btn btn--danger" type="submit">Konto endgueltig loeschen</button>
        </form>
    </article>
</section>
