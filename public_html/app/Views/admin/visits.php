<?php
/**
 * Besucher – die eigene Website und alle Kundenwebsites.
 *
 * Gezählt wird zweierlei: die eigene Seite hier auf dem Server, jede
 * andere über einen Einzeiler, der dort eingebaut wird. Beides speichert
 * keine IP-Adresse, kein Cookie und keinen dauerhaften
 * Wiedererkennungswert. Das ist keine Einschränkung, sondern der Grund,
 * warum weder meine noch eine Kundenwebsite dafür ein Zustimmungsbanner
 * braucht.
 */

use WebAtze\Core\Config;

/** @var array<int, array<string, mixed>> $zeilen */
/** @var int $tage */
/** @var array<string, mixed>|null $detail */

$base = '/' . trim((string) Config::get('create_path', 'create'), '/');

$zahl = static fn (int $n): string => number_format($n, 0, ',', "'");

$trend = static function (int $jetzt, int $davor): string {
    if ($davor === 0) {
        return $jetzt > 0 ? '<span class="wa-trend wa-trend--up">neu</span>' : '';
    }

    $diff = $jetzt - $davor;

    if ($diff === 0) {
        return '<span class="wa-muted">gleich</span>';
    }

    $prozent = (int) round($diff / $davor * 100);

    return '<span class="wa-trend wa-trend--' . ($diff > 0 ? 'up' : 'down') . '">'
        . ($diff > 0 ? '+' : '−') . abs($prozent) . '&nbsp;%</span>';
};

// Der grösste Tageswert bestimmt die Höhe der Balken. Ohne ihn wäre
// jeder Balken gleich hoch und die Grafik sagte nichts.
$hoechster = static function (array $tage): int {
    $max = 0;
    foreach ($tage as $wert) {
        $max = max($max, (int) $wert);
    }
    return max(1, $max);
};

$gesamt = ['aufrufe' => 0, 'besucher' => 0, 'davor' => 0];

foreach ($zeilen as $zeile) {
    $gesamt['aufrufe'] += (int) $zeile['aufrufe'];
    $gesamt['besucher'] += (int) $zeile['besucher'];
    $gesamt['davor'] += (int) $zeile['davor'];
}
?>

<p class="wa-intro">
    Die letzten <?= (int) $tage ?> Tage, einschliesslich heute. Gezählt wird ohne
    IP-Adresse, ohne Cookie und ohne dauerhafte Kennung – deshalb kommen diese
    Websites ohne Zustimmungsbanner aus.
</p>

<div class="wa-tiles">
    <div class="wa-tile">
        <span class="wa-tile__label">Aufrufe zusammen</span>
        <strong class="wa-tile__value"><?= $zahl($gesamt['aufrufe']) ?></strong>
        <span class="wa-tile__note"><?= $trend($gesamt['aufrufe'], $gesamt['davor']) ?></span>
    </div>
    <div class="wa-tile">
        <span class="wa-tile__label">Besucher zusammen</span>
        <strong class="wa-tile__value"><?= $zahl($gesamt['besucher']) ?></strong>
    </div>
    <div class="wa-tile">
        <span class="wa-tile__label">Websites</span>
        <strong class="wa-tile__value"><?= count($zeilen) ?></strong>
    </div>
</div>

<nav class="wa-filters" aria-label="Zeitraum">
    <?php foreach ([7 => '7 Tage', 30 => '30 Tage', 90 => '90 Tage'] as $wert => $text): ?>
        <a class="wa-chip<?= $tage === $wert ? ' is-active' : '' ?>"
           href="<?= e($base) ?>/zahlen?tage=<?= (int) $wert ?>"><?= e($text) ?></a>
    <?php endforeach; ?>
</nav>

<section class="wa-panel">
    <div class="wa-panel__head">
        <h2 class="wa-panel__title">Alle Websites</h2>
    </div>

    <div class="wa-table-wrap">
        <table class="wa-table">
            <thead>
                <tr>
                    <th>Website</th>
                    <th>Verlauf</th>
                    <th class="wa-table__right">Aufrufe</th>
                    <th class="wa-table__right">Besucher</th>
                    <th>Zustand</th>
                </tr>
            </thead>
            <tbody>
            <?php foreach ($zeilen as $zeile): ?>
                <?php $max = $hoechster((array) $zeile['tage']); ?>
                <tr>
                    <td>
                        <a href="<?= e($base) ?>/zahlen?tage=<?= (int) $tage ?>&amp;website=<?= (int) $zeile['id'] ?>">
                            <?= e((string) $zeile['name']) ?>
                        </a>
                        <?php if ((string) $zeile['domain'] !== ''): ?>
                            <span class="wa-hint"><?= e((string) $zeile['domain']) ?></span>
                        <?php endif; ?>
                    </td>
                    <td>
                        <?php /* Ein Balken je Tag – reines CSS, kein Diagrammwerkzeug. */ ?>
                        <span class="wa-spark" role="img"
                              aria-label="Aufrufe der letzten <?= count((array) $zeile['tage']) ?> Tage">
                            <?php foreach ((array) $zeile['tage'] as $tag => $wert): ?>
                                <span class="wa-spark__bar"
                                      style="--h: <?= (int) round((int) $wert / $max * 100) ?>%"
                                      title="<?= e(date('d.m.', strtotime((string) $tag))) ?>: <?= (int) $wert ?>"></span>
                            <?php endforeach; ?>
                        </span>
                    </td>
                    <td class="wa-table__right">
                        <?= $zahl((int) $zeile['aufrufe']) ?>
                        <span class="wa-hint"><?= $trend((int) $zeile['aufrufe'], (int) $zeile['davor']) ?></span>
                    </td>
                    <td class="wa-table__right"><?= $zahl((int) $zeile['besucher']) ?></td>
                    <td>
                        <?php if ((bool) $zeile['eigen']): ?>
                            <span class="wa-badge wa-badge--ok">zählt hier</span>
                        <?php elseif ((string) $zeile['letzter'] !== ''): ?>
                            <span class="wa-badge wa-badge--ok">zählt</span>
                            <span class="wa-hint">
                                zuletzt <?= e(date('d.m.Y', strtotime((string) $zeile['letzter']))) ?>
                            </span>
                        <?php else: ?>
                            <span class="wa-badge">wartet auf Einbau</span>
                        <?php endif; ?>
                    </td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</section>

<?php /* --------------------------------------------- Eine einzelne Website */ ?>
<?php if ($detail !== null): ?>
    <section class="wa-panel">
        <div class="wa-panel__head">
            <h2 class="wa-panel__title"><?= e((string) $detail['name']) ?></h2>
            <p class="wa-panel__hint">Die letzten 30 Tage.</p>
        </div>

        <div class="wa-grid-2">
            <div class="wa-table-wrap">
                <table class="wa-table">
                    <thead><tr><th>Meistbesuchte Seiten</th><th class="wa-table__right">Aufrufe</th></tr></thead>
                    <tbody>
                    <?php if ($detail['seiten'] === []): ?>
                        <tr><td colspan="2" class="wa-muted">Noch nichts gezählt.</td></tr>
                    <?php endif; ?>
                    <?php foreach ($detail['seiten'] as $seite): ?>
                        <tr>
                            <td><code><?= e((string) $seite['pfad']) ?></code></td>
                            <td class="wa-table__right"><?= $zahl((int) $seite['aufrufe']) ?></td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>

            <div class="wa-table-wrap">
                <table class="wa-table">
                    <thead><tr><th>Woher die Leute kamen</th><th class="wa-table__right">Aufrufe</th></tr></thead>
                    <tbody>
                    <?php if ($detail['herkunft'] === []): ?>
                        <tr><td colspan="2" class="wa-muted">Alle direkt – kein Verweis von aussen.</td></tr>
                    <?php endif; ?>
                    <?php foreach ($detail['herkunft'] as $quelle): ?>
                        <tr>
                            <td><?= e((string) $quelle['name']) ?></td>
                            <td class="wa-table__right"><?= $zahl((int) $quelle['aufrufe']) ?></td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>

        <?php if (!empty($detail['abholbar'])): ?>
            <?php /* Selbst gebaute Websites liefern die Zahlen auf Nachfrage. */ ?>
            <form method="post" action="<?= e($base) ?>/zahlen/<?= (int) $detail['id'] ?>"
                  class="wa-form__actions">
                <?= \WebAtze\Core\Csrf::field() ?>
                <button type="submit" class="wa-btn wa-btn--quiet wa-btn--sm">Jetzt abholen</button>
            </form>
        <?php endif; ?>

        <?php if (!(bool) $detail['eigen']): ?>
            <?php $einzeiler = \WebAtze\Domain\Visits::snippet((int) $detail['id']); ?>
            <?php if ($einzeiler !== ''): ?>
                <h3 class="wa-subtitle">Der Einzeiler für diese Website</h3>
                <p class="wa-hint">
                    Vor <code>&lt;/body&gt;</code> einsetzen – in jedem Baukasten, jedem
                    WordPress-Theme, jeder von Hand gebauten Seite. Danach zählt sie mit.
                    Er lädt nichts nach, setzt kein Cookie und verlangsamt nichts.
                </p>
                <div class="wa-copybox">
                    <input class="wa-input" type="text" readonly
                           value="<?= e($einzeiler) ?>"
                           onclick="this.select()"
                           aria-label="Zählzeile zum Kopieren">
                </div>
            <?php else: ?>
                <p class="wa-hint">
                    Für den Einzeiler fehlt die eigene Adresse. Sie steht unter
                    <a href="<?= e($base) ?>/einstellungen">Einstellungen</a> als
                    <code>app_url</code>.
                </p>
            <?php endif; ?>
        <?php endif; ?>
    </section>
<?php endif; ?>
