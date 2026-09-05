<?php defined('WEBATZE') || exit; ?>
<?php
/** @var array $c @var array $ctx @var string $classes @var string $sectionId @var string $style */
use WebAtze\Templates\Renderer as R;
$items = R::items($c);
$accordion = str_contains($classes, '--accordion');
?>
<section class="<?= e($classes) ?>" id="<?= e($sectionId) ?>"<?= $style !== '' ? ' style="' . e($style) . '"' : '' ?> data-section="services" data-section-id="<?= (int) $sectionDbId ?>"<?= $attrs ?>>
    <div class="s-shell">
        <?= R::head($c, $heading) ?>

        <div class="s-services__grid s-items">
            <?php foreach ($items as $index => $item): ?>
                <?php if ($accordion): ?>
                    <details class="s-service s-service--foldable"<?= $index === 0 ? ' open' : '' ?>>
                        <summary class="s-service__summary">
                            <?php if (($item['icon'] ?? '') !== ''): ?>
                                <span class="s-service__icon s-ico"><?= R::icon((string) $item['icon']) ?></span>
                            <?php endif; ?>
                            <span><?= e((string) ($item['title'] ?? '')) ?></span>
                        </summary>
                        <div class="s-service__body">
                            <p class="s-service__text"><?= e((string) ($item['text'] ?? '')) ?></p>
                            <?= R::link($item['link'] ?? null, 's-link') ?>
                        </div>
                    </details>
                <?php else: ?>
                    <article class="s-service" style="--i: <?= (int) $index ?>">
                        <?php if (($item['image'] ?? null) !== null && ($item['image']['src'] ?? '') !== ''): ?>
                            <div class="s-service__media s-media"><?= R::image($item['image'], 's-service__image s-img') ?></div>
                        <?php elseif (($item['icon'] ?? '') !== ''): ?>
                            <span class="s-service__icon s-ico"><?= R::icon((string) $item['icon']) ?></span>
                        <?php endif; ?>

                        <span class="s-service__num s-num" aria-hidden="true"><?= (int) $index + 1 ?></span>
                        <h3 class="s-service__title"><?= e((string) ($item['title'] ?? '')) ?></h3>
                        <p class="s-service__text"><?= e((string) ($item['text'] ?? '')) ?></p>
                        <?= R::link($item['link'] ?? null, 's-link') ?>
                    </article>
                <?php endif; ?>
            <?php endforeach; ?>
        </div>

        <?php $cta = R::link($c['cta'] ?? null, 's-btn s-btn--primary'); ?>
        <?php if ($cta !== ''): ?>
            <div class="s-services__foot"><?= $cta ?></div>
        <?php endif; ?>
    </div>
</section>
