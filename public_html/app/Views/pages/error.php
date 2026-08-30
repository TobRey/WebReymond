<?php /** @var string $errorId */ ?>
<section class="wa-error-page">
    <div class="wa-container">
        <p class="wa-error-page__code" aria-hidden="true">500</p>
        <h1 style="margin-top: var(--wa-space-5)"><?= e(__t('errors.server_title')) ?></h1>
        <p class="wa-lead" style="margin-inline: auto"><?= e(__t('errors.server_text')) ?></p>
        <?php if (($errorId ?? '') !== ''): ?>
            <p style="color: var(--wa-text-faint); font-family: var(--wa-font-mono); font-size: var(--wa-text-sm)">
                <?= e($errorId) ?>
            </p>
        <?php endif; ?>
        <div class="wa-row wa-row--center" style="margin-top: var(--wa-space-7)">
            <a class="wa-btn wa-btn--ghost" href="<?= e(wa_url()) ?>"><?= e(__t('errors.not_found_cta')) ?></a>
        </div>
    </div>
</section>
