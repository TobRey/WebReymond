<?php

/**
 * Die Kundenliste.
 *
 * Die Spalte, auf die es ankommt, ist "offen". Alles andere ist Beiwerk.
 */

use WebAtze\Core\{Config, Csrf};
use WebAtze\Domain\Billing;

/** @var array<int, array<string, mixed>> $kunden */
/** @var string $suche */
/** @var string $zeigen */
/** @var array<string, int> $zahlen */
/** @var array<string, int> $ohneZuordnung */

$base = '/' . trim((string) Config::get('create_path', 'create'), '/');
?>

<div class="wa-tiles">
    <div class="wa-tile">
        <span class="wa-tile__label">Aktive Kunden</span>
        <strong class="wa-tile__value"><?= (int) $zahlen['kunden'] ?></strong>
    </div>
    <div class="wa-tile<?= $zahlen['offen_rappen'] > 0 ? ' wa-tile--warn' : '' ?>">
        <span class="wa-tile__label">Offen</span>
        <strong class="wa-tile__value"><?= e(Billing::money((int) $zahlen['offen_rappen'])) ?></strong>
    </div>
    <div class="wa-tile">
        <span class="wa-tile__label">Diesen Monat eingegangen</span>
        <strong class="wa-tile__value"><?= e(Billing::money((int) $zahlen['monat_rappen'])) ?></strong>
    </div>
</div>

<?php
/**
 * Was zu keinem Kunden gehört.
 *
 * Die Listen für Websites, Rechnungen, Verträge und Passwörter sind
 * nicht mehr im Menü – sie stehen jetzt beim jeweiligen Kunden. Was
 * keinen hat, wäre damit unerreichbar geworden. Deshalb steht es hier,
 * ganz oben, und zwar nur dann, wenn es tatsächlich etwas gibt: Ein
 * Hinweis, der immer da ist, wird nach einer Woche nicht mehr gelesen.
 */
?>
<?php if ((int) ($ohneZuordnung['summe'] ?? 0) > 0): ?>
    <section class="wa-panel">
        <header class="wa-panel__head">
            <h2 class="wa-panel__title">Ohne Zuordnung</h2>
        </header>

        <p class="wa-hint">
            Das hier gehört zu keinem Kunden. Beim Kunden zugeordnet, taucht es
            auf dessen Seite auf – und ist von dort aus zu finden.
        </p>

        <div class="wa-found__list">
            <?php
            $orte = [
                'websites' => ['Websites', '/websites?kunde=-1'],
                'rechnungen' => ['Offerten und Rechnungen', '/rechnungen'],
                'vertraege' => ['Wartungsverträge', '/vertraege'],
                'passwoerter' => ['Passwörter', '/passwoerter'],
            ];
            ?>
            <?php foreach ($orte as $schluessel => [$text, $ziel]): ?>
                <?php if ((int) ($ohneZuordnung[$schluessel] ?? 0) > 0): ?>
                    <a class="wa-found__item" href="<?= e($base . $ziel) ?>">
                        <?= (int) $ohneZuordnung[$schluessel] ?> <?= e($text) ?>
                    </a>
                <?php endif; ?>
            <?php endforeach; ?>
        </div>
    </section>
<?php endif; ?>

<section class="wa-panel">
    <header class="wa-panel__head">
        <form method="get" class="wa-filter">
            <input class="wa-input wa-input--short" type="search" name="suche"
                   value="<?= e($suche) ?>" placeholder="Suchen …" aria-label="Kunden suchen">

            <select class="wa-select wa-input--short" name="status" aria-label="Anzeigen">
                <?php foreach (['aktiv' => 'Aktive', 'ruhend' => 'Ruhende',
                                'beendet' => 'Beendete', 'alle' => 'Alle'] as $wert => $text): ?>
                    <option value="<?= e($wert) ?>"<?= $zeigen === $wert ? ' selected' : '' ?>>
                        <?= e($text) ?>
                    </option>
                <?php endforeach; ?>
            </select>

            <button type="submit" class="wa-btn">Zeigen</button>
        </form>

        <a class="wa-btn wa-btn--primary" href="<?= e($base) ?>/kunden/neu">Neuer Kunde</a>
    </header>

    <?php if ($kunden === []): ?>
        <p class="wa-empty">
            Noch kein Kunde erfasst.
            <a href="<?= e($base) ?>/kunden/neu">Jetzt den ersten anlegen.</a>
        </p>
    <?php else: ?>
        <div class="wa-table-wrap">
            <table class="wa-table">
                <thead>
                    <tr>
                        <th>Kunde</th>
                        <th>Kontakt</th>
                        <th class="wa-table__num">Monatlich</th>
                        <th class="wa-table__num">Offen</th>
                        <th class="wa-table__num">Aufgaben</th>
                        <th></th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($kunden as $k): ?>
                        <tr>
                            <td>
                                <a class="wa-table__main" href="<?= e($base) ?>/kunden/<?= (int) $k['id'] ?>">
                                    <?= e((string) $k['name']) ?>
                                </a>
                                <?php if ((string) $k['status'] !== 'aktiv'): ?>
                                    <span class="wa-badge wa-badge--waiting"><?= e((string) $k['status']) ?></span>
                                <?php endif; ?>
                            </td>
                            <td class="wa-table__quiet">
                                <?= e((string) $k['contact_name']) ?>
                                <?php if ((string) $k['email'] !== ''): ?>
                                    <br><?= e((string) $k['email']) ?>
                                <?php endif; ?>
                            </td>
                            <td class="wa-table__num">
                                <?= $k['monatlich_rappen'] > 0
                                    ? e(Billing::money((int) $k['monatlich_rappen'])) : '–' ?>
                            </td>
                            <td class="wa-table__num">
                                <?php if ($k['offen_rappen'] > 0): ?>
                                    <strong class="wa-warn"><?= e(Billing::money((int) $k['offen_rappen'])) ?></strong>
                                <?php else: ?>
                                    <span class="wa-ok">bezahlt</span>
                                <?php endif; ?>
                            </td>
                            <td class="wa-table__num">
                                <?= $k['offene_todos'] > 0 ? (int) $k['offene_todos'] : '–' ?>
                            </td>
                            <td class="wa-table__actions">
                                <a class="wa-btn wa-btn--small" href="<?= e($base) ?>/kunden/<?= (int) $k['id'] ?>">
                                    Öffnen
                                </a>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    <?php endif; ?>
</section>
