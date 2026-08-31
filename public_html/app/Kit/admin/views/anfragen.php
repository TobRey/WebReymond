<?php defined('WEBATZE') || exit; ?>
<?php
/**
 * Anfragen über das Kontaktformular.
 *
 * Sie liegen als Datei neben der Website. Auch wenn der Mailversand des
 * Anbieters einmal klemmt, steht hier alles – deshalb ist diese Liste
 * mehr als eine Zweitschrift.
 */

/** @var array $leads @var string $base */

use WebAtzeKit\Csrf;

$backTo = $base . '?ansicht=anfragen';
?>

<h1 class="k-h1">Anfragen</h1>

<?php if ($leads === []): ?>
    <p class="k-note">
        Noch keine Anfragen. Sobald jemand das Kontaktformular abschickt, steht sie hier –
        und geht zusätzlich per E-Mail hinaus.
    </p>
<?php else: ?>
    <?php foreach ($leads as $lead): ?>
        <article class="k-card k-lead<?= empty($lead['read']) ? ' is-new' : '' ?>">
            <header class="k-lead__head">
                <div>
                    <strong><?= e((string) ($lead['name'] ?? 'Ohne Namen')) ?></strong>
                    <?php if (empty($lead['read'])): ?>
                        <span class="k-dot k-dot--text">neu</span>
                    <?php endif; ?>
                </div>
                <time><?= e(date('d.m.Y H:i', strtotime((string) ($lead['created_at'] ?? 'now')))) ?></time>
            </header>

            <p class="k-lead__contact">
                <a href="mailto:<?= e((string) ($lead['email'] ?? '')) ?>">
                    <?= e((string) ($lead['email'] ?? '')) ?>
                </a>
                <?php if (!empty($lead['phone'])): ?>
                    · <a href="tel:<?= e(preg_replace('/[^\d+]/', '', (string) $lead['phone'])) ?>">
                        <?= e((string) $lead['phone']) ?>
                    </a>
                <?php endif; ?>
                <?php if (empty($lead['mail_sent'])): ?>
                    · <span class="k-warn">nicht per E-Mail zugestellt</span>
                <?php endif; ?>
            </p>

            <?php if (!empty($lead['subject'])): ?>
                <p class="k-lead__subject"><?= e((string) $lead['subject']) ?></p>
            <?php endif; ?>

            <p class="k-lead__message"><?= nl2br(e((string) ($lead['message'] ?? ''))) ?></p>

            <form method="post" action="<?= e($base) ?>">
                <?= Csrf::field() ?>
                <input type="hidden" name="action" value="lead">
                <input type="hidden" name="lead" value="<?= e((string) ($lead['id'] ?? '')) ?>">
                <input type="hidden" name="state" value="<?= empty($lead['read']) ? 'read' : 'unread' ?>">
                <input type="hidden" name="_back" value="<?= e($backTo) ?>">
                <button class="k-btn k-btn--quiet k-btn--sm" type="submit">
                    <?= empty($lead['read']) ? 'Als gelesen markieren' : 'Als ungelesen markieren' ?>
                </button>
            </form>
        </article>
    <?php endforeach; ?>
<?php endif; ?>
