<?php
/**
 * Besucherzahlen.
 *
 * Gezählt wird auf dem Server des Kunden, geholt wird einmal am Tag.
 * Hier stehen nur Summen – wer wann da war, verlässt die Website des
 * Kunden nie. Das ist keine Einschränkung, sondern der Grund, warum
 * seine Seite ohne Zustimmungsbanner auskommt.
 */

use WebAtze\Core\{Config, Csrf};

/** @var array $rows */

$base = '/' . trim((string) Config::get('create_path', 'create'), '/');

$trend = static function (int $now, int $before): string {
    if ($before === 0) {
        return '';
    }
    $diff = $now - $before;
    if ($diff === 0) {
        return '<span class="wa-muted">gleich wie in der Vorwoche</span>';
    }
    $percent = (int) round($diff / $before * 100);
    return '<span class="' . ($diff > 0 ? 'wa-trend wa-trend--up' : 'wa-trend wa-trend--down') . '">'
        . ($diff > 0 ? '+' : '−') . abs($percent) . '&nbsp;% gegenüber der Vorwoche</span>';
};
?>

<p class="wa-intro">
    Eine Woche je Website, jeweils bis gestern. Abgeholt wird einmal am Tag; der
    laufende Tag ist deshalb noch nicht vollständig.
</p>

<?php if ($rows === []): ?>
    <section class="wa-panel">
        <div class="wa-panel__head">
            <h2 class="wa-panel__title">Noch keine Website zählt mit</h2>
        </div>
        <p class="wa-panel__body">
            Die Zählung wird im Formular unter „Was zusätzlich mitgeliefert wird"
            angehakt. Ohne Haken wird sie nicht mitgeliefert – dann gibt es hier
            auch nichts anzuzeigen.
        </p>
    </section>
<?php endif ?>

<?php foreach ($rows as $entry): ?>
    <?php $project = $entry['project']; $week = $entry['week']; ?>
    <section class="wa-panel">
        <div class="wa-panel__head">
            <h2 class="wa-panel__title">
                <a href="<?= e($base) ?>/projekt/<?= (int) $project['id'] ?>">
                    <?= e((string) $project['name']) ?>
                </a>
            </h2>
            <p class="wa-panel__hint">
                <?= e(date('d.m.Y', strtotime((string) $week['von']))) ?>
                bis <?= e(date('d.m.Y', strtotime((string) $week['bis']))) ?>
                <?php if ((string) $project['report_email'] !== ''): ?>
                    · Wochenbericht an <?= e((string) $project['report_email']) ?>
                <?php endif ?>
            </p>
            <div class="wa-panel__actions">
                <form method="post" action="<?= e($base) ?>/zahlen/<?= (int) $project['id'] ?>">
                    <?= Csrf::field() ?>
                    <button type="submit" class="wa-btn wa-btn--quiet wa-btn--sm">Jetzt abholen</button>
                </form>
            </div>
        </div>

        <?php if ((int) $week['aufrufe'] === 0): ?>
            <p class="wa-panel__body">
                Für diese Woche liegen keine Zahlen vor. Entweder war noch niemand da,
                oder die Website ist noch nicht hochgeladen.
            </p>
        <?php else: ?>
            <div class="wa-stats">
                <div class="wa-stat">
                    <span class="wa-stat__value"><?= number_format((int) $week['aufrufe'], 0, ',', "'") ?></span>
                    <span class="wa-stat__label">
                        Seitenaufrufe<br>
                        <?= $trend((int) $week['aufrufe'], (int) $week['davor']['aufrufe']) ?>
                    </span>
                </div>
                <div class="wa-stat">
                    <span class="wa-stat__value"><?= number_format((int) $week['besucher'], 0, ',', "'") ?></span>
                    <span class="wa-stat__label">
                        Besucher<br>
                        <?= $trend((int) $week['besucher'], (int) $week['davor']['besucher']) ?>
                    </span>
                </div>
            </div>

            <div class="wa-table-wrap">
                <table class="wa-table">
                    <thead><tr><th>Meistbesuchte Seiten</th><th class="wa-table__right">Aufrufe</th></tr></thead>
                    <tbody>
                    <?php foreach ($week['seiten'] as $page): ?>
                        <tr>
                            <td><code><?= e((string) $page['pfad']) ?></code></td>
                            <td class="wa-table__right"><?= (int) $page['aufrufe'] ?></td>
                        </tr>
                    <?php endforeach ?>
                    </tbody>
                </table>
            </div>

            <div class="wa-table-wrap">
                <table class="wa-table">
                    <thead><tr><th>Woher die Leute kamen</th><th class="wa-table__right">Aufrufe</th></tr></thead>
                    <tbody>
                    <?php foreach ($week['herkunft'] as $source): ?>
                        <tr>
                            <td><?= e((string) $source['name']) ?></td>
                            <td class="wa-table__right"><?= (int) $source['aufrufe'] ?></td>
                        </tr>
                    <?php endforeach ?>
                    </tbody>
                </table>
            </div>
        <?php endif ?>
    </section>
<?php endforeach ?>
