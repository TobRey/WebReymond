<?php /* Der Abschluss-Aufruf, der auf mehreren Seiten wiederkehrt. */ ?>
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
