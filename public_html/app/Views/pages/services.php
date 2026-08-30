<?php
use WebAtze\Core\I18n;
$services = ['website', 'redesign', 'backend', 'shop', 'booking', 'care'];
$icons = ['website'=>'rocket','redesign'=>'refresh','backend'=>'sliders','shop'=>'cart','booking'=>'calendar','care'=>'globe'];
?>
<section class="wa-section" style="padding-block-start: calc(var(--wa-header-h) + 4rem)">
    <div class="wa-halo wa-halo--indigo wa-halo--tr" aria-hidden="true"></div>
    <div class="wa-container">
        <div class="wa-section__head" data-reveal="up">
            <p class="wa-eyebrow"><?= e(__t('services.eyebrow')) ?></p>
            <h1><?= e(__t('services.title')) ?></h1>
            <p class="wa-lead"><?= e(__t('services.lead')) ?></p>
        </div>

        <div class="wa-stack wa-stack--lg">
            <?php foreach ($services as $index => $key): ?>
                <?php $points = I18n::list("services.items.$key.points"); ?>
                <article class="wa-split<?= $index % 2 === 1 ? ' wa-split--reverse' : '' ?>"
                         data-reveal="<?= $index % 2 === 1 ? 'right' : 'left' ?>">
                    <div class="wa-stack">
                        <span class="wa-card__icon"><?= View_partial('partials/icons', ['name' => $icons[$key]]) ?></span>
                        <h2><?= e(__t("services.items.$key.title")) ?></h2>
                        <p class="wa-card__text" style="font-size: var(--wa-text-md)">
                            <?= e(__t("services.items.$key.text")) ?>
                        </p>
                        <ul class="wa-card__list" role="list">
                            <?php foreach ($points as $point): ?><li><?= e($point) ?></li><?php endforeach; ?>
                        </ul>
                    </div>
                    <div class="wa-card" data-parallax data-speed="0.4" aria-hidden="true">
                        <span class="wa-card__index" style="opacity:.12; position:static; font-size:6rem">
                            <?= e(str_pad((string) ($index + 1), 2, '0', STR_PAD_LEFT)) ?>
                        </span>
                    </div>
                </article>
            <?php endforeach; ?>
        </div>
    </div>
</section>

<?= View_partial('partials/cta-band') ?>
