<?php
/**
 * Sicherungen.
 *
 * Sie laufen von selbst mit der stündlichen Pflege. Was hier steht, ist
 * die Antwort auf die einzige Frage, die im Ernstfall zählt: Gibt es
 * einen Stand von gestern, und komme ich an ihn heran?
 */

use WebAtze\Core\{Config, Csrf};

/** @var array $rows @var int $bytes @var array|null $lastSelf */

$base = '/' . trim((string) Config::get('create_path', 'create'), '/');

$groesse = static function (int $bytes): string {
    if ($bytes >= 1048576) {
        return number_format($bytes / 1048576, 1, ',', "'") . ' MB';
    }
    if ($bytes >= 1024) {
        return number_format($bytes / 1024, 0, ',', "'") . ' KB';
    }
    return $bytes . ' B';
};
?>

<p class="wa-intro">
    Einmal am Tag wird WebAtze gesichert, dazu bei jedem Durchgang der Pflege eine
    Kundenwebsite. Aufbewahrt werden die letzten 14 Tage und je der Monatserste der
    letzten sechs Monate; alles Ältere wird gelöscht.
</p>

<div class="wa-stats">
    <div class="wa-stat">
        <span class="wa-stat__label">Letzte eigene Sicherung</span>
        <strong class="wa-stat__value">
            <?= $lastSelf !== null
                ? e(date('d.m.Y', strtotime((string) $lastSelf['created_at'])))
                : 'noch keine' ?>
        </strong>
        <?php if ($lastSelf !== null): ?>
            <span class="wa-stat__label"><?= (int) $lastSelf['files'] ?> Dateien,
                <?= e($groesse((int) $lastSelf['bytes'])) ?></span>
        <?php endif ?>
    </div>
    <div class="wa-stat">
        <span class="wa-stat__label">Stände insgesamt</span>
        <strong class="wa-stat__value"><?= count($rows) ?></strong>
        <span class="wa-stat__label"><?= e($groesse($bytes)) ?> auf der Festplatte</span>
    </div>
    <div class="wa-stat">
        <span class="wa-stat__label">Von Hand anstossen</span>
        <form method="post" action="<?= e($base) ?>/sicherungen">
            <?= Csrf::field() ?>
            <button type="submit" class="wa-btn wa-btn--sm">Jetzt sichern</button>
        </form>
        <span class="wa-stat__label">Nötig ist das nicht – es läuft ohnehin.</span>
    </div>
</div>

<section class="wa-panel">
    <div class="wa-panel__head">
        <h2 class="wa-panel__title">Vorhandene Stände</h2>
    </div>

    <?php if ($rows === []): ?>
        <p class="wa-panel__body">
            Noch nichts gesichert. Der erste Stand entsteht, sobald die Pflege das
            nächste Mal läuft – also innerhalb der nächsten Stunde.
        </p>
    <?php else: ?>
        <div class="wa-table-wrap">
            <table class="wa-table">
                <thead>
                <tr>
                    <th>Datum</th>
                    <th>Was</th>
                    <th>Inhalt</th>
                    <th class="wa-table__right">Dateien</th>
                    <th class="wa-table__right">Grösse</th>
                    <th></th>
                </tr>
                </thead>
                <tbody>
                <?php foreach ($rows as $row): ?>
                    <?php $exists = (string) $row['path'] !== '' && is_file((string) $row['path']); ?>
                    <tr>
                        <td><?= e(date('d.m.Y H:i', strtotime((string) $row['created_at']))) ?></td>
                        <td>
                            <?php if ((string) $row['scope'] === 'self'): ?>
                                <strong>WebAtze selbst</strong>
                            <?php else: ?>
                                <a href="<?= e($base) ?>/projekt/<?= (int) $row['project_id'] ?>">
                                    <?= e((string) ($row['project_name'] ?? $row['project_slug'] ?? 'Projekt')) ?>
                                </a>
                            <?php endif ?>
                        </td>
                        <td><?= e((string) $row['note']) ?></td>
                        <td class="wa-table__right"><?= (int) $row['files'] ?></td>
                        <td class="wa-table__right"><?= e($groesse((int) $row['bytes'])) ?></td>
                        <td class="wa-table__right">
                            <?php if ($exists): ?>
                                <a class="wa-btn wa-btn--quiet wa-btn--sm"
                                   href="<?= e($base) ?>/sicherungen/<?= (int) $row['id'] ?>">
                                    Herunterladen
                                </a>
                            <?php else: ?>
                                <span class="wa-muted">Datei fehlt</span>
                            <?php endif ?>
                        </td>
                    </tr>
                <?php endforeach ?>
                </tbody>
            </table>
        </div>
    <?php endif ?>
</section>

<section class="wa-panel">
    <div class="wa-panel__head">
        <h2 class="wa-panel__title">Was nicht in der Sicherung steckt</h2>
    </div>
    <p class="wa-panel__body">
        <code>app/config.php</code> ist absichtlich nicht dabei. Dort stehen die
        Zugangsdaten zur Datenbank und der Schlüssel, mit dem die FTP-Zugänge
        verschlüsselt sind. Eine Sicherung wandert herum – kopiert, heruntergeladen,
        weitergegeben. Läge der Schlüssel darin, wäre die Verschlüsselung wertlos.
    </p>
    <p class="wa-panel__body">
        Bewahre diese eine Datei getrennt auf. Ohne sie lassen sich die FTP-Zugänge in
        der gesicherten Datenbank nicht mehr entschlüsseln – alles andere kommt zurück.
    </p>
</section>
