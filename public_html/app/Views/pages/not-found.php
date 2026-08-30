<section class="wa-error-page">
    <div class="wa-container">
        <p class="wa-error-page__code" aria-hidden="true">404</p>
        <h1 style="margin-top: var(--wa-space-5)"><?= e(__t('errors.not_found_title')) ?></h1>
        <p class="wa-lead" style="margin-inline: auto"><?= e(__t('errors.not_found_text')) ?></p>
        <div class="wa-row wa-row--center" style="margin-top: var(--wa-space-7)">
            <a class="wa-btn wa-btn--primary" href="<?= e(wa_url()) ?>" data-magnet="0.25">
                <?= e(__t('errors.not_found_cta')) ?>
            </a>
        </div>
    </div>
</section>
