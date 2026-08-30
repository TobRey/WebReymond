<?php
/**
 * Das WebAtze-Logo als SVG.
 *
 * Nachgebaut aus der gelieferten Bilddatei: das kräftige "W" aus vier
 * Strichen, der türkise Haken darin und der Drahtgitter-Globus daneben.
 * Als Vektor bleibt es bei jeder Grösse scharf, wiegt unter 2 KB und
 * lässt sich animieren.
 *
 * Aufbau des W: vier getrennte Striche statt eines durchgehenden Umrisses.
 * Ein einziger Umriss würde sich an der Mittelspitze selbst überschneiden –
 * dort entstünden Zacken statt der Spitze.
 *
 * Liegt die Originaldatei vor, genügt es, sie unter assets/img/logo.svg
 * abzulegen. Diese Datei erkennt das und nimmt dann die Originaldatei.
 *
 * Parameter:
 *   $markOnly  nur das Bildzeichen, ohne Schriftzug
 *   $size      Höhe des Bildzeichens in rem
 *   $class     zusätzliche CSS-Klasse
 *   $animated  Haken und Globus bewegen sich beim Laden
 */

/** @var bool $markOnly */
/** @var float $size */
/** @var string $class */
/** @var bool $animated */

$markOnly = $markOnly ?? false;
$size = $size ?? 2.35;
$class = $class ?? '';
$animated = $animated ?? false;

$customLogo = BASE_DIR . '/assets/img/logo.svg';
$titleId = 'wa-logo-' . bin2hex(random_bytes(3));

// Die vier Striche des W. Spitzenpunkte: oben links, Tal, Mittelspitze,
// Tal, oben rechts – jeweils mit 15 Einheiten halber Strichbreite.
$strokes = [
    'M0 8 L30 8 L55 96 L25 96 Z',
    'M25 96 L55 96 L78 40 L48 40 Z',
    'M48 40 L78 40 L101 96 L71 96 Z',
    'M71 96 L101 96 L126 8 L96 8 Z',
];
?>
<?php if (is_file($customLogo)): ?>
    <img
        src="/assets/img/logo.svg"
        alt="WebAtze"
        class="wa-brand__mark <?= e($class) ?>"
        style="height: <?= e((string) $size) ?>rem; width: auto;"
        width="168" height="104">
<?php else: ?>
<svg
    class="wa-brand__mark <?= e($class) ?><?= $animated ? ' is-animated' : '' ?>"
    viewBox="0 0 168 104"
    style="height: <?= e((string) $size) ?>rem; width: auto;"
    role="img"
    aria-labelledby="<?= e($titleId) ?>"
    fill="none"
    xmlns="http://www.w3.org/2000/svg">
    <title id="<?= e($titleId) ?>">WebAtze</title>

    <?php // Globus: liegt hinter dem W, wie im Original ?>
    <g class="wa-logo__globe" stroke="var(--wa-logo-teal, #17C8C8)" stroke-width="5"
       fill="none" stroke-linecap="round">
        <circle cx="130" cy="55" r="27"/>
        <ellipse cx="130" cy="55" rx="10.5" ry="27"/>
        <path d="M105 44h50M105 66h50"/>
    </g>
    <g class="wa-logo__nodes" fill="var(--wa-logo-teal, #17C8C8)">
        <circle cx="153" cy="44" r="5.5"/>
        <circle cx="130" cy="66" r="5.5"/>
    </g>

    <?php // Das W – vier Striche, damit die Mittelspitze sauber bleibt ?>
    <g class="wa-logo__w" fill="var(--wa-logo-indigo, #2B1B9E)">
        <?php foreach ($strokes as $d): ?>
            <path d="<?= e($d) ?>"/>
        <?php endforeach; ?>
    </g>

    <?php // Der Haken bleibt innerhalb des W und berührt den Globus nicht ?>
    <path
        class="wa-logo__check"
        d="M41 51 L63 75 L98 31"
        stroke="var(--wa-logo-teal, #17C8C8)"
        stroke-width="19"
        fill="none"/>
</svg>
<?php endif; ?>

<?php if (!$markOnly): ?>
    <span class="wa-brand__word">Web<em>Atze</em></span>
<?php endif; ?>
