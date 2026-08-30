<?php
/** @var array $c @var array $ctx @var string $classes @var string $sectionId @var string $style */
use WebAtze\Templates\Renderer as R;

$showForm = ($c['show_form'] ?? true) !== false && !str_contains($classes, 'info-only');
$showMap = !empty($c['show_map']) || str_contains($classes, 'map-');
$extended = str_contains($classes, 'extended');
$icons = str_contains($classes, '--icons');

$email = R::value($c, 'email', (string) ($ctx['email'] ?? ''));
$phone = R::value($c, 'phone', (string) ($ctx['phone'] ?? ''));
$address = R::value($c, 'address', (string) ($ctx['address'] ?? ''));
$hours = R::value($c, 'hours');
$mapQuery = R::value($c, 'map_query', $address);
?>
<section class="<?= e($classes) ?>" id="<?= e($sectionId) ?>"<?= $style !== '' ? ' style="' . e($style) . '"' : '' ?> data-section="contact">
    <div class="s-shell s-contact__inner s-inner">

        <div class="s-contact__info">
            <?= R::head($c) ?>

            <ul class="s-contact__list s-items" role="list">
                <?php if ($email !== ''): ?>
                    <li>
                        <?php if ($icons): ?><?= R::icon('mail') ?><?php endif; ?>
                        <a href="mailto:<?= e($email) ?>"><?= e($email) ?></a>
                    </li>
                <?php endif; ?>
                <?php if ($phone !== ''): ?>
                    <li>
                        <?php if ($icons): ?><?= R::icon('phone') ?><?php endif; ?>
                        <a href="tel:<?= e(preg_replace('/[^\d+]/', '', $phone)) ?>"><?= e($phone) ?></a>
                    </li>
                <?php endif; ?>
                <?php if ($address !== ''): ?>
                    <li>
                        <?php if ($icons): ?><?= R::icon('pin') ?><?php endif; ?>
                        <address><?= nl2br(e($address)) ?></address>
                    </li>
                <?php endif; ?>
            </ul>

            <?php if ($hours !== ''): ?>
                <div class="s-contact__hours">
                    <h3>Öffnungszeiten</h3>
                    <p><?= nl2br(e($hours)) ?></p>
                </div>
            <?php endif; ?>
        </div>

        <?php if ($showForm): ?>
            <?php /* Das Formular schickt an contact.php im selben Verzeichnis.
                     Honigtopf und Zeitfalle stecken darin – siehe customer-kit. */ ?>
            <form class="s-contact__form" method="post" action="contact.php" novalidate>
                <input type="hidden" name="_form" value="<?= e($sectionId) ?>">
                <input type="hidden" name="_t" value="<?= e((string) time()) ?>">

                <div class="s-hp" aria-hidden="true">
                    <label for="cf-site">Website</label>
                    <input type="text" id="cf-site" name="website" tabindex="-1" autocomplete="off">
                </div>

                <div class="s-field">
                    <label for="cf-name">Name</label>
                    <input type="text" id="cf-name" name="name" required maxlength="120" autocomplete="name">
                </div>

                <div class="s-field">
                    <label for="cf-email">E-Mail</label>
                    <input type="email" id="cf-email" name="email" required maxlength="190" autocomplete="email">
                </div>

                <?php if ($extended): ?>
                    <div class="s-field">
                        <label for="cf-phone">Telefon</label>
                        <input type="tel" id="cf-phone" name="phone" maxlength="60" autocomplete="tel">
                    </div>
                    <div class="s-field">
                        <label for="cf-subject">Betreff</label>
                        <input type="text" id="cf-subject" name="subject" maxlength="150">
                    </div>
                <?php endif; ?>

                <div class="s-field">
                    <label for="cf-message">Nachricht</label>
                    <textarea id="cf-message" name="message" required rows="6" maxlength="4000"></textarea>
                </div>

                <div class="s-form__status" role="status" aria-live="polite"></div>

                <button type="submit" class="s-btn s-btn--primary">Nachricht senden</button>
            </form>
        <?php endif; ?>

        <?php if ($showMap && $mapQuery !== ''): ?>
            <?php /* Die Karte wird erst geladen, wenn jemand sie anfordert.
                     So verlässt beim blossen Seitenaufruf keine Angabe den Server. */ ?>
            <div class="s-contact__map" data-map-query="<?= e($mapQuery) ?>">
                <button type="button" class="s-btn s-btn--ghost s-map__load">Karte anzeigen</button>
                <p class="s-map__note">Beim Anzeigen wird eine Karte von OpenStreetMap geladen.</p>
            </div>
        <?php endif; ?>
    </div>
</section>
