<?php defined('WEBATZE') || exit; ?>
<?php
/** @var array $c @var array $ctx @var string $classes @var string $sectionId @var string $style */
use WebAtze\Templates\Renderer as R;
$items = R::items($c);
$counting = str_contains($classes, 'counting');
?>
<section class="<?= e($classes) ?>" id="<?= e($sectionId) ?>"<?= $style !== '' ? ' style="' . e($style) . '"' : '' ?> data-section="stats" data-section-id="<?= (int) $sectionDbId ?>">
    <div class="s-shell">
        <?php if (R::value($c, 'title') !== ''): ?>
            <h2 class="s-title s-stats__title"><?= e(R::value($c, 'title')) ?></h2>
        <?php endif; ?>

        <dl class="s-stats__grid s-items">
            <?php foreach ($items as $index => $item): ?>
                <div class="s-stat" style="--i: <?= (int) $index ?>">
                    <dt class="s-stat__value"<?= $counting ? ' data-count="' . e((string) ($item['value'] ?? '')) . '"' : '' ?>>
                        <?= e((string) ($item['value'] ?? '')) ?>
                    </dt>
                    <dd class="s-stat__label"><?= e((string) ($item['label'] ?? '')) ?></dd>
                </div>
            <?php endforeach; ?>
        </dl>
    </div>
</section>
