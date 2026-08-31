<?php
/**
 * Die Support-Fragen der Kunden.
 *
 * Ungelesene zuerst, danach nach Datum. Antworten geht direkt im Faden –
 * ohne Umweg über eine zweite Seite, weil eine Antwort meist drei Sätze
 * hat und keinen eigenen Bildschirm braucht.
 */

use WebAtze\Core\{Config, Csrf};

/** @var array $threads @var string $filter @var array $counts */

$base = '/' . trim((string) Config::get('create_path', 'create'), '/');
?>

<p class="wa-intro">
    Fragen, die Kunden über <code>/support</code> auf ihrer Website stellen.
    Niemand erwartet eine sofortige Antwort – das ist der Unterschied zu einem Chat.
</p>

<div class="wa-filters">
    <a class="wa-chip<?= $filter === '' ? ' is-active' : '' ?>" href="<?= e($base) ?>/support">
        Alle
    </a>
    <a class="wa-chip<?= $filter === 'open' ? ' is-active' : '' ?>" href="<?= e($base) ?>/support?status=open">
        Offen <span class="wa-chip__count"><?= (int) $counts['open'] ?></span>
    </a>
    <a class="wa-chip<?= $filter === 'done' ? ' is-active' : '' ?>" href="<?= e($base) ?>/support?status=done">
        Erledigt <span class="wa-chip__count"><?= (int) $counts['done'] ?></span>
    </a>
</div>

<?php if ($threads === []): ?>
    <section class="wa-panel">
        <div class="wa-empty-state">
            <p>
                <?= $filter === '' ? 'Noch keine Fragen.' : 'Nichts mit diesem Status.' ?>
                Sobald ein Kunde auf seiner Website unter <code>/support</code> etwas fragt,
                steht es hier.
            </p>
        </div>
    </section>
<?php else: ?>
    <?php foreach ($threads as $thread): ?>
        <?php
        $id = (int) $thread['id'];
        $ungelesen = (bool) $thread['unread_for_owner'];
        $erledigt = (string) $thread['status'] === 'done';
        ?>
        <section class="wa-panel<?= $ungelesen ? ' wa-panel--neu' : '' ?>">
            <div class="wa-panel__head">
                <div>
                    <h2 class="wa-panel__title">
                        <?= e((string) $thread['subject']) ?>
                        <?php if ($ungelesen): ?>
                            <span class="wa-badge wa-badge--new">neu</span>
                        <?php endif; ?>
                        <?php if ($erledigt): ?>
                            <span class="wa-badge wa-badge--done">erledigt</span>
                        <?php endif; ?>
                    </h2>
                    <p class="wa-panel__hint">
                        <?php if ((string) ($thread['project_name'] ?? '') !== ''): ?>
                            <a href="<?= e($base) ?>/projekt/<?= (int) $thread['project_id'] ?>">
                                <?= e((string) $thread['project_name']) ?>
                            </a> ·
                        <?php endif; ?>
                        <?= e((string) $thread['asker_name'] !== '' ? (string) $thread['asker_name'] : 'ohne Namen') ?>
                        <?php if ((string) $thread['asker_email'] !== ''): ?>
                            · <a href="mailto:<?= e((string) $thread['asker_email']) ?>"><?= e((string) $thread['asker_email']) ?></a>
                        <?php endif; ?>
                        · <?= e(date('d.m.Y H:i', strtotime((string) $thread['last_message_at']))) ?>
                    </p>
                </div>

                <form method="post" action="<?= e($base) ?>/support/<?= $id ?>/status">
                    <?= Csrf::field() ?>
                    <input type="hidden" name="status" value="<?= $erledigt ? 'open' : 'done' ?>">
                    <button type="submit" class="wa-btn wa-btn--quiet wa-btn--sm">
                        <?= $erledigt ? 'Wieder öffnen' : 'Erledigt' ?>
                    </button>
                </form>
            </div>

            <div class="wa-faden">
                <?php foreach ($thread['messages'] as $message): ?>
                    <?php $vonMir = (string) $message['author'] === 'owner'; ?>
                    <div class="wa-faden__eintrag<?= $vonMir ? ' is-eigen' : '' ?>">
                        <span class="wa-faden__wer">
                            <?= $vonMir ? 'Du' : e((string) ($thread['asker_name'] ?: 'Kunde')) ?>
                            <time><?= e(date('d.m. H:i', strtotime((string) $message['created_at']))) ?></time>
                        </span>
                        <p><?= nl2br(e((string) $message['body'])) ?></p>
                    </div>
                <?php endforeach; ?>
            </div>

            <form class="wa-form" method="post" action="<?= e($base) ?>/support/<?= $id ?>/antwort">
                <?= Csrf::field() ?>
                <div class="wa-field">
                    <label class="wa-label" for="antwort-<?= $id ?>">Antworten</label>
                    <textarea class="wa-textarea" id="antwort-<?= $id ?>" name="message" rows="3"
                              maxlength="5000" required></textarea>
                    <span class="wa-label__hint">
                        Der Kunde sieht die Antwort auf seiner Website unter <code>/support</code>.
                        Hat er eine Adresse hinterlassen, bekommt er zusätzlich eine kurze Meldung –
                        ohne den Text selbst.
                    </span>
                </div>
                <div class="wa-form__actions">
                    <button type="submit" class="wa-btn wa-btn--primary">Abschicken</button>
                </div>
            </form>
        </section>
    <?php endforeach; ?>
<?php endif; ?>
