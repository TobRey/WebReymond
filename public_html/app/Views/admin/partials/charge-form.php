<?php

/**
 * Ein Kostenposten – zum Anlegen und zum Ändern dieselbe Form.
 */

use WebAtze\Core\Csrf;
use WebAtze\Domain\Billing;

/** @var string $base */
/** @var int $id */
/** @var array<string, mixed>|null $posten */

$p = $posten ?? [];
$neu = ($p['id'] ?? 0) === 0;
$vorwahl = 'p' . (int) ($p['id'] ?? 0) . '-';
?>

<form method="post" action="<?= e($base) ?>/kunden/<?= $id ?>/posten" class="wa-form wa-form--inline">
    <?= Csrf::field() ?>
    <input type="hidden" name="charge_id" value="<?= (int) ($p['id'] ?? 0) ?>">

    <div class="wa-field">
        <label class="wa-label" for="<?= $vorwahl ?>label">Bezeichnung</label>
        <input class="wa-input" type="text" id="<?= $vorwahl ?>label" name="label" required
               value="<?= e((string) ($p['label'] ?? '')) ?>"
               placeholder="z.B. Wartung Plus">
    </div>

    <div class="wa-field">
        <label class="wa-label" for="<?= $vorwahl ?>kind">Wofür</label>
        <select class="wa-select" id="<?= $vorwahl ?>kind" name="kind">
            <?php foreach (Billing::KINDS as $wert => $text): ?>
                <option value="<?= e($wert) ?>"<?= (string) ($p['kind'] ?? 'weiteres') === $wert
                    ? ' selected' : '' ?>><?= e($text) ?></option>
            <?php endforeach; ?>
        </select>
    </div>

    <div class="wa-field">
        <label class="wa-label" for="<?= $vorwahl ?>amount">Betrag CHF</label>
        <input class="wa-input" type="text" inputmode="decimal" id="<?= $vorwahl ?>amount" name="amount"
               value="<?= $neu ? '' : e(Billing::money((int) ($p['amount_rappen'] ?? 0))) ?>"
               placeholder="49.00">
    </div>

    <div class="wa-field">
        <label class="wa-label" for="<?= $vorwahl ?>interval">Wie oft</label>
        <select class="wa-select" id="<?= $vorwahl ?>interval" name="interval">
            <?php foreach (Billing::INTERVALS as $wert => $text): ?>
                <option value="<?= e($wert) ?>"<?= (string) ($p['interval'] ?? 'einmalig') === $wert
                    ? ' selected' : '' ?>><?= e($text) ?></option>
            <?php endforeach; ?>
        </select>
    </div>

    <div class="wa-field">
        <label class="wa-label" for="<?= $vorwahl ?>starts">Ab</label>
        <input class="wa-input" type="date" id="<?= $vorwahl ?>starts" name="starts_on"
               value="<?= e((string) ($p['starts_on'] ?? '')) ?>">
    </div>

    <div class="wa-field">
        <label class="wa-label" for="<?= $vorwahl ?>ends">Bis</label>
        <input class="wa-input" type="date" id="<?= $vorwahl ?>ends" name="ends_on"
               value="<?= e((string) ($p['ends_on'] ?? '')) ?>">
        <span class="wa-label__hint">Leer heisst: läuft weiter.</span>
    </div>

    <div class="wa-field wa-field--wide">
        <label class="wa-check-line">
            <input type="checkbox" name="active" value="1"
                   <?= $neu || !empty($p['active']) ? 'checked' : '' ?>>
            <span>Läuft</span>
        </label>
    </div>

    <div class="wa-form__actions">
        <button type="submit" class="wa-btn wa-btn--primary">
            <?= $neu ? 'Posten anlegen' : 'Änderung speichern' ?>
        </button>
    </div>
</form>

<?php if (!$neu): ?>
    <form method="post" action="<?= e($base) ?>/kunden/<?= $id ?>/posten/loeschen"
          data-confirm="Diesen Posten löschen? Bereits erfasste Zahlungen bleiben.">
        <?= Csrf::field() ?>
        <input type="hidden" name="charge_id" value="<?= (int) $p['id'] ?>">
        <button type="submit" class="wa-btn wa-btn--small">Posten löschen</button>
    </form>
<?php endif; ?>
