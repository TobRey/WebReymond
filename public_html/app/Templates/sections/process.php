<?php defined('WEBATZE') || exit; ?>
<?php
/** @var array $c @var array $ctx @var string $classes @var string $sectionId @var string $style */
use WebAtze\Templates\Renderer as R;
$items = R::items($c);
$numbered = str_contains($classes, 'numbered');
?>
<section class="<?= e($classes) ?>" id="<?= e($sectionId) ?>"<?= $style !== '' ? ' style="' . e($style) . '"' : '' ?> data-section="process" data-section-id="<?= (int) $sectionDbId ?>"<?= $attrs ?>>
    <div class="s-shell">
        <?= R::head($c, $heading, $titelAttrs) ?>

        <ol class="s-process__track s-items" role="list">
            <?php foreach ($items as $index => $item): ?>
                <li class="s-step" style="--i: <?= (int) $index ?>"<?= $itemAttrs ?>>
                    <?php if ($numbered): ?>
                        <span class="s-step__num s-num" aria-hidden="true"><?= (int) $index + 1 ?></span>
                    <?php elseif (($item['icon'] ?? '') !== ''): ?>
                        <span class="s-step__icon s-ico"><?= R::icon((string) $item['icon']) ?></span>
                    <?php endif; ?>

                    <h3 class="s-step__title"><?= e((string) ($item['title'] ?? '')) ?></h3>
                    <p class="s-step__text"><?= e((string) ($item['text'] ?? '')) ?></p>
                </li>
            <?php endforeach; ?>
        </ol>
    </div>
</section>
