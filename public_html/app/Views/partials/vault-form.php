<?php

/**
 * Das Formular eines Tresoreintrags.
 *
 * Es lag vorher als <details> im letzten <td> der Tabelle: in einer
 * schmalen Spalte am rechten Rand, hinter der man erst herscrollen
 * musste, und danach stapelten sich alle Felder untereinander. Als
 * eigene Datei kann es jetzt im Fenster stehen, wo Platz für zwei
 * Spalten ist - und es steht trotzdem nur einmal im Quelltext.
 *
 * @var array<string, mixed> $s      der Eintrag
 * @var array<string, string> $arten
 * @var array<int, array<string, mixed>> $kunden
 * @var string $base
 */

use WebAtze\Core\Csrf;
?>
<form method="post" action="<?= e($base) ?>/passwoerter/speichern"
      class="wa-form">
    <?= Csrf::field() ?>
    <input type="hidden" name="secret_id" value="<?= (int) $s['id'] ?>">

    <div class="wa-field">
        <label class="wa-label" for="l-<?= (int) $s['id'] ?>">Wofür</label>
        <input class="wa-input" type="text" name="label"
               id="l-<?= (int) $s['id'] ?>"
               value="<?= e((string) $s['label']) ?>" required>
    </div>

    <div class="wa-field">
        <label class="wa-label" for="u-<?= (int) $s['id'] ?>">Benutzername</label>
        <input class="wa-input" type="text" name="username"
               id="u-<?= (int) $s['id'] ?>" autocomplete="off"
               value="<?= e((string) $s['username']) ?>">
    </div>

    <div class="wa-field">
        <label class="wa-label" for="s-<?= (int) $s['id'] ?>">
            Neues Passwort
        </label>
        <input class="wa-input" type="text" name="secret"
               id="s-<?= (int) $s['id'] ?>" autocomplete="off"
               placeholder="leer lassen, dann bleibt das bisherige">
        <button type="button" class="wa-btn wa-btn--small"
                data-generate-password="#s-<?= (int) $s['id'] ?>">
            Neues erzeugen
        </button>
    </div>

    <div class="wa-field">
        <label class="wa-label" for="k-<?= (int) $s['id'] ?>">Art</label>
        <select class="wa-select" name="kind" id="k-<?= (int) $s['id'] ?>">
            <?php foreach ($arten as $wert => $text): ?>
                <option value="<?= e($wert) ?>"
                    <?= (string) $s['kind'] === $wert ? ' selected' : '' ?>>
                    <?= e($text) ?>
                </option>
            <?php endforeach; ?>
        </select>
    </div>

    <div class="wa-field">
        <label class="wa-label" for="c-<?= (int) $s['id'] ?>">Kunde</label>
        <select class="wa-select" name="customer_id"
                id="c-<?= (int) $s['id'] ?>">
            <option value="0">– keiner –</option>
            <?php foreach ($kunden as $k): ?>
                <option value="<?= (int) $k['id'] ?>"
                    <?= (int) ($s['customer_id'] ?? 0) === (int) $k['id']
                        ? ' selected' : '' ?>>
                    <?= e((string) $k['name']) ?>
                </option>
            <?php endforeach; ?>
        </select>
    </div>

    <div class="wa-field wa-field--wide">
        <label class="wa-label" for="w-<?= (int) $s['id'] ?>">Adresse</label>
        <input class="wa-input" type="text" name="url"
               id="w-<?= (int) $s['id'] ?>"
               value="<?= e((string) $s['url']) ?>">
    </div>

    <div class="wa-field wa-field--wide">
        <label class="wa-label" for="n-<?= (int) $s['id'] ?>">Notiz</label>
        <textarea class="wa-input wa-textarea" rows="2" name="note"
                  id="n-<?= (int) $s['id'] ?>"><?= e((string) $s['note']) ?></textarea>
    </div>

    <div class="wa-form__actions">
        <button type="submit" class="wa-btn wa-btn--primary">Speichern</button>
    </div>
</form>
