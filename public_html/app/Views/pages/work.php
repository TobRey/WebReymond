<?php
/** @var array $showcase */
/** @var string $locale */
?>
<section class="wa-section" style="padding-block-start: calc(var(--wa-header-h) + 4rem)">
    <div class="wa-halo wa-halo--teal wa-halo--tl" aria-hidden="true"></div>
    <div class="wa-container">
        <div class="wa-section__head" data-reveal="up">
            <p class="wa-eyebrow"><?= e(__t('work.eyebrow')) ?></p>
            <h1><?= e(__t('work.title')) ?></h1>
            <p class="wa-lead"><?= e(__t('work.lead')) ?></p>
        </div>

        <?php if ($showcase === []): ?>
            <p class="wa-empty"><?= e(__t('work.empty')) ?></p>
        <?php else: ?>
            <div class="wa-work__grid" data-reveal-stagger data-reveal="up">
                <?php foreach ($showcase as $item): ?>
                    <?= View_partial('partials/work-card', ['item' => $item, 'locale' => $locale]) ?>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>
    </div>
</section>

<?= View_partial('partials/cta-band') ?>
