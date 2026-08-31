<?php defined('WEBATZE') || exit; ?>
<?php
/** @var array $c @var array $ctx @var string $classes @var string $sectionId @var string $style */
use WebAtze\Templates\Renderer as R;
$withPhone = str_contains($classes, 'with-phone');
$hasImage = str_contains($classes, 'media-');
?>
<section class="<?= e($classes) ?>" id="<?= e($sectionId) ?>"<?= $style !== '' ? ' style="' . e($style) . '"' : '' ?> data-section="cta" data-section-id="<?= (int) $sectionDbId ?>">
    <?php if ($hasImage): ?>
        <div class="s-cta__media s-media"><?= R::image($c['image'] ?? null, 's-cta__image s-img', ['label' => '']) ?></div>
    <?php endif; ?>

    <div class="s-shell s-cta__inner s-inner">
        <div class="s-cta__body">
            <h2 class="s-cta__title"><?= e(R::value($c, 'title')) ?></h2>
            <?php if (R::value($c, 'lead') !== ''): ?>
                <p class="s-cta__lead"><?= e(R::value($c, 'lead')) ?></p>
            <?php endif; ?>
        </div>

        <div class="s-cta__actions">
            <?= R::link($c['cta'] ?? null, 's-btn s-btn--primary s-btn--lg') ?>
            <?= R::link($c['cta_secondary'] ?? null, 's-btn s-btn--ghost s-btn--lg') ?>

            <?php if ($withPhone && ($ctx['phone'] ?? '') !== ''): ?>
                <a class="s-cta__phone" href="tel:<?= e(preg_replace('/[^\d+]/', '', (string) $ctx['phone'])) ?>">
                    <?= e((string) $ctx['phone']) ?>
                </a>
            <?php endif; ?>
        </div>
    </div>
</section>
