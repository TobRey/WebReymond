<?php
use WebAtze\Core\I18n;
$steps = I18n::list('process.steps');
$faq = I18n::list('faq.items');
?>
<section class="wa-section" style="padding-block-start: calc(var(--wa-header-h) + 4rem)">
    <div class="wa-container">
        <div class="wa-section__head wa-section__head--center" data-reveal="up">
            <p class="wa-eyebrow wa-eyebrow--center"><?= e(__t('process.eyebrow')) ?></p>
            <h1><?= e(__t('process.title')) ?></h1>
            <p class="wa-lead"><?= e(__t('process.lead')) ?></p>
        </div>

        <div class="wa-process__track" data-reveal-stagger data-reveal="up">
            <?php foreach ($steps as $step): ?>
                <article class="wa-step">
                    <span class="wa-step__num" aria-hidden="true"></span>
                    <h2 class="wa-step__title"><?= e($step['title'] ?? '') ?></h2>
                    <p class="wa-step__text"><?= e($step['text'] ?? '') ?></p>
                </article>
            <?php endforeach; ?>
        </div>
    </div>
</section>

<section class="wa-section">
    <div class="wa-container wa-container--narrow">
        <div class="wa-section__head wa-section__head--center" data-reveal="up">
            <p class="wa-eyebrow wa-eyebrow--center"><?= e(__t('faq.eyebrow')) ?></p>
            <h2><?= e(__t('faq.title')) ?></h2>
        </div>
        <div class="wa-faq" data-reveal="up">
            <?php foreach ($faq as $item): ?>
                <details class="wa-faq__item">
                    <summary class="wa-faq__q">
                        <span><?= e($item['q'] ?? '') ?></span>
                        <span class="wa-faq__icon" aria-hidden="true"></span>
                    </summary>
                    <p class="wa-faq__a"><?= e($item['a'] ?? '') ?></p>
                </details>
            <?php endforeach; ?>
        </div>
    </div>
</section>

<?= View_partial('partials/cta-band') ?>
