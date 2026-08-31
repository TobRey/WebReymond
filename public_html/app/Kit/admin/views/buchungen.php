<?php defined('WEBATZE') || exit; ?>
<?php
/**
 * Buchungen.
 *
 * Zwei Dinge stehen hier: was gebucht ist, und wann überhaupt gebucht
 * werden kann. Beides gehört zusammen – wer eine Woche Ferien macht,
 * will nicht an zwei Stellen suchen.
 */

/** @var array $bookings @var array $setup @var string $base */

use WebAtzeKit\Csrf;

$backTo = $base . '?ansicht=buchungen';

$tage = [1 => 'Montag', 2 => 'Dienstag', 3 => 'Mittwoch', 4 => 'Donnerstag',
         5 => 'Freitag', 6 => 'Samstag', 7 => 'Sonntag'];

$heute = date('Y-m-d');

$kommend = [];
$vergangen = [];

foreach ($bookings as $booking) {
    if ((string) ($booking['tag'] ?? '') >= $heute) {
        $kommend[] = $booking;
    } else {
        $vergangen[] = $booking;
    }
}

usort($kommend, static fn (array $a, array $b): int
    => [$a['tag'] ?? '', $a['zeit'] ?? ''] <=> [$b['tag'] ?? '', $b['zeit'] ?? '']);

usort($vergangen, static fn (array $a, array $b): int
    => [$b['tag'] ?? '', $b['zeit'] ?? ''] <=> [$a['tag'] ?? '', $a['zeit'] ?? '']);

$datum = static function (string $day): string {
    $stamp = strtotime($day);
    return $stamp === false ? $day : date('d.m.Y', $stamp);
};

$wochentag = static function (string $day) use ($tage): string {
    $stamp = strtotime($day);
    return $stamp === false ? '' : ($tage[(int) date('N', $stamp)] ?? '');
};
?>

<h1 class="k-h1">Buchungen</h1>

<?php /* ------------------------------------------------ Kommende Termine */ ?>
<h2 class="k-h2">Kommende Termine</h2>

<?php if ($kommend === []): ?>
    <p class="k-note">
        Nichts gebucht. Sobald jemand über <a href="buchen.php">/buchen</a> einen
        Termin nimmt, steht er hier – und geht zusätzlich per E-Mail hinaus.
    </p>
<?php else: ?>
    <?php foreach ($kommend as $booking): ?>
        <?php $abgesagt = (string) ($booking['zustand'] ?? 'offen') === 'abgesagt'; ?>
        <article class="k-card k-lead<?= $abgesagt ? '' : ' is-new' ?>">
            <header class="k-lead__head">
                <div>
                    <strong>
                        <?= e($wochentag((string) $booking['tag'])) ?>,
                        <?= e($datum((string) $booking['tag'])) ?>
                        um <?= e((string) $booking['zeit']) ?> Uhr
                    </strong>
                    <span class="k-muted">
                        · <?= e((string) ($booking['leistung'] ?? 'Termin')) ?>,
                        <?= (int) ($booking['dauer'] ?? 30) ?> Min.
                    </span>
                    <?php if ($abgesagt): ?>
                        <span class="k-tag">abgesagt</span>
                    <?php endif; ?>
                </div>

                <form method="post" action="<?= e($base) ?>">
                    <?= Csrf::field() ?>
                    <input type="hidden" name="action" value="booking">
                    <input type="hidden" name="_back" value="<?= e($backTo) ?>">
                    <input type="hidden" name="booking" value="<?= e((string) $booking['id']) ?>">
                    <input type="hidden" name="state" value="<?= $abgesagt ? 'offen' : 'abgesagt' ?>">
                    <button class="k-btn k-btn--quiet k-btn--sm" type="submit">
                        <?= $abgesagt ? 'Doch wahrnehmen' : 'Absagen' ?>
                    </button>
                </form>
            </header>

            <p class="k-lead__body">
                <?= e((string) ($booking['name'] ?? '')) ?><br>
                <a href="mailto:<?= e((string) ($booking['email'] ?? '')) ?>">
                    <?= e((string) ($booking['email'] ?? '')) ?>
                </a>
                <?php if ((string) ($booking['telefon'] ?? '') !== ''): ?>
                    · <a href="tel:<?= e(preg_replace('/[^\d+]/', '', (string) $booking['telefon'])) ?>">
                        <?= e((string) $booking['telefon']) ?>
                    </a>
                <?php endif; ?>
            </p>

            <?php if ((string) ($booking['nachricht'] ?? '') !== ''): ?>
                <p class="k-lead__body"><?= nl2br(e((string) $booking['nachricht'])) ?></p>
            <?php endif; ?>
        </article>
    <?php endforeach; ?>
<?php endif; ?>

<?php /* ------------------------------------------------ Einstellungen */ ?>
<h2 class="k-h2">Wann kann gebucht werden?</h2>

<form method="post" action="<?= e($base) ?>" class="k-card">
    <?= Csrf::field() ?>
    <input type="hidden" name="action" value="booking-setup">
    <input type="hidden" name="_back" value="<?= e($backTo) ?>">

    <div class="k-field">
        <label class="k-label" for="b-titel">Überschrift der Buchungsseite</label>
        <input class="k-input" type="text" id="b-titel" name="titel" maxlength="120"
               value="<?= e((string) ($setup['titel'] ?? 'Termin buchen')) ?>">
    </div>

    <div class="k-field">
        <label class="k-label" for="b-einleitung">Einleitender Satz</label>
        <input class="k-input" type="text" id="b-einleitung" name="einleitung" maxlength="300"
               value="<?= e((string) ($setup['einleitung'] ?? '')) ?>">
    </div>

    <div class="k-field">
        <label class="k-label" for="b-empfaenger">Buchungen melden an</label>
        <input class="k-input" type="email" id="b-empfaenger" name="empfaenger" maxlength="190"
               value="<?= e((string) ($setup['empfaenger'] ?? '')) ?>">
    </div>

    <h3 class="k-h3">Leistungen</h3>
    <p class="k-hint">
        Eine pro Zeile, mit Dauer in Minuten – zum Beispiel
        <code>Beratung, 30</code>. Wer nur eine anbietet, lässt es bei einer Zeile.
    </p>

    <div class="k-field">
        <label class="k-label" for="b-leistungen">Was gebucht werden kann</label>
        <textarea class="k-input" id="b-leistungen" name="leistungen" rows="5"><?php
            foreach ((array) ($setup['leistungen'] ?? []) as $service) {
                echo e((string) ($service['name'] ?? '') . ', ' . (int) ($service['dauer'] ?? 30)), "\n";
            }
        ?></textarea>
    </div>

    <h3 class="k-h3">Zeiten</h3>
    <p class="k-hint">
        Je Tag ein oder zwei Fenster, etwa <code>08:00-12:00, 13:30-18:00</code>.
        Ein leeres Feld heisst: an diesem Tag wird nicht gebucht.
    </p>

    <?php foreach ($tage as $number => $name): ?>
        <div class="k-field">
            <label class="k-label" for="b-tag-<?= (int) $number ?>"><?= e($name) ?></label>
            <input class="k-input" type="text" id="b-tag-<?= (int) $number ?>"
                   name="zeiten[<?= (int) $number ?>]" maxlength="120"
                   placeholder="geschlossen"
                   value="<?= e(implode(', ', (array) (($setup['zeiten'] ?? [])[$number] ?? []))) ?>">
        </div>
    <?php endforeach; ?>

    <h3 class="k-h3">Geschlossen</h3>
    <div class="k-field">
        <label class="k-label" for="b-geschlossen">Einzelne Tage, an denen nichts geht</label>
        <textarea class="k-input" id="b-geschlossen" name="geschlossen" rows="3"
                  placeholder="2026-12-24"><?php
            foreach ((array) ($setup['geschlossen'] ?? []) as $day) {
                echo e((string) $day), "\n";
            }
        ?></textarea>
        <p class="k-hint">Ein Datum pro Zeile, in der Form Jahr-Monat-Tag. Für Ferien und Feiertage.</p>
    </div>

    <h3 class="k-h3">Feineinstellung</h3>

    <div class="k-field">
        <label class="k-label" for="b-takt">Termine im Abstand von … Minuten</label>
        <input class="k-input k-input--short" type="number" id="b-takt" name="takt"
               min="5" max="240" step="5" value="<?= (int) ($setup['takt'] ?? 30) ?>">
    </div>

    <div class="k-field">
        <label class="k-label" for="b-vorlauf">Frühestens in … Stunden</label>
        <input class="k-input k-input--short" type="number" id="b-vorlauf" name="vorlauf_stunden"
               min="0" max="720" step="1" value="<?= (int) ($setup['vorlauf_stunden'] ?? 24) ?>">
        <p class="k-hint">Damit niemand zehn Minuten vorher noch bucht.</p>
    </div>

    <div class="k-field">
        <label class="k-label" for="b-horizont">Höchstens … Tage im Voraus</label>
        <input class="k-input k-input--short" type="number" id="b-horizont" name="horizont_tage"
               min="1" max="365" step="1" value="<?= (int) ($setup['horizont_tage'] ?? 60) ?>">
    </div>

    <div class="k-field">
        <label class="k-check">
            <input type="checkbox" name="telefon_pflicht" value="1"
                   <?= !empty($setup['telefon_pflicht']) ? 'checked' : '' ?>>
            <span>Telefonnummer ist Pflicht</span>
        </label>
    </div>

    <button class="k-btn" type="submit">Speichern</button>
</form>

<?php /* ------------------------------------------------ Vergangene */ ?>
<?php if ($vergangen !== []): ?>
    <h2 class="k-h2">Gewesen</h2>
    <table class="k-table">
        <tbody>
        <?php foreach (array_slice($vergangen, 0, 40) as $booking): ?>
            <tr>
                <td><?= e($datum((string) $booking['tag'])) ?> <?= e((string) $booking['zeit']) ?></td>
                <td><?= e((string) ($booking['name'] ?? '')) ?></td>
                <td><?= e((string) ($booking['leistung'] ?? '')) ?></td>
                <td>
                    <?= (string) ($booking['zustand'] ?? 'offen') === 'abgesagt'
                        ? '<span class="k-tag">abgesagt</span>' : '' ?>
                </td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
<?php endif; ?>
