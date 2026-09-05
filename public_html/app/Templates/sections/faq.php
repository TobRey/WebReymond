<?php defined('WEBATZE') || exit; ?>
<?php
/** @var array $c @var array $ctx @var string $classes @var string $sectionId @var string $style */
use WebAtze\Templates\Renderer as R;
$items = R::items($c);
$open = str_contains($classes, '--open');
$firstOpen = str_contains($classes, 'first-open');
$numbered = str_contains($classes, 'numbered');
?>
<section class="<?= e($classes) ?>" id="<?= e($sectionId) ?>"<?= $style !== '' ? ' style="' . e($style) . '"' : '' ?> data-section="faq" data-section-id="<?= (int) $sectionDbId ?>"<?= $attrs ?>>
    <div class="s-shell">
        <?= R::head($c, $heading, $titelAttrs) ?>

        <div class="s-faq__list s-items">
            <?php foreach ($items as $index => $item): ?>
                <?php $isOpen = $open || ($firstOpen && $index === 0); ?>
                <details class="s-faq__item"<?= $isOpen ? ' open' : '' ?>>
                    <summary class="s-faq__q">
                        <?php if ($numbered): ?>
                            <span class="s-faq__num s-num" aria-hidden="true"><?= (int) $index + 1 ?></span>
                        <?php endif; ?>
                        <span class="s-faq__q-text"><?= e((string) ($item['question'] ?? '')) ?></span>
                        <span class="s-faq__icon s-ico" aria-hidden="true"></span>
                    </summary>
                    <div class="s-faq__a"><?= R::richtext((string) ($item['answer'] ?? '')) ?></div>
                </details>
            <?php endforeach; ?>
        </div>
    </div>
</section>
