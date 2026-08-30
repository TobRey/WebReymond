<?php
/** @var array $c @var array $ctx @var string $classes @var string $sectionId @var string $style */
use WebAtze\Templates\Renderer as R;
$items = R::items($c);
$rated = str_contains($classes, 'rated');
$avatar = str_contains($classes, 'avatar') || str_contains($classes, 'logo');
?>
<section class="<?= e($classes) ?>" id="<?= e($sectionId) ?>"<?= $style !== '' ? ' style="' . e($style) . '"' : '' ?> data-section="testimonials">
    <div class="s-shell">
        <?= R::head($c) ?>

        <div class="s-testimonials__grid s-items">
            <?php foreach ($items as $index => $item): ?>
                <figure class="s-quote" style="--i: <?= (int) $index ?>">
                    <?php if ($rated): ?>
                        <?php $stars = max(0, min(5, (int) ($item['rating'] ?? 5))); ?>
                        <div class="s-quote__rating" role="img"
                             aria-label="<?= (int) $stars ?> von 5 Sternen">
                            <?php for ($i = 0; $i < 5; $i++): ?>
                                <span class="s-star<?= $i < $stars ? ' is-on' : '' ?>" aria-hidden="true">
                                    <?= R::icon('star', 's-star__icon s-ico') ?>
                                </span>
                            <?php endfor; ?>
                        </div>
                    <?php endif; ?>

                    <blockquote class="s-quote__text"><?= e((string) ($item['quote'] ?? '')) ?></blockquote>

                    <figcaption class="s-quote__by">
                        <?php if ($avatar): ?>
                            <?= R::image($item['image'] ?? null, 's-quote__avatar s-img', ['label' => '']) ?>
                        <?php endif; ?>
                        <span>
                            <strong><?= e((string) ($item['name'] ?? '')) ?></strong>
                            <?php if (($item['role'] ?? '') !== ''): ?>
                                <em><?= e((string) $item['role']) ?></em>
                            <?php endif; ?>
                        </span>
                    </figcaption>
                </figure>
            <?php endforeach; ?>
        </div>
    </div>
</section>
