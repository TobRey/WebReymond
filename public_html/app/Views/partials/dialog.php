<?php

/**
 * Ein Formularfenster über der Liste.
 *
 * Der Grund für diese Datei: Die Bearbeiten-Formulare steckten als
 * <details> im letzten <td> der Tabelle. Damit erbten sie die Breite
 * einer schmalen Spalte am rechten Rand und stapelten alle Felder
 * untereinander – man musste erst nach rechts scrollen, um sie zu
 * finden, und dann endlos nach unten, um sie auszufüllen.
 *
 * Hier liegt dasselbe Formular stattdessen als <dialog> hinter der
 * Tabelle, mittig und breit genug für zwei Spalten. <dialog> bringt
 * Fokusfalle, Escape und die Verdunkelung dahinter selbst mit – dafür
 * braucht es keine Bibliothek.
 *
 * @var string $id     Kennung, auf die der Knopf zeigt
 * @var string $titel  Überschrift des Fensters
 * @var string $inhalt fertiges HTML des Formulars
 */

$id = (string) ($id ?? '');
$titel = (string) ($titel ?? '');
$inhalt = (string) ($inhalt ?? '');

if ($id === '') {
    return;
}
?>
<dialog class="wa-dialog" id="<?= e($id) ?>" aria-labelledby="<?= e($id) ?>-titel">
    <div class="wa-dialog__kopf">
        <h2 class="wa-dialog__titel" id="<?= e($id) ?>-titel"><?= e($titel) ?></h2>
        <?php /* formmethod="dialog" schliesst ohne JavaScript – das ist der
                 einzige Weg hinaus, wenn ein Skript nicht lädt. */ ?>
        <form method="dialog" class="wa-dialog__schliessen-form">
            <button type="submit" class="wa-icon-btn"
                    aria-label="Fenster schliessen" title="Schliessen">
                <?= View_partial('partials/admin-icons', ['name' => 'close']) ?>
            </button>
        </form>
    </div>

    <div class="wa-dialog__inhalt"><?= $inhalt ?></div>
</dialog>
