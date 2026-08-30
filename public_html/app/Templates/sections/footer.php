<?php
/** @var array $c @var array $ctx @var string $classes @var string $style */
use WebAtze\Templates\Renderer as R;

$social = R::items($c, 'social');
$nav = $ctx['nav'] ?? [];
$legal = $ctx['legal'] ?? [];
$brand = (string) ($ctx['brand'] ?? '');
$year = date('Y');

$email = R::value($c, 'email', (string) ($ctx['email'] ?? ''));
$phone = R::value($c, 'phone', (string) ($ctx['phone'] ?? ''));
$address = R::value($c, 'address', (string) ($ctx['address'] ?? ''));
?>
<footer class="<?= e($classes) ?>"<?= $style !== '' ? ' style="' . e($style) . '"' : '' ?> data-section="footer" data-section-id="<?= (int) $sectionDbId ?>">
    <div class="s-shell">
        <div class="s-footer__grid s-items">

            <div class="s-footer__brand">
                <p class="s-footer__name"><?= e($brand) ?></p>
                <?php if (R::value($c, 'about') !== ''): ?>
                    <p class="s-footer__about"><?= e(R::value($c, 'about')) ?></p>
                <?php endif; ?>
            </div>

            <?php if ($nav !== []): ?>
                <nav class="s-footer__col" aria-label="Fussnavigation">
                    <h2 class="s-footer__heading">Seiten</h2>
                    <ul role="list">
                        <?php foreach ($nav as $item): ?>
                            <li><a href="<?= e(R::safeUrl((string) ($item['url'] ?? '#'))) ?>">
                                <?= e((string) ($item['label'] ?? '')) ?></a></li>
                        <?php endforeach; ?>
                    </ul>
                </nav>
            <?php endif; ?>

            <div class="s-footer__col">
                <h2 class="s-footer__heading">Kontakt</h2>
                <ul role="list">
                    <?php if ($email !== ''): ?>
                        <li><a href="mailto:<?= e($email) ?>"><?= e($email) ?></a></li>
                    <?php endif; ?>
                    <?php if ($phone !== ''): ?>
                        <li><a href="tel:<?= e(preg_replace('/[^\d+]/', '', $phone)) ?>"><?= e($phone) ?></a></li>
                    <?php endif; ?>
                    <?php if ($address !== ''): ?>
                        <li><address><?= nl2br(e($address)) ?></address></li>
                    <?php endif; ?>
                </ul>
            </div>

            <?php if ($social !== []): ?>
                <div class="s-footer__col s-footer__social">
                    <h2 class="s-footer__heading">Folgen</h2>
                    <ul role="list">
                        <?php foreach ($social as $item): ?>
                            <li>
                                <a href="<?= e(R::safeUrl((string) ($item['url'] ?? '#'))) ?>"
                                   target="_blank" rel="noopener noreferrer">
                                    <?= e((string) ($item['name'] ?? '')) ?>
                                </a>
                            </li>
                        <?php endforeach; ?>
                    </ul>
                </div>
            <?php endif; ?>
        </div>

        <div class="s-footer__bottom">
            <p>&copy; <?= e($year) ?> <?= e($brand) ?><?= R::value($c, 'note') !== '' ? ' · ' . e(R::value($c, 'note')) : '' ?></p>

            <?php if ($legal !== []): ?>
                <ul class="s-footer__legal" role="list">
                    <?php foreach ($legal as $item): ?>
                        <li><a href="<?= e(R::safeUrl((string) ($item['url'] ?? '#'))) ?>">
                            <?= e((string) ($item['label'] ?? '')) ?></a></li>
                    <?php endforeach; ?>
                </ul>
            <?php endif; ?>
        </div>
    </div>
</footer>
