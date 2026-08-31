<?php defined('WEBATZE') || exit; ?>
<?php
/** @var array $c @var array $ctx @var string $classes @var string $sectionId @var string $style */
use WebAtze\Templates\Renderer as R;
$items = R::items($c);
$marquee = str_contains($classes, 'marquee');
?>
<section class="<?= e($classes) ?>" id="<?= e($sectionId) ?>"<?= $style !== '' ? ' style="' . e($style) . '"' : '' ?> data-section="logos" data-section-id="<?= (int) $sectionDbId ?>">
    <div class="s-shell">
        <?php if (R::value($c, 'title') !== ''): ?>
            <p class="s-logos__title"><?= e(R::value($c, 'title')) ?></p>
        <?php endif; ?>

        <div class="s-logos__track s-items"<?= $marquee ? ' data-marquee' : '' ?>>
            <?php foreach ($items as $item): ?>
                <div class="s-logo">
                    <?php $image = $item['image'] ?? null; ?>
                    <?php if (is_array($image) && ($image['src'] ?? '') !== ''): ?>
                        <?= R::image(array_merge($image, ['alt' => (string) ($item['name'] ?? '')]), 's-logo__image s-img') ?>
                    <?php else: ?>
                        <span class="s-logo__name"><?= e((string) ($item['name'] ?? '')) ?></span>
                    <?php endif; ?>
                </div>
            <?php endforeach; ?>
        </div>
    </div>
</section>
