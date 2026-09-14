<?php use App\Core\View; ?>
<div class="toolbar">
    <a class="btn btn--primary" href="<?= View::url('/admin/fall/neu') ?>">Neuen Fall anlegen</a>
    <button class="btn" data-action="import-case">Fall importieren</button>
    <span class="spacer"></span>
    <span class="hint">Veroeffentlichte Faelle sind fuer Spielende sichtbar.</span>
</div>

<section class="panel-box" data-admin-page="cases">
    <table class="table">
        <thead>
        <tr><th>Fall</th><th>Status</th><th>Pruefung</th><th>Umfang</th><th>Aktionen</th></tr>
        </thead>
        <tbody>
        <?php foreach ($cases as $case): $stats = $validation[$case['id']] ?? []; ?>
            <tr>
                <td>
                    <strong><?= View::e($case['title']) ?></strong><br>
                    <small class="hint"><?= View::e($case['code']) ?> · ID <code><?= View::e($case['id']) ?></code></small>
                </td>
                <td><span class="chip <?= $case['status'] === 'published' ? 'chip--ok' : ($case['status'] === 'disabled' ? 'chip--bad' : 'chip--warn') ?>"><?= View::e($case['status']) ?></span></td>
                <td>
                    <?php if (($stats['errors'] ?? 0) > 0): ?>
                        <span class="chip chip--bad"><?= (int)$stats['errors'] ?> Fehler</span>
                    <?php else: ?>
                        <span class="chip chip--ok">fehlerfrei</span>
                    <?php endif; ?>
                    <?php if (($stats['warnings'] ?? 0) > 0): ?>
                        <span class="chip chip--warn"><?= (int)$stats['warnings'] ?> Hinweise</span>
                    <?php endif; ?>
                </td>
                <td class="hint">
                    <?= (int)($stats['npcs'] ?? 0) ?> NPCs · <?= (int)($stats['evidence'] ?? 0) ?> Beweise ·
                    <?= (int)($stats['puzzles'] ?? 0) ?> Raetsel · <?= (int)($stats['media'] ?? 0) ?> Medien
                </td>
                <td>
                    <div class="toolbar" style="margin:0">
                        <a class="btn btn--small" href="<?= View::url('/admin/fall/' . $case['id']) ?>">Editor</a>
                        <a class="btn btn--small" href="<?= View::url('/admin/test/' . $case['id']) ?>">Vorschau</a>
                        <a class="btn btn--small" href="<?= View::url('/api/admin/case/' . $case['id'] . '/export') ?>">Export</a>
                        <?php if ($case['status'] === 'published'): ?>
                            <button class="btn btn--small" data-action="case-status" data-case="<?= View::e($case['id']) ?>" data-status="draft">Zurueckziehen</button>
                        <?php else: ?>
                            <button class="btn btn--small btn--primary" data-action="case-status" data-case="<?= View::e($case['id']) ?>" data-status="published">Veroeffentlichen</button>
                        <?php endif; ?>
                        <button class="btn btn--small" data-action="case-duplicate" data-case="<?= View::e($case['id']) ?>">Duplizieren</button>
                        <button class="btn btn--small btn--danger" data-action="case-delete" data-case="<?= View::e($case['id']) ?>">Loeschen</button>
                    </div>
                </td>
            </tr>
        <?php endforeach; ?>
        <?php if ($cases === []): ?>
            <tr><td colspan="5" class="hint">Es ist kein Fall vorhanden. Der mitgelieferte Fall kann ueber "Fall importieren" eingespielt werden.</td></tr>
        <?php endif; ?>
        </tbody>
    </table>
</section>
