<?php

/**
 * Das Vorschaubild einer Website in einer Liste.
 *
 * Kein Screenshot vom Server: Auf geteiltem Hosting gibt es keinen
 * Browser, der eine Seite abfotografieren könnte. Der Browser, der
 * diese Liste anzeigt, kann es umsonst – deshalb steht hier die echte
 * Startseite in einem Rahmen, auf Daumennagelgrösse verkleinert.
 *
 * Der Rahmen ist für die Bedienung unsichtbar: kein Tabstopp, keine
 * Mausereignisse, für Screenreader nicht vorhanden. Er ist ein Bild,
 * kein Inhalt.
 *
 * @var array<string, mixed> $website
 * @var string $base
 */

use WebAtze\Domain\Websites;

$w = $website ?? [];
$name = (string) ($w['name'] ?? '');
$domain = (string) ($w['domain'] ?? '');
$hat = Websites::hatVorschau($w);
?>
<div class="wa-thumb<?= $hat ? '' : ' wa-thumb--leer' ?>">
    <?php if ($hat): ?>
        <iframe class="wa-thumb__rahmen"
                src="<?= e($base) ?>/projekt/<?= (int) $w['id'] ?>/miniatur"
                loading="lazy" tabindex="-1" aria-hidden="true"
                title="" scrolling="no"></iframe>
    <?php else: ?>
        <?php /* Ohne gebauten Stand: ein Anfangsbuchstabe. Er sagt
                 wenigstens, welche Zeile welche ist - ein leeres
                 graues Feld saehe nach Fehler aus. */ ?>
        <span class="wa-thumb__zeichen" aria-hidden="true">
            <?= e(mb_strtoupper(mb_substr($name !== '' ? $name : $domain, 0, 1))) ?>
        </span>
    <?php endif; ?>
</div>
