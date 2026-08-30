<?php
use WebAtze\Core\{Config, Csrf};
/** @var array $projects @var array $activeJobs @var array $failedJobs @var array $stats */
$base = '/' . trim((string) Config::get('create_path', 'create'), '/');

$statusLabels = [
    'draft' => ['Entwurf', 'new'],
    'building' => ['Wird gebaut', 'running'],
    'ready' => ['Fertig', 'done'],
    'live' => ['Online', 'done'],
    'failed' => ['Fehlgeschlagen', 'failed'],
];
?>

<div class="wa-stats">
    <div class="wa-stat">
        <span class="wa-stat__value"><?= (int) $stats['projects'] ?></span>
        <span class="wa-stat__label">Projekte insgesamt</span>
    </div>
    <div class="wa-stat">
        <span class="wa-stat__value"><?= (int) $stats['live'] ?></span>
        <span class="wa-stat__label">davon online</span>
    </div>
    <div class="wa-stat">
        <span class="wa-stat__value"><?= (int) $stats['leads_new'] ?></span>
        <span class="wa-stat__label">neue Anfragen</span>
    </div>
    <div class="wa-stat">
        <span class="wa-stat__value">$<?= number_format($stats['cost_month'] / 1e6, 2) ?></span>
        <span class="wa-stat__label">KI-Kosten diesen Monat</span>
    </div>
</div>

<?php if ($activeJobs !== []): ?>
    <section class="wa-panel">
        <div class="wa-panel__head">
            <h2 class="wa-panel__title">Läuft gerade</h2>
        </div>
        <?php foreach ($activeJobs as $job): ?>
            <div class="wa-job" data-job-watch="<?= (int) $job['id'] ?>">
                <div class="wa-job__row">
                    <strong><?= e((string) ($job['project_name'] ?? 'Auftrag')) ?></strong>
                    <span class="wa-job__value" data-job-label><?= (int) $job['progress'] ?>%</span>
                </div>
                <div class="wa-progress"><div class="wa-progress__bar" data-job-bar
                     style="--value: <?= (int) $job['progress'] ?>%"></div></div>
                <div class="wa-job__row">
                    <span class="wa-job__step" data-job-step><?= e((string) $job['message']) ?></span>
                    <form method="post" action="/api/jobs/<?= (int) $job['id'] ?>/abbrechen"
                          data-confirm="Diesen Auftrag wirklich abbrechen?">
                        <?= Csrf::field() ?>
                        <button type="submit" class="wa-btn wa-btn--quiet wa-btn--sm">Abbrechen</button>
                    </form>
                </div>
            </div>
        <?php endforeach; ?>
    </section>
<?php endif; ?>

<?php if ($failedJobs !== []): ?>
    <section class="wa-panel">
        <div class="wa-panel__head">
            <h2 class="wa-panel__title">Fehlgeschlagen</h2>
            <p class="wa-panel__hint">Diese Aufträge sind endgültig gescheitert. Sie bleiben stehen,
               bis sie angesehen wurden – niemals verschwindet ein Fehler stillschweigend.</p>
        </div>
        <?php foreach ($failedJobs as $job): ?>
            <div class="wa-note wa-note--danger">
                <div>
                    <strong><?= e((string) ($job['project_name'] ?? 'Auftrag')) ?> · <?= e((string) $job['type']) ?></strong>
                    <?= e((string) $job['error']) ?>
                </div>
            </div>
        <?php endforeach; ?>
    </section>
<?php endif; ?>

<section class="wa-panel">
    <div class="wa-panel__head">
        <h2 class="wa-panel__title">Projekte</h2>
        <a class="wa-btn wa-btn--primary wa-btn--sm" href="<?= e($base) ?>/neu">Neue Website</a>
    </div>

    <?php if ($projects === []): ?>
        <div class="wa-empty-state">
            <p>Noch keine Projekte.</p>
            <a class="wa-btn wa-btn--primary" href="<?= e($base) ?>/neu">Erste Website erstellen</a>
        </div>
    <?php else: ?>
        <div class="wa-table-wrap">
            <table class="wa-table">
                <thead>
                    <tr>
                        <th>Name</th><th>Status</th><th>Seiten</th>
                        <th>Paket</th><th>Domain</th><th>Zuletzt</th>
                    </tr>
                </thead>
                <tbody>
                <?php foreach ($projects as $project): ?>
                    <?php [$label, $tone] = $statusLabels[$project['status']] ?? [$project['status'], '']; ?>
                    <tr>
                        <td><a href="<?= e($base) ?>/projekt/<?= (int) $project['id'] ?>"><?= e((string) $project['name']) ?></a></td>
                        <td><span class="wa-badge wa-badge--<?= e($tone) ?>"><?= e($label) ?></span></td>
                        <td><?= (int) $project['page_count'] ?></td>
                        <td><?= $project['build_version'] ? 'v' . (int) $project['build_version'] : '–' ?></td>
                        <td><?= $project['domain'] !== '' ? e((string) $project['domain']) : '–' ?></td>
                        <td><?= e(date('d.m.Y H:i', strtotime((string) $project['updated_at']))) ?></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    <?php endif; ?>
</section>
