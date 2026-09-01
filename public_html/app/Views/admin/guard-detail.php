<?php

/**
 * Eine überwachte Website im Einzelnen.
 *
 * Der Verlauf ist als Balkenreihe gezeichnet, nicht als Tabelle: Ob eine
 * Seite «meistens läuft» oder «immer wieder aussetzt», sieht man in
 * einer Reihe sofort und in einer Liste nie.
 */

use WebAtze\Core\{Config, Csrf};
use WebAtze\Domain\Monitor;

/** @var array<string, mixed> $waechter */
/** @var array<int, array<string, mixed>> $verlauf */
/** @var float|null $quote30 */
/** @var float|null $quote7 */
/** @var array<int, array<string, mixed>> $kunden */
/** @var array<int, array<string, mixed>> $projekte */

$base = '/' . trim((string) Config::get('create_path', 'create'), '/');
$laeuft = (int) $waechter['last_ok'] === 1 && (string) ($waechter['last_checked_at'] ?? '') !== '';

// Der älteste Wert links, der neueste rechts – so liest man Zeit.
$reihe = array_reverse($verlauf);
?>

<div class="wa-tiles">
    <div class="wa-tile<?= $laeuft ? '' : ' wa-tile--warn' ?>">
        <span class="wa-tile__label">Zustand</span>
        <strong class="wa-tile__value"><?= $laeuft ? 'läuft' : 'Ausfall' ?></strong>
    </div>
    <div class="wa-tile">
        <span class="wa-tile__label">Erreichbar (30 Tage)</span>
        <strong class="wa-tile__value">
            <?= $quote30 === null ? '–' : e(number_format($quote30, 2, '.', "'")) . '%' ?>
        </strong>
    </div>
    <div class="wa-tile">
        <span class="wa-tile__label">Erreichbar (7 Tage)</span>
        <strong class="wa-tile__value">
            <?= $quote7 === null ? '–' : e(number_format($quote7, 2, '.', "'")) . '%' ?>
        </strong>
    </div>
    <div class="wa-tile">
        <span class="wa-tile__label">Zuletzt</span>
        <strong class="wa-tile__value"><?= (int) $waechter['last_ms'] ?> ms</strong>
    </div>
</div>

<section class="wa-panel">
    <header class="wa-panel__head">
        <h2 class="wa-panel__title"><?= e((string) $waechter['url']) ?></h2>
        <div class="wa-panel__actions">
            <a class="wa-btn wa-btn--small" href="<?= e((string) $waechter['url']) ?>"
               target="_blank" rel="noopener noreferrer">Öffnen</a>
            <form method="post" action="<?= e($base) ?>/wartung/pruefen">
                <?= Csrf::field() ?>
                <input type="hidden" name="monitor_id" value="<?= (int) $waechter['id'] ?>">
                <button type="submit" class="wa-btn wa-btn--small">Jetzt prüfen</button>
            </form>
        </div>
    </header>

    <?php if (!$laeuft && (string) $waechter['last_note'] !== ''): ?>
        <p class="wa-alert wa-alert--bad"><?= e((string) $waechter['last_note']) ?></p>
    <?php endif; ?>

    <?php if ((string) $waechter['cert_expires_on'] !== ''): ?>
        <?php $knapp = (string) $waechter['cert_expires_on']
            <= date('Y-m-d', time() + Monitor::CERT_WARN_DAYS * 86400); ?>
        <p class="wa-panel__hint">
            Das Sicherheitszertifikat gilt bis
            <strong><?= e(date('d.m.Y', strtotime((string) $waechter['cert_expires_on']) ?: time())) ?></strong>.
            <?= $knapp
                ? 'Das ist bald – wenn es nicht von selbst erneuert wird, läuft die Seite '
                  . 'danach in eine Sicherheitswarnung.'
                : '' ?>
        </p>
    <?php endif; ?>

    <?php if ($reihe === []): ?>
        <p class="wa-empty">Noch keine Prüfung.</p>
    <?php else: ?>
        <div class="wa-uptime" role="img"
             aria-label="Verlauf der letzten <?= count($reihe) ?> Prüfungen">
            <?php foreach ($reihe as $z): ?>
                <span class="wa-uptime__bar<?= (int) $z['ok'] === 1 ? '' : ' is-bad' ?>"
                      title="<?= e(date('d.m. H:i', strtotime((string) $z['checked_at']) ?: time())) ?> · <?=
                          (int) $z['ok'] === 1 ? (int) $z['ms'] . ' ms' : e((string) $z['note']) ?>"></span>
            <?php endforeach; ?>
        </div>

        <div class="wa-table-wrap">
            <table class="wa-table">
                <thead>
                    <tr>
                        <th>Wann</th>
                        <th>Zustand</th>
                        <th class="wa-table__num">Antwort</th>
                        <th class="wa-table__num">Dauer</th>
                        <th>Bemerkung</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach (array_slice($verlauf, 0, 25) as $z): ?>
                        <tr>
                            <td><?= e(date('d.m.Y H:i', strtotime((string) $z['checked_at']) ?: time())) ?></td>
                            <td>
                                <?php if ((int) $z['ok'] === 1): ?>
                                    <span class="wa-badge wa-badge--ok">gut</span>
                                <?php else: ?>
                                    <span class="wa-badge wa-badge--bad">Ausfall</span>
                                <?php endif; ?>
                            </td>
                            <td class="wa-table__num"><?= (int) $z['code'] ?: '–' ?></td>
                            <td class="wa-table__num"><?= (int) $z['ms'] ?> ms</td>
                            <td class="wa-table__quiet"><?= e((string) $z['note']) ?></td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    <?php endif; ?>
</section>

<section class="wa-panel">
    <header class="wa-panel__head">
        <h2 class="wa-panel__title">Einstellungen</h2>
    </header>

    <form method="post" action="<?= e($base) ?>/wartung/waechter" class="wa-form wa-form--inline">
        <?= Csrf::field() ?>
        <input type="hidden" name="monitor_id" value="<?= (int) $waechter['id'] ?>">

        <div class="wa-field">
            <label class="wa-label" for="g-url">Adresse</label>
            <input class="wa-input" type="text" id="g-url" name="url"
                   value="<?= e((string) $waechter['url']) ?>" required>
        </div>

        <div class="wa-field">
            <label class="wa-label" for="g-label">Name</label>
            <input class="wa-input" type="text" id="g-label" name="label"
                   value="<?= e((string) $waechter['label']) ?>">
        </div>

        <div class="wa-field">
            <label class="wa-label" for="g-customer">Kunde</label>
            <select class="wa-select" id="g-customer" name="customer_id">
                <option value="0">– keiner –</option>
                <?php foreach ($kunden as $k): ?>
                    <option value="<?= (int) $k['id'] ?>"
                        <?= (int) ($waechter['customer_id'] ?? 0) === (int) $k['id'] ? ' selected' : '' ?>>
                        <?= e((string) $k['name']) ?>
                    </option>
                <?php endforeach; ?>
            </select>
        </div>

        <div class="wa-field">
            <label class="wa-label" for="g-project">Projekt</label>
            <select class="wa-select" id="g-project" name="project_id">
                <option value="0">– keines –</option>
                <?php foreach ($projekte as $p): ?>
                    <option value="<?= (int) $p['id'] ?>"
                        <?= (int) ($waechter['project_id'] ?? 0) === (int) $p['id'] ? ' selected' : '' ?>>
                        <?= e((string) $p['name']) ?>
                    </option>
                <?php endforeach; ?>
            </select>
        </div>

        <div class="wa-field">
            <label class="wa-label" for="g-every">Alle wie viele Minuten</label>
            <input class="wa-input wa-input--short" type="number" id="g-every" name="every_minutes"
                   min="5" max="1440" value="<?= (int) $waechter['every_minutes'] ?>">
        </div>

        <div class="wa-field wa-field--wide">
            <label class="wa-label" for="g-expect">Dieses Wort muss auf der Seite stehen</label>
            <input class="wa-input" type="text" id="g-expect" name="expect"
                   value="<?= e((string) $waechter['expect']) ?>">
        </div>

        <div class="wa-field wa-field--wide">
            <label class="wa-check-line">
                <input type="checkbox" name="active" value="1"
                    <?= (int) $waechter['active'] === 1 ? 'checked' : '' ?>>
                <span>Überwachen</span>
            </label>
            <label class="wa-check-line">
                <input type="checkbox" name="notify" value="1"
                    <?= (int) $waechter['notify'] === 1 ? 'checked' : '' ?>>
                <span>Bei Ausfall eine E-Mail schicken</span>
            </label>
        </div>

        <div class="wa-form__actions">
            <button type="submit" class="wa-btn wa-btn--primary">Speichern</button>
        </div>
    </form>

    <form method="post" action="<?= e($base) ?>/wartung/entfernen"
          data-confirm="Diese Überwachung samt Verlauf entfernen?">
        <?= Csrf::field() ?>
        <input type="hidden" name="monitor_id" value="<?= (int) $waechter['id'] ?>">
        <button type="submit" class="wa-btn wa-btn--small">Überwachung entfernen</button>
    </form>
</section>
