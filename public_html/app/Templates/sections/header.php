<?php defined('WEBATZE') || exit; ?>
<?php
/** @var array $c @var array $ctx @var string $classes @var string $style */
use WebAtze\Templates\Renderer as R;

$nav = $ctx['nav'] ?? [];
$brand = R::value($c, 'brand', (string) ($ctx['brand'] ?? ''));
$logo = $ctx['logo'] ?? null;
?>
<header class="<?= e($classes) ?>"<?= $style !== '' ? ' style="' . e($style) . '"' : '' ?> data-section="header" data-section-id="<?= (int) $sectionDbId ?>">
    <div class="s-shell s-header__inner s-inner">
        <a class="s-header__brand" href="<?= e($ctx['home'] ?? '/') ?>">
            <?php if (is_array($logo) && ($logo['src'] ?? '') !== ''): ?>
                <?= R::image($logo, 's-header__logo', ['eager' => true]) ?>
            <?php else: ?>
                <span class="s-header__wordmark"><?= e($brand) ?></span>
            <?php endif; ?>
        </a>

        <?php if ($nav !== []): ?>
            <nav class="s-header__nav" aria-label="Hauptnavigation">
                <?php foreach ($nav as $item): ?>
                    <a class="s-header__link" href="<?= e(R::safeUrl((string) ($item['url'] ?? '#'))) ?>"
                       <?= !empty($item['current']) ? 'aria-current="page"' : '' ?>>
                        <?= e((string) ($item['label'] ?? '')) ?>
                    </a>
                <?php endforeach; ?>
            </nav>
        <?php endif; ?>

        <div class="s-header__actions">
            <?php if (($ctx['phone'] ?? '') !== ''): ?>
                <?php /* Der Sprachumschalter – nur, wenn es mehr als eine gibt. */ ?>
                <?php if (!empty($ctx['languages'])): ?>
                    <nav class="s-header__langs" aria-label="Sprache">
                        <?php foreach ($ctx['languages'] as $lang): ?>
                            <a class="s-header__lang<?= !empty($lang['current']) ? ' is-current' : '' ?>"
                               href="<?= e(R::safeUrl((string) $lang['url'])) ?>"
                               hreflang="<?= e((string) $lang['code']) ?>"
                               lang="<?= e((string) $lang['code']) ?>"
                               <?= !empty($lang['current']) ? 'aria-current="true"' : '' ?>>
                                <?= e(mb_strtoupper((string) $lang['code'])) ?>
                            </a>
                        <?php endforeach; ?>
                    </nav>
                <?php endif; ?>

                <a class="s-header__phone" href="tel:<?= e(preg_replace('/[^\d+]/', '', (string) $ctx['phone'])) ?>">
                    <?= e((string) $ctx['phone']) ?>
                </a>
            <?php endif; ?>
            <?= R::link($c['cta'] ?? null, 's-btn s-btn--primary s-btn--sm') ?>

            <button type="button" class="s-header__burger" aria-expanded="false"
                    aria-controls="s-mobile-nav" aria-label="Menü">
                <span></span><span></span><span></span>
            </button>
        </div>
    </div>

    <?php if ($nav !== []): ?>
        <div class="s-header__mobile" id="s-mobile-nav" hidden>
            <?php foreach ($nav as $item): ?>
                <a href="<?= e(R::safeUrl((string) ($item['url'] ?? '#'))) ?>">
                    <?= e((string) ($item['label'] ?? '')) ?>
                </a>
            <?php endforeach; ?>
        </div>
    <?php endif; ?>
</header>
