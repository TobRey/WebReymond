<?php
/**
 * Eine Referenzkarte mit echter Miniatur der fertigen Kundenwebsite.
 *
 * Die Miniatur zeigt die auf diesem Server gespeicherte Kopie. Weil sie
 * von der eigenen Adresse kommt, greift keine fremde Einbettungssperre.
 * Geladen wird sie erst, wenn die Karte in Sichtweite kommt.
 */

/** @var array $item */
/** @var string $locale */

$title = (string) ($item['title'] ?? '');
$slug = (string) ($item['slug'] ?? '');
$subtitle = (string) ($item['subtitle'] ?? '');
$preview = (string) ($item['preview_path'] ?? '');
$liveUrl = (string) ($item['live_url'] ?? '');
$tags = array_filter(array_map('trim', explode(',', (string) ($item['tags'] ?? ''))));

$detailUrl = wa_url('work') . '/' . rawurlencode($slug);
$displayUrl = $liveUrl !== '' ? preg_replace('#^https?://#', '', $liveUrl) : $slug . '.ch';
?>
<a class="wa-work-card" href="<?= e($detailUrl) ?>">
    <div class="wa-browser">
        <div class="wa-browser__bar" aria-hidden="true">
            <span class="wa-browser__dot"></span>
            <span class="wa-browser__dot"></span>
            <span class="wa-browser__dot"></span>
            <span class="wa-browser__url"><?= e($displayUrl) ?></span>
        </div>

        <?php if ($preview !== ''): ?>
            <iframe
                class="wa-browser__frame"
                data-src="<?= e($preview) ?>"
                title="<?= e(__t('work.preview_label', ['name' => $title])) ?>"
                loading="lazy"
                tabindex="-1"
                aria-hidden="true"
                sandbox="allow-same-origin"
                scrolling="no"></iframe>
        <?php else: ?>
            <div class="wa-browser__placeholder" aria-hidden="true">
                <?= e(mb_strtoupper(mb_substr($title, 0, 2))) ?>
            </div>
        <?php endif; ?>
    </div>

    <div class="wa-work-card__body">
        <h3 class="wa-work-card__title"><?= e($title) ?></h3>
        <?php if ($subtitle !== ''): ?>
            <p class="wa-work-card__meta"><?= e($subtitle) ?></p>
        <?php endif; ?>

        <?php if ($tags !== []): ?>
            <ul class="wa-tags" role="list">
                <?php foreach (array_slice($tags, 0, 3) as $tag): ?>
                    <li class="wa-tag"><?= e($tag) ?></li>
                <?php endforeach; ?>
            </ul>
        <?php endif; ?>
    </div>
</a>
