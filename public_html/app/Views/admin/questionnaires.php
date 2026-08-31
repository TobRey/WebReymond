<?php
/**
 * Fragebögen.
 *
 * Ein Verweis pro Kunde. Er füllt aus, du übernimmst die Antworten mit
 * einem Klick ins Formular für eine neue Website.
 */

use WebAtze\Core\{Config, Csrf};

/** @var array $rows @var array $fields @var string $publicBase */

$base = '/' . trim((string) Config::get('create_path', 'create'), '/');

$label = [];
foreach ($fields as $field) {
    $label[(string) $field['name']] = (string) $field['label'];
}

$zustand = [
    'open' => ['Verschickt', ''],
    'submitted' => ['Ausgefüllt', 'wa-badge--done'],
    'used' => ['Übernommen', 'wa-badge--running'],
];
?>

<p class="wa-intro">
    Statt am Telefon mitzuschreiben: Der Kunde füllt selbst aus. Was dabei
    herauskommt, füllt das Formular für eine neue Website vor – ändern kannst du
    danach alles.
</p>

<section class="wa-panel wa-panel--neu">
    <div class="wa-panel__head">
        <h2 class="wa-panel__title">Neuen Verweis erzeugen</h2>
        <p class="wa-panel__hint">Er gilt 30 Tage und braucht kein Konto.</p>
    </div>

    <form method="post" action="<?= e($base) ?>/fragebogen" class="wa-grid-2">
        <?= Csrf::field() ?>
        <div class="wa-field">
            <label class="wa-label" for="q-company">Firma</label>
            <input class="wa-input" type="text" id="q-company" name="company" maxlength="190"
                   placeholder="Schreinerei Muster">
        </div>
        <div class="wa-field">
            <label class="wa-label" for="q-email">E-Mail des Kunden</label>
            <input class="wa-input" type="email" id="q-email" name="email" maxlength="190"
                   placeholder="info@muster.ch">
        </div>
        <div class="wa-field">
            <label class="wa-label" for="q-note">Notiz für dich</label>
            <input class="wa-input" type="text" id="q-note" name="note" maxlength="490"
                   placeholder="Über Peter kennengelernt, will es bis Ende Monat">
        </div>
        <div class="wa-field">
            <label class="wa-checkbox">
                <input type="checkbox" name="send" value="1" checked>
                <span>Verweis gleich per E-Mail schicken</span>
            </label>
            <button type="submit" class="wa-btn">Verweis erzeugen</button>
        </div>
    </form>
</section>

<?php if ($rows === []): ?>
    <section class="wa-panel">
        <p class="wa-panel__body">Noch kein Fragebogen verschickt.</p>
    </section>
<?php endif; ?>

<?php foreach ($rows as $row): ?>
    <?php
    $status = (string) $row['status'];
    $answers = (array) $row['answers'];
    $url = $publicBase . (string) $row['token'];
    ?>
    <section class="wa-panel">
        <div class="wa-panel__head">
            <h2 class="wa-panel__title">
                <?= e((string) $row['company'] !== '' ? (string) $row['company'] : 'Ohne Namen') ?>
                <span class="wa-badge <?= e($zustand[$status][1] ?? '') ?>">
                    <?= e($zustand[$status][0] ?? $status) ?>
                </span>
            </h2>
            <p class="wa-panel__hint">
                Angelegt am <?= e(date('d.m.Y', strtotime((string) $row['created_at']))) ?>,
                gültig bis <?= e(date('d.m.Y', strtotime((string) $row['expires_at']))) ?>.
                <?php if ($row['opened_at'] !== null): ?>
                    Geöffnet am <?= e(date('d.m.Y H:i', strtotime((string) $row['opened_at']))) ?>.
                <?php else: ?>
                    Noch nicht geöffnet.
                <?php endif; ?>
                <?php if ((string) $row['note'] !== ''): ?>
                    <br><em><?= e((string) $row['note']) ?></em>
                <?php endif; ?>
            </p>
            <div class="wa-panel__actions">
                <?php if ($status === 'submitted'): ?>
                    <form method="post" action="<?= e($base) ?>/fragebogen/<?= (int) $row['id'] ?>/uebernehmen">
                        <?= Csrf::field() ?>
                        <button type="submit" class="wa-btn wa-btn--sm">Ins Formular übernehmen</button>
                    </form>
                <?php endif; ?>
                <form method="post" action="<?= e($base) ?>/fragebogen/<?= (int) $row['id'] ?>/loeschen">
                    <?= Csrf::field() ?>
                    <button type="submit" class="wa-btn wa-btn--quiet wa-btn--sm">Löschen</button>
                </form>
            </div>
        </div>

        <?php if ($answers === []): ?>
            <p class="wa-panel__body">
                Noch nichts ausgefüllt. Der Verweis:
                <code><?= e($url) ?></code>
            </p>
        <?php else: ?>
            <div class="wa-table-wrap">
                <table class="wa-table">
                    <tbody>
                    <?php foreach ($answers as $key => $value): ?>
                        <?php if (trim((string) $value) === '') { continue; } ?>
                        <tr>
                            <th scope="row"><?= e($label[(string) $key] ?? (string) $key) ?></th>
                            <td><?= nl2br(e((string) $value)) ?></td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php endif; ?>
    </section>
<?php endforeach; ?>
