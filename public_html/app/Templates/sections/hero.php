<?php defined('WEBATZE') || exit; ?>
<?php
/** @var array $c @var array $ctx @var string $classes @var string $sectionId @var string $style */
use WebAtze\Templates\Renderer as R;

$badges = R::items($c, 'badges');

// Wohin gehört das Bild?
//   – als Hintergrund: direkt in den Abschnitt, damit es ihn ausfüllen kann
//   – sonst: in das Raster neben den Text, sonst greift die Spaltenzuweisung nicht
$hasMedia = !str_contains($classes, 'no-media');
$coverMedia = $hasMedia && (str_contains($classes, 'media-cover') || str_contains($classes, 'media-corner'));
$inlineMedia = $hasMedia && !$coverMedia;

$media = $hasMedia
    ? '<div class="s-hero__media s-media">'
        . R::image($c['image'] ?? null, 's-hero__image s-img', ['eager' => true, 'label' => 'Grossbild'])
        . '</div>'
    : '';
?>
<section class="<?= e($classes) ?>" id="<?= e($sectionId) ?>"<?= $style !== '' ? ' style="' . e($style) . '"' : '' ?> data-section="hero" data-section-id="<?= (int) $sectionDbId ?>">
    <?php if ($coverMedia): ?>
        <?= $media ?>
    <?php endif; ?>

    <div class="s-shell s-hero__inner s-inner">
        <div class="s-hero__body">
            <?php if (R::value($c, 'eyebrow') !== ''): ?>
                <p class="s-eyebrow"><?= e(R::value($c, 'eyebrow')) ?></p>
            <?php endif; ?>

            <h1 class="s-hero__title"><?= e(R::value($c, 'title')) ?></h1>

            <?php if (R::value($c, 'lead') !== ''): ?>
                <p class="s-hero__lead"><?= e(R::value($c, 'lead')) ?></p>
            <?php endif; ?>

            <?php $cta = R::link($c['cta'] ?? null, 's-btn s-btn--primary s-btn--lg');
                  $cta2 = R::link($c['cta_secondary'] ?? null, 's-btn s-btn--ghost s-btn--lg'); ?>
            <?php if ($cta !== '' || $cta2 !== ''): ?>
                <div class="s-hero__actions"><?= $cta ?><?= $cta2 ?></div>
            <?php endif; ?>

            <?php if ($badges !== []): ?>
                <ul class="s-hero__badges" role="list">
                    <?php foreach ($badges as $badge): ?>
                        <li><?= e((string) ($badge['text'] ?? '')) ?></li>
                    <?php endforeach; ?>
                </ul>
            <?php endif; ?>
        </div>

        <?php if ($inlineMedia): ?>
            <?= $media ?>
        <?php endif; ?>
    </div>
</section>
