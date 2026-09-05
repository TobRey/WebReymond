<?php defined('WEBATZE') || exit; ?>
<?php
/** @var array $c @var array $ctx @var string $classes @var string $sectionId @var string $style */
use WebAtze\Templates\Renderer as R;
$items = R::items($c);
?>
<section class="<?= e($classes) ?>" id="<?= e($sectionId) ?>"<?= $style !== '' ? ' style="' . e($style) . '"' : '' ?> data-section="features" data-section-id="<?= (int) $sectionDbId ?>"<?= $attrs ?>>
    <div class="s-shell">
        <?= R::head($c, $heading, $titelAttrs) ?>

        <div class="s-features__grid s-items">
            <?php foreach ($items as $index => $item): ?>
                <article class="s-feature" style="--i: <?= (int) $index ?>"<?= $itemAttrs ?>>
                    <span class="s-feature__num s-num" aria-hidden="true"><?= e(str_pad((string) ($index + 1), 2, '0', STR_PAD_LEFT)) ?></span>

                    <?php if (($item['image'] ?? null) !== null && ($item['image']['src'] ?? '') !== ''): ?>
                        <div class="s-feature__media s-media"><?= R::image($item['image'], 's-feature__image s-img') ?></div>
                    <?php elseif (($item['icon'] ?? '') !== ''): ?>
                        <span class="s-feature__icon s-ico"><?= R::icon((string) $item['icon']) ?></span>
                    <?php endif; ?>

                    <h3 class="s-feature__title"><?= e((string) ($item['title'] ?? '')) ?></h3>
                    <p class="s-feature__text"><?= e((string) ($item['text'] ?? '')) ?></p>
                </article>
            <?php endforeach; ?>
        </div>
    </div>
</section>
