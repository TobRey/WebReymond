<?php

/**
 * Der freie Abschnitt.
 *
 * Die einzige Vorlage ohne festes Gerüst: Was hier steht, entscheiden
 * die Bausteine. Sie kommen aus Templates\Blocks, sind dort geprüft und
 * bringen ihre eigenen Kennungen mit, damit der Editor sie greifen kann.
 */

declare(strict_types=1);

use WebAtze\Templates\Blocks;

defined('WEBATZE') || exit;

/** @var array $c */
/** @var string $classes */
/** @var string $sectionId */
/** @var string $style */
/** @var int $sectionDbId */
/** @var string $attrs */

$blocks = Blocks::clean($c['blocks'] ?? []);

?>
<section class="<?= e($classes) ?>" id="<?= e($sectionId) ?>"<?= $style !== '' ? ' style="' . e($style) . '"' : '' ?> data-section="frei" data-section-id="<?= (int) $sectionDbId ?>"<?= $attrs ?>>
    <div class="s-shell s-frei__inner s-inner" data-block-canvas>
        <?= Blocks::render($blocks) ?>
    </div>
</section>
