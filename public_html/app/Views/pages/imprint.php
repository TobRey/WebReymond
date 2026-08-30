<?php
use WebAtze\Core\Settings;
$s = Settings::all();
$complete = Settings::imprintComplete();
?>
<section class="wa-section" style="padding-block-start: calc(var(--wa-header-h) + 4rem)">
    <div class="wa-container wa-container--narrow">
        <div class="wa-section__head" data-reveal="up">
            <h1><?= e(__t('imprint.title')) ?></h1>
            <p class="wa-lead"><?= e(__t('imprint.intro')) ?></p>
        </div>

        <?php if (!$complete): ?>
            <div class="wa-note wa-note--warning" style="margin-bottom: var(--wa-space-6)">
                <div><strong><?= e(__t('imprint.placeholder_note')) ?></strong></div>
            </div>
        <?php endif; ?>

        <div class="wa-prose" data-reveal="up">
            <h2><?= e(__t('imprint.responsible')) ?></h2>
            <dl>
                <?php if (($s['company_name'] ?? '') !== ''): ?>
                    <dd><?= e($s['company_name']) ?></dd>
                <?php endif; ?>
                <?php if (($s['owner_name'] ?? '') !== ''): ?>
                    <dd><?= e($s['owner_name']) ?></dd>
                <?php endif; ?>
                <?php if (($s['street'] ?? '') !== ''): ?>
                    <dd><?= e($s['street']) ?></dd>
                <?php endif; ?>
                <?php if (($s['zip_city'] ?? '') !== ''): ?>
                    <dd><?= e($s['zip_city']) ?></dd>
                <?php endif; ?>
                <?php if (($s['country'] ?? '') !== ''): ?>
                    <dd><?= e($s['country']) ?></dd>
                <?php endif; ?>
                <?php if (($s['uid'] ?? '') !== ''): ?>
                    <dt>UID</dt><dd><?= e($s['uid']) ?></dd>
                <?php endif; ?>
            </dl>

            <?php if (($s['email'] ?? '') !== '' || ($s['phone'] ?? '') !== ''): ?>
                <h2><?= e(__t('imprint.contact')) ?></h2>
                <dl>
                    <?php if (($s['email'] ?? '') !== ''): ?>
                        <dd><a href="mailto:<?= e($s['email']) ?>"><?= e($s['email']) ?></a></dd>
                    <?php endif; ?>
                    <?php if (($s['phone'] ?? '') !== ''): ?>
                        <dd><a href="tel:<?= e(preg_replace('/[^\d+]/', '', $s['phone'])) ?>"><?= e($s['phone']) ?></a></dd>
                    <?php endif; ?>
                </dl>
            <?php endif; ?>

            <h2><?= e(__t('imprint.disclaimer_title')) ?></h2>
            <p><?= e(__t('imprint.disclaimer')) ?></p>

            <h2><?= e(__t('imprint.copyright_title')) ?></h2>
            <p><?= e(__t('imprint.copyright')) ?></p>

            <?php if (trim((string) ($s['imprint_extra'] ?? '')) !== ''): ?>
                <?php foreach (preg_split('/\n{2,}/', trim((string) $s['imprint_extra'])) ?: [] as $p): ?>
                    <p><?= nl2br(e(trim($p))) ?></p>
                <?php endforeach; ?>
            <?php endif; ?>
        </div>
    </div>
</section>
