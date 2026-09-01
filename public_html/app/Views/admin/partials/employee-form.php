<?php

use WebAtze\Core\Csrf;
use WebAtze\Domain\Billing;

/** @var string $base */
/** @var array<string, mixed>|null $person */

$p = $person ?? [];
$neu = ($p['id'] ?? 0) === 0;
$vorwahl = 'm' . (int) ($p['id'] ?? 0) . '-';
?>

<form method="post" action="<?= e($base) ?>/mitarbeitende" class="wa-form wa-form--inline">
    <?= Csrf::field() ?>
    <input type="hidden" name="employee_id" value="<?= (int) ($p['id'] ?? 0) ?>">

    <div class="wa-field">
        <label class="wa-label" for="<?= $vorwahl ?>name">Name</label>
        <input class="wa-input" type="text" id="<?= $vorwahl ?>name" name="name" required
               value="<?= e((string) ($p['name'] ?? '')) ?>">
    </div>

    <div class="wa-field">
        <label class="wa-label" for="<?= $vorwahl ?>role">Rolle</label>
        <input class="wa-input" type="text" id="<?= $vorwahl ?>role" name="role"
               value="<?= e((string) ($p['role'] ?? '')) ?>" placeholder="z.B. Gestaltung">
    </div>

    <div class="wa-field">
        <label class="wa-label" for="<?= $vorwahl ?>email">E-Mail</label>
        <input class="wa-input" type="email" id="<?= $vorwahl ?>email" name="email"
               value="<?= e((string) ($p['email'] ?? '')) ?>">
    </div>

    <div class="wa-field">
        <label class="wa-label" for="<?= $vorwahl ?>phone">Telefon</label>
        <input class="wa-input" type="text" id="<?= $vorwahl ?>phone" name="phone"
               value="<?= e((string) ($p['phone'] ?? '')) ?>">
    </div>

    <div class="wa-field">
        <label class="wa-label" for="<?= $vorwahl ?>hourly">Stundensatz CHF</label>
        <input class="wa-input" type="text" inputmode="decimal" id="<?= $vorwahl ?>hourly" name="hourly"
               value="<?= $neu ? '' : e(Billing::money((int) ($p['hourly_rappen'] ?? 0))) ?>">
    </div>

    <div class="wa-field wa-field--wide">
        <label class="wa-check-line">
            <input type="checkbox" name="active" value="1"
                   <?= $neu || !empty($p['active']) ? 'checked' : '' ?>>
            <span>Arbeitet mit</span>
        </label>
    </div>

    <div class="wa-form__actions">
        <button type="submit" class="wa-btn wa-btn--primary">
            <?= $neu ? 'Hinzufügen' : 'Speichern' ?>
        </button>
    </div>
</form>

<?php if (!$neu): ?>
    <form method="post" action="<?= e($base) ?>/mitarbeitende/loeschen"
          data-confirm="Diese Person entfernen? Ihre Kunden bleiben, sind dann aber ohne Betreuung.">
        <?= Csrf::field() ?>
        <input type="hidden" name="employee_id" value="<?= (int) $p['id'] ?>">
        <button type="submit" class="wa-btn wa-btn--small">Entfernen</button>
    </form>
<?php endif; ?>
