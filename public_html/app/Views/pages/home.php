<?php
/**
 * Die Startseite.
 *
 * Nirgends auf dieser Seite steht, wie die Websites entstehen.
 * Das ist Absicht und darf beim Bearbeiten nicht verloren gehen.
 */

use WebAtze\Core\I18n;

/** @var array $showcase   Referenzen aus der Datenbank */
/** @var string $locale */

$services = ['website', 'redesign', 'backend', 'shop', 'booking', 'care'];
$serviceIcons = [
    'website' => 'rocket',
    'redesign' => 'refresh',
    'backend' => 'sliders',
    'shop' => 'cart',
    'booking' => 'calendar',
    'care' => 'globe',
];

$whyIcons = ['bolt', 'rocket', 'check', 'chat', 'shield', 'unlock'];
$heroLines = I18n::list('hero.title_lines');
$badges = I18n::list('hero.badges');
$marquee = I18n::list('marquee');
$steps = I18n::list('process.steps');
$whyItems = I18n::list('why.items');
$faqItems = I18n::list('faq.items');
?>

<?php /* ============================ Hero ============================ */ ?>
<section class="wa-hero">
    <div class="wa-gridlines" aria-hidden="true"></div>

    <div class="wa-container wa-hero__inner">

        <p class="wa-hero__badge">
            <span class="wa-hero__badge-dot" aria-hidden="true"></span>
            <?= e(__t('hero.eyebrow')) ?>
        </p>

        <h1 class="wa-hero__title">
            <?php foreach ($heroLines as $index => $line): ?>
                <span class="wa-hero__line<?= $index === count($heroLines) - 1 ? ' wa-hero__line--accent' : '' ?>"
                      style="--i: <?= (int) $index ?>">
                    <span><?= e($line) ?></span>
                </span>
            <?php endforeach; ?>
        </h1>

        <p class="wa-hero__lead" data-reveal="up" style="--reveal-delay: 520ms">
            <?= e(__t('hero.lead')) ?>
        </p>

        <div class="wa-hero__actions" data-reveal="up" style="--reveal-delay: 640ms">
            <a class="wa-btn wa-btn--primary wa-btn--lg" href="<?= e(wa_url('contact')) ?>" data-magnet="0.28">
                <?= e(__t('hero.cta_primary')) ?>
                <span class="wa-btn__arrow"><?= View_partial('partials/icons', ['name' => 'arrow-right']) ?></span>
            </a>
            <a class="wa-btn wa-btn--ghost wa-btn--lg" href="#referenzen" data-magnet="0.18">
                <?= e(__t('hero.cta_secondary')) ?>
            </a>
        </div>

        <ul class="wa-hero__badges" role="list" data-reveal="up" style="--reveal-delay: 760ms">
            <?php foreach ($badges as $badge): ?>
                <li><?= e($badge) ?></li>
            <?php endforeach; ?>
        </ul>
    </div>

    <a class="wa-hero__scroll" href="#leistungen" aria-label="<?= e(__t('hero.scroll')) ?>">
        <span><?= e(__t('hero.scroll')) ?></span>
        <span class="wa-hero__scroll-line" aria-hidden="true"></span>
    </a>
</section>

<?php /* ========================== Laufband =========================== */ ?>
<div class="wa-marquee" aria-hidden="true">
    <div class="wa-marquee__track">
        <?php foreach ($marquee as $word): ?>
            <span class="wa-marquee__item"><?= e($word) ?></span>
        <?php endforeach; ?>
    </div>
</div>

<?php /* ========================= Leistungen ========================== */ ?>
<section class="wa-section" id="leistungen">
    <div class="wa-halo wa-halo--indigo wa-halo--tl" aria-hidden="true"></div>

    <div class="wa-container">
        <div class="wa-section__head" data-reveal="up">
            <p class="wa-eyebrow"><?= e(__t('services.eyebrow')) ?></p>
            <h2><?= e(__t('services.title')) ?></h2>
            <p class="wa-lead"><?= e(__t('services.lead')) ?></p>
        </div>

        <div class="wa-grid wa-grid--3" data-reveal-stagger data-reveal="up">
            <?php foreach ($services as $key): ?>
                <?php $points = I18n::list("services.items.$key.points"); ?>
                <article class="wa-card wa-card--tilt">
                    <span class="wa-card__icon">
                        <?= View_partial('partials/icons', ['name' => $serviceIcons[$key]]) ?>
                    </span>
                    <h3 class="wa-card__title"><?= e(__t("services.items.$key.title")) ?></h3>
                    <p class="wa-card__text"><?= e(__t("services.items.$key.text")) ?></p>
                    <ul class="wa-card__list" role="list">
                        <?php foreach ($points as $point): ?>
                            <li><?= e($point) ?></li>
                        <?php endforeach; ?>
                    </ul>
                </article>
            <?php endforeach; ?>
        </div>
    </div>
</section>

<?php /* =========================== Ablauf ============================ */ ?>
<section class="wa-section wa-process" id="ablauf">
    <div class="wa-container">
        <div class="wa-section__head wa-section__head--center" data-reveal="up">
            <p class="wa-eyebrow wa-eyebrow--center"><?= e(__t('process.eyebrow')) ?></p>
            <h2><?= e(__t('process.title')) ?></h2>
            <p class="wa-lead"><?= e(__t('process.lead')) ?></p>
        </div>

        <div class="wa-process__track" data-reveal-stagger data-reveal="up">
            <?php foreach ($steps as $step): ?>
                <article class="wa-step">
                    <span class="wa-step__num" aria-hidden="true"></span>
                    <h3 class="wa-step__title"><?= e($step['title'] ?? '') ?></h3>
                    <p class="wa-step__text"><?= e($step['text'] ?? '') ?></p>
                </article>
            <?php endforeach; ?>
        </div>
    </div>
</section>

<?php /* ======================== Warum WebAtze ======================== */ ?>
<section class="wa-section" id="warum">
    <div class="wa-halo wa-halo--teal wa-halo--br" aria-hidden="true"></div>

    <div class="wa-container">
        <div class="wa-section__head" data-reveal="up" data-parallax="deep" data-speed="0.5">
            <p class="wa-eyebrow"><?= e(__t('why.eyebrow')) ?></p>
            <h2><?= e(__t('why.title')) ?></h2>
            <p class="wa-lead"><?= e(__t('why.lead')) ?></p>
        </div>

        <div class="wa-why__grid" data-reveal-stagger data-reveal="up">
            <?php foreach ($whyItems as $index => $item): ?>
                <article class="wa-card wa-card--tilt" data-tilt-strength="5">
                    <span class="wa-card__index" aria-hidden="true"><?= e(str_pad((string) ($index + 1), 2, '0', STR_PAD_LEFT)) ?></span>
                    <span class="wa-card__icon">
                        <?= View_partial('partials/icons', ['name' => $whyIcons[$index] ?? 'check']) ?>
                    </span>
                    <h3 class="wa-card__title"><?= e($item['title'] ?? '') ?></h3>
                    <p class="wa-card__text"><?= e($item['text'] ?? '') ?></p>
                </article>
            <?php endforeach; ?>
        </div>
    </div>
</section>

<?php /* ========================= Referenzen ========================== */ ?>
<section class="wa-section" id="referenzen">
    <div class="wa-container">
        <div class="wa-section__head" data-reveal="up">
            <p class="wa-eyebrow"><?= e(__t('work.eyebrow')) ?></p>
            <h2><?= e(__t('work.title')) ?></h2>
            <p class="wa-lead"><?= e(__t('work.lead')) ?></p>
        </div>

        <?php if ($showcase === []): ?>
            <p class="wa-empty"><?= e(__t('work.empty')) ?></p>
        <?php else: ?>
            <div class="wa-work__grid" data-reveal-stagger data-reveal="up">
                <?php foreach (array_slice($showcase, 0, 6) as $item): ?>
                    <?= View_partial('partials/work-card', ['item' => $item, 'locale' => $locale]) ?>
                <?php endforeach; ?>
            </div>

            <?php if (count($showcase) > 6): ?>
                <div class="wa-row wa-row--center" style="margin-top: var(--wa-space-7)">
                    <a class="wa-btn wa-btn--ghost" href="<?= e(wa_url('work')) ?>" data-magnet="0.2">
                        <?= e(__t('work.title')) ?>
                        <span class="wa-btn__arrow"><?= View_partial('partials/icons', ['name' => 'arrow-right']) ?></span>
                    </a>
                </div>
            <?php endif; ?>
        <?php endif; ?>
    </div>
</section>

<?php /* ============================= FAQ ============================= */ ?>
<section class="wa-section" id="fragen">
    <div class="wa-container wa-container--narrow">
        <div class="wa-section__head wa-section__head--center" data-reveal="up">
            <p class="wa-eyebrow wa-eyebrow--center"><?= e(__t('faq.eyebrow')) ?></p>
            <h2><?= e(__t('faq.title')) ?></h2>
        </div>

        <div class="wa-faq" data-reveal="up">
            <?php foreach ($faqItems as $item): ?>
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

<?php /* ======================== Abschluss-Aufruf ===================== */ ?>
<section class="wa-section wa-section--loose">
    <div class="wa-container">
        <div class="wa-cta" data-reveal="scale">
            <div class="wa-halo wa-halo--indigo wa-halo--tr" aria-hidden="true"></div>

            <h2 class="wa-cta__title"><?= e(__t('cta.title')) ?></h2>
            <p class="wa-cta__lead"><?= e(__t('cta.lead')) ?></p>

            <div class="wa-cta__actions">
                <a class="wa-btn wa-btn--primary wa-btn--lg" href="<?= e(wa_url('contact')) ?>" data-magnet="0.3">
                    <?= e(__t('cta.button')) ?>
                    <span class="wa-btn__arrow"><?= View_partial('partials/icons', ['name' => 'arrow-right']) ?></span>
                </a>
            </div>
        </div>
    </div>
</section>
