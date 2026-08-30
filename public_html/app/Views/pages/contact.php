<?php
use WebAtze\Core\{Csrf, I18n, Settings};

$subjects = I18n::list('contact.form.subject_options');
$email = Settings::get('email');
$phone = Settings::get('phone');
$whatsapp = Settings::get('whatsapp');
?>
<section class="wa-section" style="padding-block-start: calc(var(--wa-header-h) + 4rem)">
    <div class="wa-halo wa-halo--teal wa-halo--tr" aria-hidden="true"></div>

    <div class="wa-container">
        <div class="wa-section__head" data-reveal="up">
            <p class="wa-eyebrow"><?= e(__t('contact.eyebrow')) ?></p>
            <h1><?= e(__t('contact.title')) ?></h1>
            <p class="wa-lead"><?= e(__t('contact.lead')) ?></p>
        </div>

        <div class="wa-contact" data-reveal="up">

            <aside class="wa-contact__aside">
                <?php if ($email !== '' || $phone !== '' || $whatsapp !== ''): ?>
                    <div class="wa-contact__direct">
                        <p class="wa-footer__title"><?= e(__t('contact.direct')) ?></p>
                        <?php if ($email !== ''): ?>
                            <a href="mailto:<?= e($email) ?>"><?= e($email) ?></a>
                        <?php endif; ?>
                        <?php if ($phone !== ''): ?>
                            <a href="tel:<?= e(preg_replace('/[^\d+]/', '', $phone)) ?>"><?= e($phone) ?></a>
                        <?php endif; ?>
                        <?php if ($whatsapp !== ''): ?>
                            <a href="https://wa.me/<?= e(preg_replace('/\D/', '', $whatsapp)) ?>"
                               target="_blank" rel="noopener noreferrer">WhatsApp</a>
                        <?php endif; ?>
                    </div>
                <?php endif; ?>

                <div class="wa-note">
                    <div><?= e(__t('contact.form.privacy_note')) ?></div>
                </div>
            </aside>

            <form class="wa-contact__form" method="post" action="/api/kontakt"
                  data-contact-form data-sending-label="<?= e(__t('contact.form.sending')) ?>" novalidate>

                <?= Csrf::field() ?>
                <input type="hidden" name="started_at" value="">

                <?php /* Honigtopf: echte Menschen sehen dieses Feld nie. */ ?>
                <div class="wa-hp" aria-hidden="true">
                    <label for="wa-website">Website</label>
                    <input type="text" id="wa-website" name="website" tabindex="-1" autocomplete="off">
                </div>

                <div class="wa-contact__row">
                    <div class="wa-field">
                        <label class="wa-label" for="c-name"><?= e(__t('contact.form.name')) ?></label>
                        <input class="wa-input" type="text" id="c-name" name="name" required
                               autocomplete="name" maxlength="120"
                               placeholder="<?= e(__t('contact.form.name_placeholder')) ?>">
                    </div>
                    <div class="wa-field">
                        <label class="wa-label" for="c-email"><?= e(__t('contact.form.email')) ?></label>
                        <input class="wa-input" type="email" id="c-email" name="email" required
                               autocomplete="email" maxlength="190"
                               placeholder="<?= e(__t('contact.form.email_placeholder')) ?>">
                    </div>
                </div>

                <div class="wa-contact__row">
                    <div class="wa-field">
                        <label class="wa-label" for="c-phone">
                            <?= e(__t('contact.form.phone')) ?>
                        </label>
                        <input class="wa-input" type="tel" id="c-phone" name="phone"
                               autocomplete="tel" maxlength="60"
                               placeholder="<?= e(__t('contact.form.phone_placeholder')) ?>">
                    </div>
                    <div class="wa-field">
                        <label class="wa-label" for="c-company">
                            <?= e(__t('contact.form.company')) ?>
                        </label>
                        <input class="wa-input" type="text" id="c-company" name="company"
                               autocomplete="organization" maxlength="190"
                               placeholder="<?= e(__t('contact.form.company_placeholder')) ?>">
                    </div>
                </div>

                <div class="wa-field">
                    <label class="wa-label" for="c-subject"><?= e(__t('contact.form.subject')) ?></label>
                    <select class="wa-select" id="c-subject" name="subject">
                        <?php foreach ($subjects as $value => $label): ?>
                            <option value="<?= e($value) ?>"><?= e($label) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div class="wa-field">
                    <label class="wa-label" for="c-message"><?= e(__t('contact.form.message')) ?></label>
                    <textarea class="wa-textarea" id="c-message" name="message" required
                              maxlength="5000" rows="7"
                              placeholder="<?= e(__t('contact.form.message_placeholder')) ?>"></textarea>
                </div>

                <div data-form-status role="status" aria-live="polite"></div>

                <button type="submit" class="wa-btn wa-btn--primary wa-btn--lg" data-magnet="0.2">
                    <?= e(__t('contact.form.submit')) ?>
                </button>
            </form>
        </div>
    </div>
</section>
