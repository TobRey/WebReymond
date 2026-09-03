<?php

declare(strict_types=1);

namespace WebAtze\Core;

/**
 * Einzelne Werte in app/config.php ändern, ohne den Rest anzufassen.
 *
 * Die Datei ist von Hand lesbar und soll es bleiben: Kommentare,
 * Reihenfolge und Einrückung überleben. Deshalb wird gezielt eine Zeile
 * ersetzt statt die ganze Datei neu geschrieben – ein var_export() über
 * alles würde jede Erklärung darin verschlucken.
 *
 * Geändert wird nur, was hier ausdrücklich erlaubt ist. Ein Formular
 * soll nicht an beliebigen Einstellungen drehen können.
 */
final class ConfigFile
{
    /** Was sich über diesen Weg setzen lässt. */
    private const ERLAUBT = ['crypto_key', 'app_key'];

    public static function path(): string
    {
        return APP_DIR . '/config.php';
    }

    public static function isWritable(): bool
    {
        return is_file(self::path()) && is_writable(self::path());
    }

    /**
     * Einen Wert setzen.
     *
     * @return string leerer Text bei Erfolg, sonst der Grund
     */
    public static function set(string $schluessel, string $wert): string
    {
        if (!in_array($schluessel, self::ERLAUBT, true)) {
            return 'Dieser Eintrag lässt sich nicht auf diesem Weg ändern.';
        }

        // Nur harmlose Zeichen. Der Wert landet in einer PHP-Datei; was
        // dort ein Anführungszeichen setzen könnte, hat nichts verloren.
        if (preg_match('/^[A-Za-z0-9_-]{1,200}$/', $wert) !== 1) {
            return 'Dieser Wert enthält Zeichen, die dort nicht hingehören.';
        }

        $pfad = self::path();

        if (!is_file($pfad)) {
            return 'app/config.php wurde nicht gefunden.';
        }

        $inhalt = (string) file_get_contents($pfad);

        if ($inhalt === '') {
            return 'app/config.php liess sich nicht lesen.';
        }

        $zeile = "'" . $schluessel . "' => '" . $wert . "',";

        if (preg_match("/'" . preg_quote($schluessel, '/') . "'\s*=>\s*'[^']*',/", $inhalt) === 1) {
            $neu = preg_replace(
                "/'" . preg_quote($schluessel, '/') . "'\s*=>\s*'[^']*',/",
                $zeile,
                $inhalt,
                1
            );
        } else {
            // Fehlt der Eintrag ganz, kommt er direkt hinter das
            // öffnende "return [".
            $neu = preg_replace('/(return\s*\[)/', "$1\n    " . $zeile, $inhalt, 1);
        }

        if (!is_string($neu) || $neu === $inhalt) {
            return 'Der Eintrag liess sich nicht setzen. Bitte von Hand eintragen.';
        }

        // Erst daneben schreiben, prüfen, dann hinüberschieben.
        //
        // Zwei Gründe für diesen Umweg: Eine config.php mit Syntaxfehler
        // legt die ganze Website lahm, und rename() ist unteilbar - es
        // gibt keinen Moment, in dem eine halb geschriebene Datei
        // dasteht. Eine Sicherungskopie bleibt bewusst nicht liegen:
        // Sie enthielte die Zugangsdaten im Klartext unter einem
        // Namen, den niemand mehr im Blick hat.
        $neben = $pfad . '.neu';

        if (@file_put_contents($neben, $neu) === false) {
            return 'Keine Schreibrechte auf app/config.php.';
        }

        @chmod($neben, 0640);

        if (!self::syntaxOk($neben)) {
            @unlink($neben);

            return 'Die geänderte Datei wäre fehlerhaft gewesen und wurde verworfen.';
        }

        if (!@rename($neben, $pfad)) {
            @unlink($neben);

            return 'app/config.php liess sich nicht ersetzen. Fehlen die Schreibrechte?';
        }

        Config::set($schluessel, $wert);

        return '';
    }

    /**
     * Lässt sich die Datei fehlerfrei einlesen?
     *
     * Ohne den PHP-Aufruf – auf geteiltem Hosting ist er oft gesperrt –
     * bleibt der Weg über include, in einem eigenen Prozess gäbe es
     * nicht. Deshalb: einlesen und schauen, ob ein Array herauskommt.
     */
    private static function syntaxOk(string $datei): bool
    {
        try {
            $wert = @include $datei;
        } catch (\Throwable) {
            return false;
        }

        return is_array($wert) && isset($wert['app_key']);
    }
}
