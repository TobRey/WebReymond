<?php use App\Core\View; ?>
<div class="grid grid--4">
    <div class="tile">
        <h3>Faelle</h3>
        <div class="big"><?= count($cases) ?></div>
        <p><?= count(array_filter($cases, static fn(array $c): bool => $c['status'] === 'published')) ?> veroeffentlicht</p>
    </div>
    <div class="tile">
        <h3>Konten</h3>
        <div class="big"><?= (int)$userCount ?></div>
        <p><?= (int)$adminCount ?> Administrator(en)</p>
    </div>
    <div class="tile">
        <h3>Dialogmodus</h3>
        <div class="big" style="font-size:1.1rem"><?= $chatMode === 'offline' ? 'OFFLINE' : 'KI AKTIV' ?></div>
        <p><?= View::e($aiProvider) ?><?= $aiModel !== '' ? ' · ' . View::e($aiModel) : '' ?></p>
    </div>
    <div class="tile">
        <h3>Diagnose</h3>
        <div class="big" style="font-size:1.1rem">
            <?= (int)($diagnostics['summary']['fail'] ?? 0) ?> Fehler
        </div>
        <p><?= (int)($diagnostics['summary']['warn'] ?? 0) ?> Hinweise, <?= (int)($diagnostics['summary']['ok'] ?? 0) ?> in Ordnung</p>
    </div>
</div>

<div class="grid grid--2" style="margin-top:1rem">
    <section class="panel-box">
        <h2>Faelle</h2>
        <table class="table">
            <thead><tr><th>Titel</th><th>Status</th><th>Inhalt</th><th></th></tr></thead>
            <tbody>
            <?php foreach ($cases as $case): ?>
                <tr>
                    <td><strong><?= View::e($case['title']) ?></strong><br><small class="hint"><?= View::e($case['code']) ?></small></td>
                    <td><span class="chip <?= $case['status'] === 'published' ? 'chip--ok' : 'chip--warn' ?>"><?= View::e($case['status']) ?></span></td>
                    <td class="hint"><?= (int)$case['counts']['npcs'] ?> NPCs · <?= (int)$case['counts']['evidence'] ?> Beweise · <?= (int)$case['counts']['puzzles'] ?> Raetsel</td>
                    <td>
                        <a class="btn btn--small" href="<?= View::url('/admin/fall/' . $case['id']) ?>">Bearbeiten</a>
                        <a class="btn btn--small" href="<?= View::url('/admin/test/' . $case['id']) ?>">Testen</a>
                    </td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
        <div class="toolbar" style="margin-top:.8rem">
            <a class="btn btn--primary btn--small" href="<?= View::url('/admin/fall/neu') ?>">Neuen Fall anlegen</a>
            <a class="btn btn--small" href="<?= View::url('/admin/faelle') ?>">Alle Faelle</a>
        </div>
    </section>

    <section class="panel-box">
        <h2>Systemzustand</h2>
        <?php foreach ($diagnostics['groups'] as $group): ?>
            <?php
            $fails = array_filter($group['checks'], static fn(array $c): bool => $c['status'] !== 'ok');
            if ($fails === []) continue;
            ?>
            <h3 style="font-size:.8rem;letter-spacing:.14em;text-transform:uppercase;color:var(--ink-dim)"><?= View::e($group['title']) ?></h3>
            <div class="check-list">
                <?php foreach ($fails as $check): ?>
                    <div class="check-row">
                        <span class="status-dot status-<?= View::e($check['status']) ?>"></span>
                        <span><?= View::e($check['name']) ?></span>
                        <span><?= View::e($check['message']) ?><?php if ($check['fix'] !== ''): ?><span class="fix"><br><?= View::e($check['fix']) ?></span><?php endif; ?></span>
                    </div>
                <?php endforeach; ?>
            </div>
        <?php endforeach; ?>
        <?php if (($diagnostics['summary']['fail'] ?? 0) === 0 && ($diagnostics['summary']['warn'] ?? 0) === 0): ?>
            <div class="alert alert--ok">Alle Pruefungen sind in Ordnung.</div>
        <?php endif; ?>
        <div class="toolbar" style="margin-top:.8rem">
            <a class="btn btn--small" href="<?= View::url('/admin/diagnose') ?>">Vollstaendige Diagnose</a>
        </div>
    </section>
</div>

<div class="grid grid--2" style="margin-top:1rem">
    <section class="panel-box">
        <h2>Letzte Ereignisse</h2>
        <table class="log-table">
            <tbody>
            <?php foreach ($logs as $entry): ?>
                <tr>
                    <td class="hint"><?= View::e(substr((string)($entry['ts'] ?? ''), 0, 19)) ?></td>
                    <td class="log-level <?= View::e($entry['level'] ?? '') ?>"><?= View::e($entry['level'] ?? '') ?></td>
                    <td><?= View::e($entry['msg'] ?? '') ?></td>
                </tr>
            <?php endforeach; ?>
            <?php if ($logs === []): ?><tr><td class="hint">Keine Eintraege.</td></tr><?php endif; ?>
            </tbody>
        </table>
    </section>
    <section class="panel-box">
        <h2>Letzte Sicherungen</h2>
        <?php if ($backups === []): ?>
            <p class="hint">Noch keine Sicherung vorhanden.</p>
        <?php else: ?>
            <table class="table">
                <tbody>
                <?php foreach ($backups as $backup): ?>
                    <tr><td class="mono"><?= View::e($backup['file']) ?></td><td><?= number_format($backup['size'] / 1024, 0, ',', '.') ?> KB</td></tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        <?php endif; ?>
        <div class="toolbar" style="margin-top:.8rem">
            <a class="btn btn--small" href="<?= View::url('/admin/sicherung') ?>">Sicherung verwalten</a>
        </div>
    </section>
</div>
