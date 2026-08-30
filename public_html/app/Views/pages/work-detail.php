<?php
/** @var array $item */
/** @var string $locale */

$body = (string) ($locale === 'en' ? ($item['body_en'] ?? '') : ($item['body_de'] ?? ''));
if (trim($body) === '') {
    $body = (string) ($item['body_de'] ?? '');
}
$tags = array_filter(array_map('trim', explode(',', (string) ($item['tags'] ?? ''))));
$live = (string) ($item['live_url'] ?? '');
$preview = (string) ($item['preview_path'] ?? '');
?>
<article class="wa-section" style="padding-block-start: calc(var(--wa-header-h) + 4rem)">
    <div class="wa-container">
        <a class="wa-btn wa-btn--quiet wa-btn--sm" href="<?= e(wa_url('work')) ?>" style="margin-bottom: var(--wa-space-5)">
            &larr; <?= e(__t('nav.back')) ?>
        </a>

        <div class="wa-section__head" data-reveal="up">
            <h1><?= e((string) $item['title']) ?></h1>
            <?php if (($item['subtitle'] ?? '') !== ''): ?>
                <p class="wa-lead"><?= e((string) $item['subtitle']) ?></p>
            <?php endif; ?>

            <?php if ($tags !== []): ?>
                <ul class="wa-tags" role="list" style="margin-top: var(--wa-space-5)">
                    <?php foreach ($tags as $tag): ?><li class="wa-tag"><?= e($tag) ?></li><?php endforeach; ?>
                </ul>
            <?php endif; ?>
        </div>

        <?php if ($preview !== ''): ?>
            <div class="wa-browser" style="aspect-ratio: 16/10; border-radius: var(--wa-radius-lg); border: 1px solid var(--wa-border)"
                 data-reveal="scale">
                <div class="wa-browser__bar" aria-hidden="true">
                    <span class="wa-browser__dot"></span><span class="wa-browser__dot"></span><span class="wa-browser__dot"></span>
                    <span class="wa-browser__url"><?= e(preg_replace('#^https?://#', '', $live) ?: (string) $item['slug']) ?></span>
                </div>
                <iframe class="wa-browser__frame" style="--thumb-scale: 0.55"
                        data-src="<?= e($preview) ?>"
                        title="<?= e(__t('work.preview_label', ['name' => (string) $item['title']])) ?>"
                        loading="lazy" tabindex="-1" aria-hidden="true"
                        sandbox="allow-same-origin" scrolling="no"></iframe>
            </div>
        <?php endif; ?>

        <div class="wa-prose" style="margin-top: var(--wa-space-7)" data-reveal="up">
            <?php foreach (preg_split('/\n{2,}/', trim($body)) ?: [] as $paragraph): ?>
                <p><?= e(trim($paragraph)) ?></p>
            <?php endforeach; ?>

            <?php if ($live !== ''): ?>
                <p>
                    <a class="wa-btn wa-btn--ghost" href="<?= e($live) ?>" target="_blank" rel="noopener noreferrer">
                        <?= e(__t('work.visit')) ?>
                        <?= View_partial('partials/icons', ['name' => 'external']) ?>
                    </a>
                </p>
            <?php endif; ?>
        </div>
    </div>
</article>

<?= View_partial('partials/cta-band') ?>
