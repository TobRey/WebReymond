<?php
/** @var array $c @var array $ctx @var string $classes @var string $sectionId @var string $style */
use WebAtze\Templates\Renderer as R;
$items = R::items($c);
$captions = str_contains($classes, 'captions');
?>
<section class="<?= e($classes) ?>" id="<?= e($sectionId) ?>"<?= $style !== '' ? ' style="' . e($style) . '"' : '' ?> data-section="gallery" data-section-id="<?= (int) $sectionDbId ?>">
    <div class="s-shell">
        <?= R::head($c) ?>

        <ul class="s-gallery__grid s-items" role="list">
            <?php foreach ($items as $index => $item): ?>
                <li class="s-gallery__item" style="--i: <?= (int) $index ?>">
                    <?= R::image($item['image'] ?? null, 's-gallery__image s-img', [
                        'eager' => $index < 2,
                        'label' => 'Bild ' . ($index + 1),
                    ]) ?>
                    <?php if ($captions && ($item['caption'] ?? '') !== ''): ?>
                        <span class="s-gallery__caption"><?= e((string) $item['caption']) ?></span>
                    <?php endif; ?>
                </li>
            <?php endforeach; ?>
        </ul>
    </div>
</section>
