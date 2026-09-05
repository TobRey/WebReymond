<?php defined('WEBATZE') || exit; ?>
<?php
/** @var array $c @var array $ctx @var string $classes @var string $sectionId @var string $style */
use WebAtze\Templates\Renderer as R;
$highlights = R::items($c, 'highlights');
$hasMedia = !str_contains($classes, 'no-media');
?>
<section class="<?= e($classes) ?>" id="<?= e($sectionId) ?>"<?= $style !== '' ? ' style="' . e($style) . '"' : '' ?> data-section="about" data-section-id="<?= (int) $sectionDbId ?>"<?= $attrs ?>>
    <div class="s-shell s-about__inner s-inner">
        <?php if ($hasMedia): ?>
            <div class="s-about__media s-media">
                <?= R::image($c['image'] ?? null, 's-about__image s-img', ['label' => 'Bild']) ?>
            </div>
        <?php endif; ?>

        <div class="s-about__body">
            <?php if (R::value($c, 'eyebrow') !== ''): ?>
                <p class="s-eyebrow"><?= e(R::value($c, 'eyebrow')) ?></p>
            <?php endif; ?>
            <<?= $heading ?> class="s-title"><?= e(R::value($c, 'title')) ?></<?= $heading ?>>

            <div class="s-prose"><?= R::richtext(R::value($c, 'body')) ?></div>

            <?php if ($highlights !== []): ?>
                <ul class="s-about__highlights" role="list">
                    <?php foreach ($highlights as $item): ?>
                        <li>
                            <strong><?= e((string) ($item['title'] ?? '')) ?></strong>
                            <?php if (($item['text'] ?? '') !== ''): ?>
                                <span><?= e((string) $item['text']) ?></span>
                            <?php endif; ?>
                        </li>
                    <?php endforeach; ?>
                </ul>
            <?php endif; ?>

            <?= R::link($c['cta'] ?? null, 's-btn s-btn--primary') ?>
        </div>
    </div>
</section>
