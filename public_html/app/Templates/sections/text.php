<?php defined('WEBATZE') || exit; ?>
<?php
/** @var array $c @var array $ctx @var string $classes @var string $sectionId @var string $style */
use WebAtze\Templates\Renderer as R;
?>
<section class="<?= e($classes) ?>" id="<?= e($sectionId) ?>"<?= $style !== '' ? ' style="' . e($style) . '"' : '' ?> data-section="text" data-section-id="<?= (int) $sectionDbId ?>"<?= $attrs ?>>
    <div class="s-shell s-text__inner s-inner">
        <?php if (R::value($c, 'eyebrow') !== '' || R::value($c, 'title') !== ''): ?>
            <div class="s-text__head">
                <?php if (R::value($c, 'eyebrow') !== ''): ?>
                    <p class="s-eyebrow"><?= e(R::value($c, 'eyebrow')) ?></p>
                <?php endif; ?>
                <?php if (R::value($c, 'title') !== ''): ?>
                    <<?= $heading ?> class="s-title"<?= $titelAttrs ?>><?= e(R::value($c, 'title')) ?></<?= $heading ?>>
                <?php endif; ?>
            </div>
        <?php endif; ?>

        <div class="s-prose"><?= R::richtext(R::value($c, 'body')) ?></div>
    </div>
</section>
