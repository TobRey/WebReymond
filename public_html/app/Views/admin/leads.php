<?php
/**
 * Anfragen über das Kontaktformular.
 *
 * Absichtlich als Liste mit ausklappbaren Nachrichten statt als Tabelle:
 * Was zählt, ist der Text der Anfrage, nicht die Spalte.
 */

use WebAtze\Core\{Config, Csrf};

/** @var array $leads @var array $counts @var string $filter */

$base = '/' . trim((string) Config::get('create_path', 'create'), '/');

$statuses = [
    'new' => ['Neu', 'new'],
    'open' => ['In Arbeit', 'running'],
    'done' => ['Erledigt', 'done'],
    'spam' => ['Spam', 'waiting'],
];
?>

<div class="wa-filters">
    <a class="wa-chip<?= $filter === '' ? ' is-active' : '' ?>" href="<?= e($base) ?>/anfragen">
        Alle
    </a>
    <?php foreach ($statuses as $key => [$label, $tone]): ?>
        <a class="wa-chip<?= $filter === $key ? ' is-active' : '' ?>"
           href="<?= e($base) ?>/anfragen?status=<?= e($key) ?>">
            <?= e($label) ?> <span class="wa-chip__count"><?= (int) ($counts[$key] ?? 0) ?></span>
        </a>
    <?php endforeach; ?>
</div>

<?php if ($leads === []): ?>
    <section class="wa-panel">
        <div class="wa-empty-state">
            <p><?= $filter === '' ? 'Noch keine Anfragen.' : 'Keine Anfragen mit diesem Status.' ?></p>
        </div>
    </section>
<?php else: ?>
    <div class="wa-requests">
        <?php foreach ($leads as $lead): ?>
            <?php [$label, $tone] = $statuses[(string) $lead['status']] ?? [(string) $lead['status'], 'waiting']; ?>
            <article class="wa-request">
                <header class="wa-request__head">
                    <div>
                        <strong><?= e(trim((string) $lead['name']) !== '' ? (string) $lead['name'] : 'Ohne Namen') ?></strong>
                        <?php if ((string) $lead['company'] !== ''): ?>
                            <span class="wa-request__meta"><?= e((string) $lead['company']) ?></span>
                        <?php endif; ?>
                        <span class="wa-badge wa-badge--<?= e($tone) ?>"><?= e($label) ?></span>
                    </div>
                    <time datetime="<?= e(date('c', strtotime((string) $lead['created_at']))) ?>">
                        <?= e(date('d.m.Y H:i', strtotime((string) $lead['created_at']))) ?>
                    </time>
                </header>

                <div class="wa-request__contact">
                    <?php if ((string) $lead['email'] !== ''): ?>
                        <a href="mailto:<?= e((string) $lead['email']) ?>"><?= e((string) $lead['email']) ?></a>
                    <?php endif; ?>
                    <?php if ((string) $lead['phone'] !== ''): ?>
                        <a href="tel:<?= e(preg_replace('/[^\d+]/', '', (string) $lead['phone'])) ?>">
                            <?= e((string) $lead['phone']) ?>
                        </a>
                    <?php endif; ?>
                    <?php if (!(bool) $lead['mail_sent']): ?>
                        <span class="wa-badge wa-badge--failed">E-Mail nicht zugestellt</span>
                    <?php endif; ?>
                </div>

                <?php if ((string) $lead['subject'] !== ''): ?>
                    <p class="wa-request__subject"><?= e((string) $lead['subject']) ?></p>
                <?php endif; ?>

                <p class="wa-request__message"><?= nl2br(e((string) $lead['message'])) ?></p>

                <footer class="wa-request__foot">
                    <form method="post" action="<?= e($base) ?>/anfragen/<?= (int) $lead['id'] ?>/status"
                          class="wa-request__actions">
                        <?= Csrf::field() ?>
                        <?php foreach ($statuses as $key => [$statusLabel, ]): ?>
                            <?php if ($key === (string) $lead['status']) { continue; } ?>
                            <button type="submit" name="status" value="<?= e($key) ?>"
                                    class="wa-btn wa-btn--quiet wa-btn--sm">
                                <?= e($statusLabel) ?>
                            </button>
                        <?php endforeach; ?>
                    </form>
                    <span class="wa-request__meta">
                        <?= e((string) $lead['source']) ?> · <?= e((string) $lead['ip']) ?>
                    </span>
                </footer>
            </article>
        <?php endforeach; ?>
    </div>
<?php endif; ?>
