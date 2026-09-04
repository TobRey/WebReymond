<?php

/**
 * Wartungscenter: läuft alles, und ist alles gesichert?
 *
 * Ganz oben steht, was gerade nicht läuft. Wenn dort nichts steht, ist
 * das die beste Nachricht des Tages – und sie soll auch so aussehen.
 */

use WebAtze\Core\{Config, Csrf};
use WebAtze\Domain\Monitor;

/** @var array<int, array<string, mixed>> $waechter */
/** @var array<string, int> $kurz */
/** @var array<int, array<string, mixed>> $unten */
/** @var array<int, array<string, mixed>> $sicherungen */
/** @var int $bytes */
/** @var array<string, mixed>|null $letzteEigene */
/** @var int $behalten */
/** @var array<int, array<string, mixed>> $kunden */
/** @var array<int, array<string, mixed>> $projekte */
/** @var int $certWarnDays */

$base = '/' . trim((string) Config::get('create_path', 'create'), '/');

$mb = static fn (int $b): string => $b < 1048576
    ? number_format($b / 1024, 0, '.', "'") . ' KB'
    : number_format($b / 1048576, 1, '.', "'") . ' MB';

$certGrenze = date('Y-m-d', time() + $certWarnDays * 86400);
?>

<div class="wa-tiles">
    <div class="wa-tile<?= $kurz['unten'] > 0 ? ' wa-tile--warn' : '' ?>">
        <span class="wa-tile__label">Nicht erreichbar</span>
        <strong class="wa-tile__value"><?= (int) $kurz['unten'] ?></strong>
    </div>
    <div class="wa-tile">
        <span class="wa-tile__label">Überwacht</span>
        <strong class="wa-tile__value"><?= (int) $kurz['aktiv'] ?></strong>
    </div>
    <div class="wa-tile<?= $kurz['zertifikate'] > 0 ? ' wa-tile--warn' : '' ?>">
        <span class="wa-tile__label">Zertifikat läuft ab</span>
        <strong class="wa-tile__value"><?= (int) $kurz['zertifikate'] ?></strong>
    </div>
    <div class="wa-tile">
        <span class="wa-tile__label">Sicherungen belegen</span>
        <strong class="wa-tile__value"><?= e($mb($bytes)) ?></strong>
    </div>
</div>

<?php if ($unten !== []): ?>
<section class="wa-panel wa-panel--alarm">
    <header class="wa-panel__head">
        <h2 class="wa-panel__title">Diese Seiten antworten nicht</h2>
    </header>

    <ul class="wa-alarms">
        <?php foreach ($unten as $w): ?>
            <li class="wa-alarm">
                <strong><?= e((string) ($w['label'] ?: $w['url'])) ?></strong>
                <?php if ((string) ($w['kunde'] ?? '') !== ''): ?>
                    <span class="wa-table__quiet"><?= e((string) $w['kunde']) ?></span>
                <?php endif; ?>
                <p><?= e((string) $w['last_note'] ?: 'Grund unbekannt.') ?></p>
                <?php if ((string) ($w['down_since'] ?? '') !== ''): ?>
                    <p class="wa-table__quiet">
                        Seit <?= e(date('d.m.Y H:i', strtotime((string) $w['down_since']) ?: time())) ?> Uhr.
                    </p>
                <?php endif; ?>
                <a class="wa-btn wa-btn--small" href="<?= e($base) ?>/wartung/<?= (int) $w['id'] ?>">Ansehen</a>
            </li>
        <?php endforeach; ?>
    </ul>
</section>
<?php endif; ?>

<section class="wa-panel">
    <header class="wa-panel__head">
        <h2 class="wa-panel__title">Überwachte Websites</h2>
        <form method="post" action="<?= e($base) ?>/wartung/alle-pruefen">
            <?= Csrf::field() ?>
            <button type="submit" class="wa-btn">Jetzt alle prüfen</button>
        </form>
    </header>

    <?php if ($waechter === []): ?>
        <p class="wa-empty">
            Noch nichts überwacht. Unten eine Adresse eintragen – dann wird sie alle paar
            Minuten aufgerufen, und bei einem Ausfall kommt eine E-Mail.
        </p>
    <?php else: ?>
        <div class="wa-table-wrap">
            <table class="wa-table">
                <thead>
                    <tr>
                        <th>Website</th>
                        <th>Zustand</th>
                        <th class="wa-table__num">Tempo</th>
                        <th>Zertifikat</th>
                        <th>Zuletzt geprüft</th>
                        <th></th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($waechter as $w): ?>
                        <?php
                            $geprueft = (string) ($w['last_checked_at'] ?? '');
                            $laeuft = (int) $w['last_ok'] === 1;
                            $zert = (string) $w['cert_expires_on'];
                        ?>
                        <tr>
                            <td>
                                <a class="wa-table__main" href="<?= e($base) ?>/wartung/<?= (int) $w['id'] ?>">
                                    <?= e((string) ($w['label'] ?: $w['url'])) ?>
                                </a>
                                <span class="wa-table__quiet">
                                    <?= e((string) ($w['kunde'] ?? $w['projekt'] ?? '')) ?>
                                </span>
                            </td>
                            <td>
                                <?php if ((int) $w['active'] !== 1): ?>
                                    <span class="wa-badge">pausiert</span>
                                <?php elseif ($geprueft === ''): ?>
                                    <span class="wa-badge">noch nicht geprüft</span>
                                <?php elseif ($laeuft): ?>
                                    <span class="wa-badge wa-badge--ok">läuft</span>
                                <?php else: ?>
                                    <span class="wa-badge wa-badge--bad">Ausfall</span>
                                <?php endif; ?>
                            </td>
                            <td class="wa-table__num">
                                <?= $geprueft !== '' ? (int) $w['last_ms'] . ' ms' : '–' ?>
                            </td>
                            <td class="wa-table__quiet">
                                <?php if ($zert === ''): ?>
                                    –
                                <?php elseif ($zert <= $certGrenze): ?>
                                    <span class="wa-badge wa-badge--bad">
                                        bis <?= e(date('d.m.Y', strtotime($zert) ?: time())) ?>
                                    </span>
                                <?php else: ?>
                                    bis <?= e(date('d.m.Y', strtotime($zert) ?: time())) ?>
                                <?php endif; ?>
                            </td>
                            <td class="wa-table__quiet">
                                <?= $geprueft !== ''
                                    ? e(date('d.m. H:i', strtotime($geprueft) ?: time()))
                                    : 'nie' ?>
                            </td>
                            <td class="wa-table__actions">
                                <form method="post" action="<?= e($base) ?>/wartung/pruefen">
                                    <?= Csrf::field() ?>
                                    <input type="hidden" name="monitor_id" value="<?= (int) $w['id'] ?>">
                                    <button type="submit" class="wa-btn wa-btn--small">Prüfen</button>
                                </form>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    <?php endif; ?>

    <details class="wa-details">
        <summary>Website zur Überwachung hinzufügen</summary>
        <form method="post" action="<?= e($base) ?>/wartung/waechter" class="wa-form wa-form--inline">
            <?= Csrf::field() ?>

            <div class="wa-field">
                <label class="wa-label" for="m-url">Adresse</label>
                <input class="wa-input" type="text" id="m-url" name="url" required
                       placeholder="https://kunde.ch">
            </div>

            <div class="wa-field">
                <label class="wa-label" for="m-label">Name</label>
                <input class="wa-input" type="text" id="m-label" name="label"
                       placeholder="leer lassen für den Domainnamen">
            </div>

            <div class="wa-field">
                <label class="wa-label" for="m-customer">Kunde</label>
                <select class="wa-select" id="m-customer" name="customer_id">
                    <option value="0">– keiner –</option>
                    <?php foreach ($kunden as $k): ?>
                        <option value="<?= (int) $k['id'] ?>"><?= e((string) $k['name']) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>

            <div class="wa-field">
                <label class="wa-label" for="m-every">Alle wie viele Minuten</label>
                <input class="wa-input wa-input--short" type="number" id="m-every" name="every_minutes"
                       min="5" max="1440" value="15">
            </div>

            <div class="wa-field wa-field--wide">
                <label class="wa-label" for="m-expect">Dieses Wort muss auf der Seite stehen</label>
                <input class="wa-input" type="text" id="m-expect" name="expect"
                       placeholder="optional – z.B. der Firmenname">
                <p class="wa-hint">
                    Ein Server kann «alles in Ordnung» melden und trotzdem eine leere
                    Fehlerseite ausliefern. Steht hier ein Wort, wird auch das geprüft.
                </p>
            </div>

            <div class="wa-field wa-field--wide">
                <label class="wa-check-line">
                    <input type="checkbox" name="active" value="1" checked>
                    <span>Überwachen</span>
                </label>
                <label class="wa-check-line">
                    <input type="checkbox" name="notify" value="1" checked>
                    <span>Bei Ausfall eine E-Mail schicken</span>
                </label>
            </div>

            <div class="wa-form__actions">
                <button type="submit" class="wa-btn wa-btn--primary">Hinzufügen</button>
            </div>
        </form>
    </details>
</section>

<section class="wa-panel" id="sicherungen">
    <header class="wa-panel__head">
        <h2 class="wa-panel__title">Sicherungen</h2>
        <div class="wa-panel__actions">
            <?php /* Zwei getrennte Archive mit Absicht: Das eine hat die
                     Daten, das andere den Schluessel dazu. Wer nur eines
                     davon in die Haende bekommt, hat nichts. */ ?>
            <form method="post" action="<?= e($base) ?>/wartung/sicherung/jetzt">
                <?= Csrf::field() ?>
                <input type="hidden" name="art" value="dateien">
                <button type="submit" class="wa-btn wa-btn--sm">Dateien jetzt sichern</button>
            </form>
            <form method="post" action="<?= e($base) ?>/wartung/sicherung/jetzt">
                <?= Csrf::field() ?>
                <input type="hidden" name="art" value="datenbank">
                <button type="submit" class="wa-btn wa-btn--sm">Datenbank jetzt sichern</button>
            </form>
            <form method="post" action="<?= e($base) ?>/wartung/sichern">
                <?= Csrf::field() ?>
                <button type="submit" class="wa-btn wa-btn--quiet wa-btn--sm">Kundenseite sichern</button>
            </form>
        </div>
    </header>

    <p class="wa-panel__hint">
        Gesichert wird von selbst, einmal am Tag: <strong>alle Dateien</strong> der
        Installation als ein Archiv mit Datum, <strong>die Datenbank</strong> als zweites,
        dazu reihum je eine Kundenwebsite. Von einer Kundenwebsite bleiben die
        <strong><?= (int) $behalten ?></strong> neuesten Stände liegen, ältere fallen beim
        Anlegen eines neuen weg.
        <?php if ($letzteEigene !== null): ?>
            Die eigene Datenbank zuletzt am
            <?= e(date('d.m.Y \u\m H:i', strtotime((string) $letzteEigene['created_at']) ?: time())) ?> Uhr.
        <?php endif; ?>
    </p>

    <div class="wa-note">
        <div>
            <strong>Das Dateiarchiv enthält <code>app/config.php</code> – und damit den
            Schlüssel zum Tresor.</strong>
            <p class="wa-hint">
                Das ist Absicht: Ohne diesen Schlüssel wäre nach einem Ausfall jedes
                Passwort im Tresor verloren. Der Schutz liegt darin, dass Schlüssel und
                verschlüsselte Daten in zwei getrennten Archiven stehen. Lade sie herunter
                und lege sie an <em>verschiedenen</em> Orten ab.
            </p>
        </div>
    </div>

    <form method="post" action="<?= e($base) ?>/wartung/aufbewahrung" class="wa-form wa-form--inline">
        <?= Csrf::field() ?>
        <div class="wa-field wa-field--wide">
            <label class="wa-label" for="behalten">Stände je Website behalten</label>
            <input class="wa-input wa-input--short" type="number" id="behalten" name="behalten"
                   min="1" max="30" value="<?= (int) $behalten ?>">
            <p class="wa-hint">
                Eins ist erlaubt, aber riskant: Wird eine bereits beschädigte Seite gesichert,
                überschreibt sie den letzten guten Stand. Zwei kosten wenig und retten viel.
            </p>
        </div>
        <div class="wa-form__actions">
            <button type="submit" class="wa-btn">Übernehmen</button>
        </div>
    </form>

    <?php if ($sicherungen === []): ?>
        <p class="wa-empty">Noch nichts gesichert.</p>
    <?php else: ?>
        <div class="wa-table-wrap">
            <table class="wa-table">
                <thead>
                    <tr>
                        <th>Wann</th>
                        <th>Was</th>
                        <th class="wa-table__num">Dateien</th>
                        <th class="wa-table__num">Grösse</th>
                        <th></th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($sicherungen as $s): ?>
                        <tr>
                            <td><?= e(date('d.m.Y H:i', strtotime((string) $s['created_at']) ?: time())) ?></td>
                            <?php
                                $art = (string) $s['scope'];
                                $was = match ($art) {
                                    'self' => 'Eigene Datenbank',
                                    'files' => 'Alle Dateien',
                                    default => (string) ($s['project_name'] ?? 'Kundenwebsite'),
                                };
                            ?>
                            <td>
                                <?= e($was) ?>
                                <?php if ($art === 'files'): ?>
                                    <span class="wa-badge">mit Schlüssel</span>
                                <?php endif; ?>
                            </td>
                            <td class="wa-table__num"><?= (int) $s['files'] ?></td>
                            <td class="wa-table__num"><?= e($mb((int) $s['bytes'])) ?></td>
                            <td class="wa-table__actions">
                                <div class="wa-panel__actions">
                                    <a class="wa-btn wa-btn--small"
                                       href="<?= e($base) ?>/wartung/sicherung/<?= (int) $s['id'] ?>">Laden</a>
                                    <form method="post" action="<?= e($base) ?>/wartung/sicherung/loeschen"
                                          data-confirm="Diese Sicherung wirklich löschen? Sie ist danach weg – lade sie vorher herunter, wenn du sie behalten willst.">
                                        <?= Csrf::field() ?>
                                        <input type="hidden" name="backup_id" value="<?= (int) $s['id'] ?>">
                                        <button type="submit" class="wa-btn wa-btn--small wa-btn--danger">
                                            Löschen
                                        </button>
                                    </form>
                                </div>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    <?php endif; ?>
</section>
