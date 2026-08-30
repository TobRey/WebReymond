<?php
/**
 * Fusszeile.
 */

/** @var string $locale */
/** @var array $siteSettings */

$year = date('Y');
$email = $siteSettings['email'] ?? '';
$phone = $siteSettings['phone'] ?? '';

$pages = [
    'services' => __t('nav.services'),
    'process' => __t('nav.process'),
    'work' => __t('nav.work'),
    'contact' => __t('nav.contact'),
];

$legal = [
    'imprint' => __t('nav.imprint'),
    'privacy' => __t('nav.privacy'),
];
?>
<footer class="wa-footer">
    <div class="wa-container">
        <div class="wa-footer__grid">

            <div class="wa-footer__brand">
                <a class="wa-brand" href="<?= e(wa_url()) ?>" aria-label="WebAtze">
                    <?= View_partial('partials/logo', ['size' => 2.1]) ?>
                </a>
                <p class="wa-card__text" style="margin-top: var(--wa-space-4)">
                    <?= e(__t('footer.about')) ?>
                </p>
            </div>

            <div>
                <h2 class="wa-footer__title"><?= e(__t('footer.nav_title')) ?></h2>
                <ul class="wa-footer__list" role="list">
                    <?php foreach ($pages as $key => $label): ?>
                        <li><a href="<?= e(wa_url($key)) ?>"><?= e($label) ?></a></li>
                    <?php endforeach; ?>
                </ul>
            </div>

            <div>
                <h2 class="wa-footer__title"><?= e(__t('footer.legal_title')) ?></h2>
                <ul class="wa-footer__list" role="list">
                    <?php foreach ($legal as $key => $label): ?>
                        <li><a href="<?= e(wa_url($key)) ?>"><?= e($label) ?></a></li>
                    <?php endforeach; ?>
                </ul>
            </div>

            <div>
                <h2 class="wa-footer__title"><?= e(__t('footer.contact_title')) ?></h2>
                <ul class="wa-footer__list" role="list">
                    <?php if ($email !== ''): ?>
                        <li><a href="mailto:<?= e($email) ?>"><?= e($email) ?></a></li>
                    <?php endif; ?>
                    <?php if ($phone !== ''): ?>
                        <li><a href="tel:<?= e(preg_replace('/[^\d+]/', '', $phone)) ?>"><?= e($phone) ?></a></li>
                    <?php endif; ?>
                    <li><a href="<?= e(wa_url('contact')) ?>"><?= e(__t('cta.button')) ?></a></li>
                </ul>
            </div>
        </div>

        <div class="wa-footer__bottom">
            <span>&copy; <?= e($year) ?> WebAtze. <?= e(__t('footer.rights')) ?></span>
            <span><?= e(__t('footer.built')) ?></span>
        </div>
    </div>
</footer>
