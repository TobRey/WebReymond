<?php
/**
 * Kopfzeile mit Navigation, Sprachumschalter und Themenschalter.
 */

use WebAtze\Core\I18n;

/** @var string $locale */
/** @var string $currentPath */
/** @var string $other */

$links = [
    'services' => __t('nav.services'),
    'process' => __t('nav.process'),
    'work' => __t('nav.work'),
    'contact' => __t('nav.contact'),
];
?>
<header class="wa-header">
    <div class="wa-container wa-header__inner">

        <a class="wa-brand" href="<?= e(wa_url()) ?>" aria-label="WebAtze">
            <?= View_partial('partials/logo', ['size' => 2.1, 'animated' => true]) ?>
        </a>

        <nav class="wa-nav" aria-label="<?= e(__t('nav.menu')) ?>">
            <?php foreach ($links as $key => $label): ?>
                <?php $href = wa_url($key); ?>
                <a class="wa-nav__link" href="<?= e($href) ?>"
                   <?= is_current($href, $currentPath) ? 'aria-current="page"' : '' ?>>
                    <?= e($label) ?>
                </a>
            <?php endforeach; ?>
        </nav>

        <div class="wa-header__actions">

            <a class="wa-chip" href="<?= e(I18n::alternateUrl($currentPath, $other)) ?>"
               hreflang="<?= e($other) ?>"
               aria-label="<?= e(__t('nav.language')) ?>: <?= e($other === 'de' ? __t('nav.german') : __t('nav.english')) ?>">
                <?= e(strtoupper($other)) ?>
            </a>

            <button type="button" class="wa-chip" data-theme-toggle
                    aria-pressed="false"
                    aria-label="<?= e(__t('nav.theme_light')) ?>">
                <span class="wa-theme-icon wa-theme-icon--sun"><?= View_partial('partials/icons', ['name' => 'sun']) ?></span>
                <span class="wa-theme-icon wa-theme-icon--moon"><?= View_partial('partials/icons', ['name' => 'moon']) ?></span>
            </button>

            <a class="wa-btn wa-btn--primary wa-btn--sm wa-header__cta" href="<?= e(wa_url('contact')) ?>" data-magnet="0.22">
                <?= e(__t('cta.button')) ?>
            </a>

            <button type="button" class="wa-burger" data-nav-toggle
                    aria-expanded="false" aria-controls="wa-mobile-nav"
                    aria-label="<?= e(__t('nav.menu')) ?>">
                <span></span><span></span><span></span>
            </button>
        </div>
    </div>
</header>

<div class="wa-mobile-nav" id="wa-mobile-nav" data-nav-panel>
    <nav aria-label="<?= e(__t('nav.menu')) ?>">
        <ul class="wa-mobile-nav__list" role="list">
            <li><a class="wa-mobile-nav__link" href="<?= e(wa_url()) ?>"><?= e(__t('nav.home')) ?></a></li>
            <?php foreach ($links as $key => $label): ?>
                <li><a class="wa-mobile-nav__link" href="<?= e(wa_url($key)) ?>"><?= e($label) ?></a></li>
            <?php endforeach; ?>
        </ul>
    </nav>
</div>
