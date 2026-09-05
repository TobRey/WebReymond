<?php defined('WEBATZE') || exit; ?>
<?php
/** @var array $c @var array $ctx @var string $classes @var string $sectionId @var string $style */
use WebAtze\Templates\Renderer as R;
$items = R::items($c);
$hasMedia = !str_contains($classes, 'no-media');
$withContact = str_contains($classes, 'with-contact');
$withBio = str_contains($classes, 'with-bio');
?>
<section class="<?= e($classes) ?>" id="<?= e($sectionId) ?>"<?= $style !== '' ? ' style="' . e($style) . '"' : '' ?> data-section="team" data-section-id="<?= (int) $sectionDbId ?>"<?= $attrs ?>>
    <div class="s-shell">
        <?= R::head($c, $heading, $titelAttrs) ?>

        <ul class="s-team__grid s-items" role="list">
            <?php foreach ($items as $index => $item): ?>
                <li class="s-person" style="--i: <?= (int) $index ?>">
                    <?php if ($hasMedia): ?>
                        <div class="s-person__media s-media">
                            <?= R::image($item['image'] ?? null, 's-person__image s-img', [
                                'label' => (string) ($item['name'] ?? ''),
                            ]) ?>
                        </div>
                    <?php endif; ?>

                    <div class="s-person__body">
                        <h3 class="s-person__name"><?= e((string) ($item['name'] ?? '')) ?></h3>
                        <p class="s-person__role"><?= e((string) ($item['role'] ?? '')) ?></p>

                        <?php if ($withBio && ($item['text'] ?? '') !== ''): ?>
                            <p class="s-person__text"><?= e((string) $item['text']) ?></p>
                        <?php endif; ?>

                        <?php if ($withContact && ($item['email'] ?? '') !== ''): ?>
                            <a class="s-link" href="mailto:<?= e((string) $item['email']) ?>">
                                <?= e((string) $item['email']) ?>
                            </a>
                        <?php endif; ?>
                    </div>
                </li>
            <?php endforeach; ?>
        </ul>
    </div>
</section>
