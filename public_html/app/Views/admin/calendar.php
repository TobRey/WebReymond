<?php

/**
 * Der Monatskalender.
 *
 * Ein Raster aus Wochen, jede Woche sieben Tage. Termine und fällige
 * Aufgaben stehen im Feld – wer den Monat ansieht, soll wissen, was
 * ansteht, ohne irgendwo hineinzuklicken.
 */

use WebAtze\Core\{Config, Csrf};
use WebAtze\Domain\Calendar;

/** @var string $monat */
/** @var array<int, array<int, array<string, mixed>>> $wochen */
/** @var array<int, array<string, mixed>> $kunden */
/** @var array<int, array<string, mixed>> $mitarbeitende */
/** @var array<int, array<string, mixed>> $naechste */

$base = '/' . trim((string) Config::get('create_path', 'create'), '/');
?>

<section class="wa-panel">
    <header class="wa-panel__head">
        <div class="wa-monthnav">
            <a class="wa-btn wa-btn--small"
               href="<?= e($base) ?>/kalender?monat=<?= e(Calendar::previous($monat)) ?>"
               aria-label="Monat davor">←</a>

            <h2 class="wa-panel__title"><?= e(Calendar::title($monat)) ?></h2>

            <a class="wa-btn wa-btn--small"
               href="<?= e($base) ?>/kalender?monat=<?= e(Calendar::next($monat)) ?>"
               aria-label="Monat danach">→</a>
        </div>

        <a class="wa-btn" href="<?= e($base) ?>/kalender?monat=<?= e(date('Y-m')) ?>">Heute</a>
    </header>

    <div class="wa-cal">
        <?php foreach (Calendar::DAYS as $tag): ?>
            <div class="wa-cal__head"><?= e($tag) ?></div>
        <?php endforeach; ?>

        <?php foreach ($wochen as $woche): ?>
            <?php foreach ($woche as $tag): ?>
                <div class="wa-cal__day<?= $tag['fremd'] ? ' is-other' : '' ?><?= $tag['heute'] ? ' is-today' : '' ?><?= $tag['wochenende'] ? ' is-weekend' : '' ?>">
                    <span class="wa-cal__num"><?= (int) $tag['nummer'] ?></span>

                    <?php foreach ($tag['termine'] as $t): ?>
                        <span class="wa-cal__item" title="<?= e((string) $t['title']) ?>">
                            <span class="wa-cal__time"><?= e(substr((string) $t['starts_at'], 11, 5)) ?></span>
                            <?= e(mb_substr((string) $t['title'], 0, 22)) ?>
                            <?php if ((string) ($t['kunde'] ?? '') !== ''): ?>
                                <span class="wa-cal__who"><?= e((string) $t['kunde']) ?></span>
                            <?php endif; ?>
                        </span>
                    <?php endforeach; ?>

                    <?php foreach ($tag['aufgaben'] as $a): ?>
                        <span class="wa-cal__item wa-cal__item--todo"
                              title="<?= e((string) $a['title']) ?>">
                            <?= e(mb_substr((string) $a['title'], 0, 24)) ?>
                        </span>
                    <?php endforeach; ?>
                </div>
            <?php endforeach; ?>
        <?php endforeach; ?>
    </div>
</section>

<section class="wa-panel">
    <header class="wa-panel__head">
        <h2 class="wa-panel__title">Termin eintragen</h2>
    </header>

    <form method="post" action="<?= e($base) ?>/termine" class="wa-form">
        <?= Csrf::field() ?>

        <div class="wa-form__grid">
            <div class="wa-field wa-field--wide">
                <label class="wa-label" for="k-title">Worum geht es?</label>
                <input class="wa-input" type="text" id="k-title" name="title" required>
            </div>

            <div class="wa-field">
                <label class="wa-label" for="k-start">Beginn</label>
                <input class="wa-input" type="datetime-local" id="k-start" name="starts_at" required>
            </div>

            <div class="wa-field">
                <label class="wa-label" for="k-end">Ende</label>
                <input class="wa-input" type="datetime-local" id="k-end" name="ends_at">
            </div>

            <div class="wa-field">
                <label class="wa-label" for="k-kunde">Kunde</label>
                <select class="wa-select" id="k-kunde" name="customer_id">
                    <option value="0">–</option>
                    <?php foreach ($kunden as $k): ?>
                        <option value="<?= (int) $k['id'] ?>"><?= e((string) $k['name']) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>

            <div class="wa-field">
                <label class="wa-label" for="k-person">Wer</label>
                <select class="wa-select" id="k-person" name="employee_id">
                    <option value="0">–</option>
                    <?php foreach ($mitarbeitende as $m): ?>
                        <option value="<?= (int) $m['id'] ?>"><?= e((string) $m['name']) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>

            <div class="wa-field">
                <label class="wa-label" for="k-place">Wo</label>
                <input class="wa-input" type="text" id="k-place" name="place">
            </div>

            <div class="wa-field wa-field--wide">
                <label class="wa-label" for="k-note">Notiz</label>
                <textarea class="wa-textarea" id="k-note" name="note" rows="2"></textarea>
            </div>
        </div>

        <div class="wa-form__actions">
            <button type="submit" class="wa-btn wa-btn--primary">Eintragen</button>
        </div>
    </form>
</section>

<section class="wa-panel">
    <header class="wa-panel__head">
        <h2 class="wa-panel__title">Als Nächstes</h2>
    </header>

    <?php if ($naechste === []): ?>
        <p class="wa-empty">Nichts geplant.</p>
    <?php else: ?>
        <ul class="wa-list">
            <?php foreach ($naechste as $t): ?>
                <li class="wa-list__row">
                    <div>
                        <strong><?= e((string) $t['title']) ?></strong><br>
                        <span class="wa-table__quiet">
                            <?= e((string) $t['starts_at']) ?>
                            <?php if ((string) ($t['kunde'] ?? '') !== ''): ?>
                                · <?= e((string) $t['kunde']) ?>
                            <?php endif; ?>
                            <?php if ((string) ($t['mitarbeiter'] ?? '') !== ''): ?>
                                · <?= e((string) $t['mitarbeiter']) ?>
                            <?php endif; ?>
                            <?php if ((string) $t['place'] !== ''): ?>
                                · <?= e((string) $t['place']) ?>
                            <?php endif; ?>
                        </span>
                    </div>

                    <form method="post" action="<?= e($base) ?>/termine/loeschen"
                          data-confirm="Diesen Termin absagen?">
                        <?= Csrf::field() ?>
                        <input type="hidden" name="appointment_id" value="<?= (int) $t['id'] ?>">
                        <button type="submit" class="wa-btn wa-btn--small">Absagen</button>
                    </form>
                </li>
            <?php endforeach; ?>
        </ul>
    <?php endif; ?>
</section>
