<?php
/**
 * Die Abfrage des Zeitcodes.
 *
 * Bewusst genauso schmucklos wie die Anmeldung: Wer die Adresse zufällig
 * findet, soll nicht sehen, wozu sie gehört.
 */

use WebAtze\Core\Csrf;

/** @var string $error */
?>
<div class="wa-login">
    <form class="wa-login__card" method="post" action="" autocomplete="off">
        <?= Csrf::field() ?>

        <h1 class="wa-login__title">Bestätigen</h1>
        <p class="wa-login__sub">
            Der sechsstellige Code aus deiner App. Er wechselt alle 30 Sekunden.
        </p>

        <?php if ($error !== ''): ?>
            <p class="wa-login__error" role="alert"><?= e($error) ?></p>
        <?php endif; ?>

        <label class="wa-label" for="code">Code</label>
        <input class="wa-input wa-input--code" type="text" id="code" name="code"
               inputmode="numeric" autocomplete="one-time-code" required autofocus
               maxlength="9" spellcheck="false" placeholder="123456">

        <label class="wa-checkbox">
            <input type="checkbox" name="trust" value="1">
            <span>Diesem Gerät 30 Tage vertrauen</span>
        </label>

        <button type="submit" class="wa-btn wa-btn--primary wa-btn--block">Weiter</button>

        <p class="wa-login__hint">
            Telefon nicht zur Hand? Dann einen der Ersatzcodes eingeben –
            sie enthalten einen Bindestrich.
        </p>
    </form>
</div>
