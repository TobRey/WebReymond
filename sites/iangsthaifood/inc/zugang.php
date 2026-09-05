<?php
/**
 * Zugang zu den geschützten Seiten (Support und Besucherzahlen).
 *
 * Verglichen wird der Zugangscode aus data/config.php mit hash_equals.
 * Gemerkt wird er nie: im Cookie steht nur ein Ticket aus Ablaufzeitpunkt
 * und Signatur.
 */

declare(strict_types=1);

require_once __DIR__ . '/bootstrap.php';

const TICKET_COOKIE = 'iang_support';
const TICKET_TAGE   = 180;

function ticket_erzeugen(): string
{
    $bis = time() + TICKET_TAGE * 86400;
    return $bis . '.' . hash_hmac('sha256', 'support|' . $bis, cfg('support_token'));
}

function ticket_gueltig(?string $wert): bool
{
    if (!is_string($wert) || $wert === '') {
        return false;
    }
    $teile = explode('.', $wert, 2);
    if (count($teile) !== 2 || !ctype_digit($teile[0])) {
        return false;
    }
    if ((int) $teile[0] < time()) {
        return false;
    }
    return hash_equals(hash_hmac('sha256', 'support|' . $teile[0], cfg('support_token')), $teile[1]);
}

function angemeldet(): bool
{
    return ticket_gueltig($_COOKIE[TICKET_COOKIE] ?? null);
}

/**
 * Nimmt die Eingabe des Zugangscodes entgegen.
 * Gibt eine Meldung zurück oder leitet bei Erfolg weiter.
 */
function code_pruefen(string $zielseite): ?array
{
    if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST' || ($_POST['was'] ?? '') !== 'code') {
        return null;
    }

    if (!versuche_erlaubt('code', absender_kennung(), 5, 3600)) {
        return ['err', 'Es gab in der letzten Stunde zu viele Versuche. Bitte probier es später noch einmal.'];
    }

    $eingabe = saubere_eingabe($_POST['code'] ?? '', 64);
    if (hash_equals(cfg('support_code'), $eingabe)) {
        cookie_setzen(TICKET_COOKIE, ticket_erzeugen(), TICKET_TAGE);
        header('Location: ' . $zielseite . '?willkommen=1');
        exit;
    }

    return ['err', 'Das hat nicht geklappt. Prüf den Code und versuch es noch einmal.'];
}

/** Das Formular für den Zugangscode. */
function code_formular(string $zielseite): string
{
    return <<<HTML
      <form class="form" method="post" action="{$zielseite}">
        <input type="hidden" name="was" value="code">
        <div class="field">
          <label for="code">Zugangscode</label>
          <input type="text" id="code" name="code" autocomplete="off" spellcheck="false" required maxlength="64">
          <span class="hint">Gross- und Kleinschreibung beachten.</span>
        </div>
        <div class="form__actions">
          <button class="btn btn--primary" type="submit">Weiter</button>
        </div>
      </form>
HTML;
}
