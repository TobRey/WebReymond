<?php
/**
 * Offerten und Rechnungen.
 *
 * Die eine Frage, die diese Seite beantworten muss: Was ist offen, und
 * was ist überfällig. Alles andere steht darunter.
 */

use WebAtze\Build\DocumentBuilder;
use WebAtze\Core\{Config, Csrf};

/** @var array $rows @var string $kind @var array $summary
 *  @var array $projects @var bool $ibanMissing */

$base = '/' . trim((string) Config::get('create_path', 'create'), '/');

$geld = static fn (int $rappen): string => DocumentBuilder::money($rappen);

$badge = [
    'draft' => '',
    'sent' => 'wa-badge--running',
    'paid' => 'wa-badge--done',
    'cancelled' => 'wa-badge--failed',
];
?>

<div class="wa-stats">
    <div class="wa-stat">
        <span class="wa-stat__value"><?= (int) $summary['offen'] ?></span>
        <span class="wa-stat__label">Rechnungen offen</span>
    </div>
    <div class="wa-stat">
        <span class="wa-stat__value">CHF <?= e($geld((int) $summary['offen_rappen'])) ?></span>
        <span class="wa-stat__label">steht noch aus</span>
    </div>
    <div class="wa-stat">
        <span class="wa-stat__value"><?= (int) $summary['ueberfaellig'] ?></span>
        <span class="wa-stat__label">
            <?= (int) $summary['ueberfaellig'] > 0
                ? '<span class="wa-trend wa-trend--down">überfällig</span>'
                : 'überfällig' ?>
        </span>
    </div>
    <div class="wa-stat">
        <span class="wa-stat__value"><?= (int) $summary['bezahlt'] ?></span>
        <span class="wa-stat__label">bezahlt</span>
    </div>
</div>

<?php if ($ibanMissing): ?>
    <div class="wa-note wa-note--danger" role="alert">
        <div>
            Auf deinen Rechnungen steht keine IBAN – der Kunde weiss dann nicht, wohin
            er zahlen soll. Trag sie unter
            <a href="<?= e($base) ?>/einstellungen">Einstellungen</a> ein.
        </div>
    </div>
<?php endif; ?>

<?php
/*
 * Kommt der Aufruf von einer Kundenseite, sind Name und Adresse
 * bekannt. Die Adresse steht dort als freier Text - die erste Zeile ist
 * fast immer die Strasse, die letzte PLZ und Ort. Wo das nicht stimmt,
 * korrigiert man es im Feld; besser als jedes Mal alles abzutippen.
 */
$empfaenger = ['name' => '', 'attn' => '', 'street' => '', 'city' => '', 'email' => ''];

if (($kunde ?? null) !== null) {
    $zeilen = array_values(array_filter(array_map(
        'trim',
        preg_split('/\R+/', (string) ($kunde['address'] ?? '')) ?: []
    )));

    $empfaenger = [
        'name' => (string) $kunde['name'],
        'attn' => (string) $kunde['contact_name'],
        'street' => $zeilen[0] ?? '',
        'city' => count($zeilen) > 1 ? (string) end($zeilen) : '',
        'email' => (string) $kunde['email'],
    ];
}
?>

<section class="wa-panel wa-panel--neu">
    <div class="wa-panel__head">
        <h2 class="wa-panel__title">Neu anlegen</h2>
        <p class="wa-panel__hint">
            Beträge in Franken, mit oder ohne Rappen. „990.-" versteht das Feld auch.
        </p>
    </div>

    <form method="post" action="<?= e($base) ?>/rechnungen">
        <?= Csrf::field() ?>

        <div class="wa-grid-2">
            <div class="wa-field">
                <label class="wa-label" for="d-kind">Art</label>
                <select class="wa-input" id="d-kind" name="kind">
                    <?php foreach (DocumentBuilder::KINDS as $key => $label): ?>
                        <option value="<?= e($key) ?>"<?= $vorgabe === $key ? ' selected' : '' ?>>
                            <?= e($label) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="wa-field">
                <label class="wa-label" for="d-customer">Kunde</label>
                <select class="wa-input" id="d-customer" name="customer_id">
                    <option value="0">– kein Kunde –</option>
                    <?php foreach ($kunden as $k): ?>
                        <option value="<?= (int) $k['id'] ?>"
                            <?= $kunde !== null && (int) $kunde['id'] === (int) $k['id'] ? ' selected' : '' ?>>
                            <?= e((string) $k['name']) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="wa-field">
                <label class="wa-label" for="d-project">Gehört zu</label>
                <select class="wa-input" id="d-project" name="project_id">
                    <option value="0">– keine Website –</option>
                    <?php foreach ($projects as $project): ?>
                        <option value="<?= (int) $project['id'] ?>"
                            <?= $kunde !== null && (int) ($kunde['project_id'] ?? 0) === (int) $project['id']
                                ? ' selected' : '' ?>>
                            <?= e((string) $project['name']) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="wa-field">
                <label class="wa-label" for="d-title">Betreff</label>
                <input class="wa-input" type="text" id="d-title" name="title" maxlength="190"
                       placeholder="Neue Website inkl. Bearbeitungsbereich">
            </div>
            <div class="wa-field">
                <label class="wa-label" for="d-vat">Mehrwertsteuer in Prozent</label>
                <input class="wa-input wa-input--short" type="number" id="d-vat" name="vat_percent"
                       min="0" max="30" step="1" value="0">
                <span class="wa-label__hint">0, solange du nicht steuerpflichtig bist.</span>
            </div>
        </div>

        <div class="wa-grid-2">
            <div class="wa-field">
                <label class="wa-label" for="d-rname">Empfänger</label>
                <input class="wa-input" type="text" id="d-rname" name="recipient_name" maxlength="190"
                       value="<?= e($empfaenger['name']) ?>"
                       placeholder="Schreinerei Muster AG">
            </div>
            <div class="wa-field">
                <label class="wa-label" for="d-rattn">Zu Handen von</label>
                <input class="wa-input" type="text" id="d-rattn" name="recipient_attn" maxlength="190"
                       value="<?= e($empfaenger['attn']) ?>"
                       placeholder="Herr Peter Muster">
            </div>
            <div class="wa-field">
                <label class="wa-label" for="d-rstreet">Strasse</label>
                <input class="wa-input" type="text" id="d-rstreet" name="recipient_street" maxlength="190"
                       value="<?= e($empfaenger['street']) ?>">
            </div>
            <div class="wa-field">
                <label class="wa-label" for="d-rcity">PLZ und Ort</label>
                <input class="wa-input" type="text" id="d-rcity" name="recipient_city" maxlength="190"
                       value="<?= e($empfaenger['city']) ?>">
            </div>
            <div class="wa-field">
                <label class="wa-label" for="d-remail">E-Mail des Empfängers</label>
                <input class="wa-input" type="email" id="d-remail" name="recipient_email" maxlength="190"
                       value="<?= e($empfaenger['email']) ?>">
            </div>
            <div class="wa-field">
                <label class="wa-label" for="d-due">Zahlungsfrist in Tagen</label>
                <input class="wa-input wa-input--short" type="number" id="d-due" name="due_days"
                       min="0" max="180" step="1" value="30">
            </div>
        </div>

        <div class="wa-field">
            <label class="wa-label">Positionen</label>
            <?php for ($i = 0; $i < 5; $i++): ?>
                <div class="wa-posten">
                    <input class="wa-input" type="text" name="item_label[]" maxlength="500"
                           placeholder="<?= $i === 0 ? 'Website mit fünf Seiten' : 'Weitere Leistung' ?>"
                           aria-label="Leistung">
                    <input class="wa-input wa-input--short" type="text" name="item_quantity[]"
                           value="1" aria-label="Menge">
                    <input class="wa-input wa-input--short" type="text" name="item_price[]"
                           placeholder="0.00" aria-label="Einzelpreis in Franken">
                </div>
            <?php endfor; ?>
        </div>

        <div class="wa-grid-2">
            <div class="wa-field">
                <label class="wa-label" for="d-intro">Anrede und Einleitung</label>
                <textarea class="wa-input" id="d-intro" name="intro" rows="4"
                          placeholder="Guten Tag&#10;&#10;Besten Dank für Ihr Interesse. Gerne unterbreite ich Ihnen folgendes Angebot."></textarea>
            </div>
            <div class="wa-field">
                <label class="wa-label" for="d-outro">Schlusswort</label>
                <textarea class="wa-input" id="d-outro" name="outro" rows="4"
                          placeholder="Ich freue mich auf Ihre Rückmeldung."></textarea>
            </div>
        </div>

        <button type="submit" class="wa-btn">Anlegen und PDF erzeugen</button>
    </form>
</section>

<section class="wa-panel">
    <div class="wa-panel__head">
        <h2 class="wa-panel__title">Alles bisher</h2>
        <div class="wa-panel__actions">
            <a class="wa-chip<?= $kind === '' ? ' is-active' : '' ?>"
               href="<?= e($base) ?>/rechnungen">Alle</a>
            <?php foreach (DocumentBuilder::KINDS as $key => $label): ?>
                <a class="wa-chip<?= $kind === $key ? ' is-active' : '' ?>"
                   href="<?= e($base) ?>/rechnungen?art=<?= e($key) ?>"><?= e($label) ?>n</a>
            <?php endforeach; ?>
        </div>
    </div>

    <?php if ($rows === []): ?>
        <p class="wa-panel__body">Noch nichts angelegt.</p>
    <?php else: ?>
        <div class="wa-table-wrap">
            <table class="wa-table">
                <thead>
                <tr>
                    <th>Nummer</th>
                    <th>Empfänger</th>
                    <th>Datum</th>
                    <th class="wa-table__right">Betrag</th>
                    <th>Zustand</th>
                    <th></th>
                </tr>
                </thead>
                <tbody>
                <?php foreach ($rows as $row): ?>
                    <tr>
                        <td>
                            <code><?= e((string) $row['number']) ?></code>
                            <?php if ((string) $row['title'] !== ''): ?>
                                <br><span class="wa-muted"><?= e((string) $row['title']) ?></span>
                            <?php endif; ?>
                        </td>
                        <td>
                            <?= e((string) (((array) $row['recipient'])['name'] ?? '–')) ?>
                            <?php if ((string) ($row['project_name'] ?? '') !== ''): ?>
                                <br><span class="wa-muted"><?= e((string) $row['project_name']) ?></span>
                            <?php endif; ?>
                        </td>
                        <td>
                            <?= e(date('d.m.Y', strtotime((string) $row['issued_on']))) ?>
                            <?php if ((string) $row['due_on'] !== ''): ?>
                                <br><span class="wa-muted">
                                    fällig <?= e(date('d.m.Y', strtotime((string) $row['due_on']))) ?>
                                </span>
                            <?php endif; ?>
                        </td>
                        <td class="wa-table__right">
                            <?= e((string) $row['currency']) ?>
                            <?= e($geld((int) $row['total_rappen'])) ?>
                        </td>
                        <td>
                            <span class="wa-badge <?= e($badge[(string) $row['status']] ?? '') ?>">
                                <?= e(DocumentBuilder::STATUS[(string) $row['status']] ?? (string) $row['status']) ?>
                            </span>
                            <?php if (!empty($row['overdue'])): ?>
                                <br><span class="wa-trend wa-trend--down">überfällig</span>
                            <?php endif; ?>
                        </td>
                        <td class="wa-table__right">
                            <div class="wa-panel__actions">
                                <a class="wa-btn wa-btn--quiet wa-btn--sm"
                                   href="<?= e($base) ?>/rechnungen/<?= (int) $row['id'] ?>/pdf">PDF</a>

                                <?php if ((string) $row['status'] !== 'paid'
                                          && (string) $row['status'] !== 'cancelled'): ?>
                                    <form method="post" action="<?= e($base) ?>/rechnungen/<?= (int) $row['id'] ?>/zustand">
                                        <?= Csrf::field() ?>
                                        <input type="hidden" name="status"
                                               value="<?= (string) $row['status'] === 'draft' ? 'sent' : 'paid' ?>">
                                        <button type="submit" class="wa-btn wa-btn--sm">
                                            <?= (string) $row['status'] === 'draft' ? 'Verschickt' : 'Bezahlt' ?>
                                        </button>
                                    </form>
                                <?php endif; ?>

                                <?php if ((string) $row['kind'] === 'offer'): ?>
                                    <form method="post" action="<?= e($base) ?>/rechnungen/<?= (int) $row['id'] ?>/rechnung">
                                        <?= Csrf::field() ?>
                                        <button type="submit" class="wa-btn wa-btn--quiet wa-btn--sm">
                                            Zur Rechnung
                                        </button>
                                    </form>
                                <?php endif; ?>

                                <?php if (trim((string) (((array) $row['recipient'])['email'] ?? '')) !== ''): ?>
                                    <form method="post" action="<?= e($base) ?>/rechnungen/<?= (int) $row['id'] ?>/senden">
                                        <?= Csrf::field() ?>
                                        <button type="submit" class="wa-btn wa-btn--quiet wa-btn--sm">
                                            Mail an Kunden
                                        </button>
                                    </form>
                                <?php endif; ?>
                            </div>
                        </td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    <?php endif; ?>

    <p class="wa-panel__body">
        Der Einzahlungsschein (die QR-Rechnung) ist nicht dabei. Der braucht einen
        QR-Code nach Schweizer Norm, und einen halbherzigen zu erzeugen wäre schlimmer
        als keinen: Eine Rechnung, deren Code die Bank nicht liest, kostet den Kunden
        Zeit. IBAN und Verwendungszweck stehen im Text – damit lässt sich jede Zahlung
        auslösen.
    </p>
</section>
