<?php

/**
 * Buchhaltung: was reinkommt, was rausgeht, was offen ist.
 *
 * Der Verlauf über die Monate steht bewusst oben. Eine Jahreszahl allein
 * sagt nichts darüber, ob es aufwärts oder abwärts geht.
 */

use WebAtze\Core\{Config, Csrf};
use WebAtze\Domain\{Billing, Calendar};

/** @var int $jahr */
/** @var array<string, mixed> $summe */
/** @var array<int, array<string, mixed>> $monate */
/** @var array<int, array<string, mixed>> $ausgaben */
/** @var array<int, array<string, mixed>> $einnahmen */
/** @var array<int, array<string, mixed>> $offen */

$base = '/' . trim((string) Config::get('create_path', 'create'), '/');

$hoechster = 1;
foreach ($monate as $m) {
    $hoechster = max($hoechster, (int) $m['einnahmen'], (int) $m['ausgaben']);
}

$offenSumme = 0;
foreach ($offen as $o) {
    $offenSumme += (int) $o['amount_rappen'];
}
?>

<div class="wa-tiles">
    <div class="wa-tile">
        <span class="wa-tile__label">Einnahmen <?= (int) $jahr ?></span>
        <strong class="wa-tile__value"><?= e(Billing::money((int) $summe['einnahmen'])) ?></strong>
    </div>
    <div class="wa-tile">
        <span class="wa-tile__label">Ausgaben</span>
        <strong class="wa-tile__value"><?= e(Billing::money((int) $summe['ausgaben'])) ?></strong>
    </div>
    <div class="wa-tile<?= $summe['gewinn'] < 0 ? ' wa-tile--warn' : '' ?>">
        <span class="wa-tile__label">Bleibt</span>
        <strong class="wa-tile__value"><?= e(Billing::money((int) $summe['gewinn'])) ?></strong>
    </div>
    <div class="wa-tile<?= $offenSumme > 0 ? ' wa-tile--warn' : '' ?>">
        <span class="wa-tile__label">Noch offen</span>
        <strong class="wa-tile__value"><?= e(Billing::money($offenSumme)) ?></strong>
    </div>
</div>

<section class="wa-panel">
    <header class="wa-panel__head">
        <div class="wa-monthnav">
            <a class="wa-btn wa-btn--small" href="<?= e($base) ?>/buchhaltung?jahr=<?= $jahr - 1 ?>">←</a>
            <h2 class="wa-panel__title">Verlauf <?= (int) $jahr ?></h2>
            <a class="wa-btn wa-btn--small" href="<?= e($base) ?>/buchhaltung?jahr=<?= $jahr + 1 ?>">→</a>
        </div>
    </header>

    <div class="wa-bars" role="img"
         aria-label="Einnahmen und Ausgaben je Monat im Jahr <?= (int) $jahr ?>">
        <?php foreach ($monate as $m): ?>
            <?php
                $zeit = strtotime((string) $m['monat']) ?: time();
                $ein = (int) $m['einnahmen'];
                $aus = (int) $m['ausgaben'];
            ?>
            <div class="wa-bars__month">
                <div class="wa-bars__stack">
                    <span class="wa-bars__bar wa-bars__bar--in"
                          style="--h: <?= round($ein / $hoechster * 100) ?>%"
                          title="Einnahmen <?= e(Billing::money($ein)) ?>"></span>
                    <span class="wa-bars__bar wa-bars__bar--out"
                          style="--h: <?= round($aus / $hoechster * 100) ?>%"
                          title="Ausgaben <?= e(Billing::money($aus)) ?>"></span>
                </div>
                <span class="wa-bars__label"><?= e(mb_substr(Calendar::MONTHS[(int) date('n', $zeit)] ?? '', 0, 3)) ?></span>
            </div>
        <?php endforeach; ?>
    </div>

    <p class="wa-panel__hint">
        <span class="wa-key wa-key--in"></span> Einnahmen
        <span class="wa-key wa-key--out"></span> Ausgaben
    </p>
</section>

<?php if ($offen !== []): ?>
<section class="wa-panel">
    <header class="wa-panel__head">
        <h2 class="wa-panel__title">Was noch aussteht</h2>
    </header>

    <div class="wa-table-wrap">
        <table class="wa-table">
            <thead>
                <tr>
                    <th>Kunde</th>
                    <th>Posten</th>
                    <th>Periode</th>
                    <th class="wa-table__num">Betrag</th>
                    <th></th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($offen as $o): ?>
                    <tr>
                        <td>
                            <a class="wa-table__main" href="<?= e($base) ?>/kunden/<?= (int) $o['kunde_id'] ?>">
                                <?= e((string) $o['kunde']) ?>
                            </a>
                        </td>
                        <td><?= e((string) $o['label']) ?></td>
                        <td class="wa-table__quiet"><?= e((string) $o['periode']) ?: 'einmalig' ?></td>
                        <td class="wa-table__num"><?= e(Billing::money((int) $o['amount_rappen'])) ?></td>
                        <td class="wa-table__actions">
                            <form method="post" action="<?= e($base) ?>/kunden/<?= (int) $o['kunde_id'] ?>/bezahlt">
                                <?= Csrf::field() ?>
                                <input type="hidden" name="charge_id" value="<?= (int) $o['charge_id'] ?>">
                                <button type="submit" class="wa-check">abhaken</button>
                            </form>
                        </td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</section>
<?php endif; ?>

<section class="wa-panel">
    <header class="wa-panel__head">
        <h2 class="wa-panel__title">Ausgaben</h2>
    </header>

    <?php if ($ausgaben === []): ?>
        <p class="wa-empty">Für <?= (int) $jahr ?> nichts eingetragen.</p>
    <?php else: ?>
        <div class="wa-table-wrap">
            <table class="wa-table">
                <thead>
                    <tr>
                        <th>Datum</th>
                        <th>Wofür</th>
                        <th>Art</th>
                        <th class="wa-table__num">Betrag</th>
                        <th></th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($ausgaben as $a): ?>
                        <tr>
                            <td><?= e((string) $a['spent_on']) ?></td>
                            <td>
                                <?= e((string) $a['label']) ?>
                                <?php if ((string) $a['recurring'] !== 'einmalig'): ?>
                                    <span class="wa-badge"><?= e(Billing::INTERVALS[$a['recurring']] ?? '') ?></span>
                                <?php endif; ?>
                            </td>
                            <td class="wa-table__quiet"><?= e((string) $a['category']) ?></td>
                            <td class="wa-table__num"><?= e(Billing::money((int) $a['amount_rappen'])) ?></td>
                            <td class="wa-table__actions">
                                <form method="post" action="<?= e($base) ?>/ausgaben/loeschen"
                                      data-confirm="Diese Ausgabe löschen?">
                                    <?= Csrf::field() ?>
                                    <input type="hidden" name="expense_id" value="<?= (int) $a['id'] ?>">
                                    <button type="submit" class="wa-btn wa-btn--small">Löschen</button>
                                </form>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    <?php endif; ?>

    <details class="wa-details">
        <summary>Ausgabe eintragen</summary>
        <form method="post" action="<?= e($base) ?>/ausgaben" class="wa-form wa-form--inline">
            <?= Csrf::field() ?>

            <div class="wa-field">
                <label class="wa-label" for="a-label">Wofür</label>
                <input class="wa-input" type="text" id="a-label" name="label" required
                       placeholder="z.B. Hosting GoDaddy">
            </div>

            <div class="wa-field">
                <label class="wa-label" for="a-amount">Betrag CHF</label>
                <input class="wa-input" type="text" inputmode="decimal" id="a-amount" name="amount">
            </div>

            <div class="wa-field">
                <label class="wa-label" for="a-date">Datum</label>
                <input class="wa-input" type="date" id="a-date" name="spent_on"
                       value="<?= e(date('Y-m-d')) ?>">
            </div>

            <div class="wa-field">
                <label class="wa-label" for="a-cat">Art</label>
                <input class="wa-input" type="text" id="a-cat" name="category" list="ausgabearten"
                       value="sonstiges">
                <datalist id="ausgabearten">
                    <option value="hosting"><option value="domains"><option value="software">
                    <option value="werkzeuge"><option value="ki-guthaben"><option value="loehne">
                    <option value="versicherung"><option value="sonstiges">
                </datalist>
            </div>

            <div class="wa-field">
                <label class="wa-label" for="a-rec">Wie oft</label>
                <select class="wa-select" id="a-rec" name="recurring">
                    <?php foreach (Billing::INTERVALS as $wert => $text): ?>
                        <option value="<?= e($wert) ?>"><?= e($text) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>

            <div class="wa-form__actions">
                <button type="submit" class="wa-btn wa-btn--primary">Eintragen</button>
            </div>
        </form>
    </details>
</section>

<section class="wa-panel">
    <header class="wa-panel__head">
        <h2 class="wa-panel__title">Eingegangen</h2>
    </header>

    <?php if ($einnahmen === []): ?>
        <p class="wa-empty">Für <?= (int) $jahr ?> nichts verbucht.</p>
    <?php else: ?>
        <div class="wa-table-wrap">
            <table class="wa-table">
                <thead>
                    <tr>
                        <th>Datum</th>
                        <th>Kunde</th>
                        <th>Wofür</th>
                        <th>Periode</th>
                        <th class="wa-table__num">Betrag</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($einnahmen as $z): ?>
                        <tr>
                            <td><?= e((string) $z['paid_on']) ?></td>
                            <td><?= e((string) ($z['kunde'] ?? '–')) ?></td>
                            <td><?= e((string) $z['label']) ?></td>
                            <td class="wa-table__quiet"><?= e((string) $z['period']) ?: 'einmalig' ?></td>
                            <td class="wa-table__num"><?= e(Billing::money((int) $z['amount_rappen'])) ?></td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    <?php endif; ?>
</section>
