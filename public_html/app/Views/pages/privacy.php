<?php
use WebAtze\Core\I18n;
$sections = I18n::list('privacy.sections');
?>
<section class="wa-section" style="padding-block-start: calc(var(--wa-header-h) + 4rem)">
    <div class="wa-container wa-container--narrow">
        <div class="wa-section__head" data-reveal="up">
            <h1><?= e(__t('privacy.title')) ?></h1>
            <p class="wa-lead"><?= e(__t('privacy.intro')) ?></p>
        </div>

        <div class="wa-prose" data-reveal="up">
            <?php foreach ($sections as $section): ?>
                <h2><?= e($section['title'] ?? '') ?></h2>
                <p><?= e($section['text'] ?? '') ?></p>
            <?php endforeach; ?>

            <p style="color: var(--wa-text-faint); font-size: var(--wa-text-sm)">
                <?= e(__t('privacy.updated')) ?>: <?= e(date('m.Y')) ?>
            </p>
        </div>
    </div>
</section>
