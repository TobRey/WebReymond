<?php

declare(strict_types=1);

namespace WebAtze\Core;

use RuntimeException;

/**
 * Ein Fehler, der sich durch Wiederholen nicht behebt.
 *
 * Der Unterschied ist wichtiger, als er aussieht. Eine Netzstörung
 * lohnt einen zweiten Versuch; ein fehlender Schlüssel nicht. Wird
 * beides gleich behandelt, versucht der Worker eine falsche
 * Einstellung alle dreissig Sekunden erneut – und zwar bis jemand
 * hinsieht. Genau das ist einmal passiert.
 *
 * Was hiermit geworfen wird, hält den Auftrag an und zeigt eine
 * Anweisung, was zu tun ist. Kein Wiederholen, kein Rauschen im
 * Protokoll.
 */
final class ConfigurationError extends RuntimeException
{
    /** Was der Betreiber tun muss, in einem Satz. */
    private string $remedy;

    /** Feld in den Einstellungen, das fehlt – für die Oberfläche. */
    private string $field;

    public function __construct(string $message, string $remedy = '', string $field = '')
    {
        parent::__construct($message);

        $this->remedy = $remedy;
        $this->field = $field;
    }

    public function remedy(): string
    {
        return $this->remedy;
    }

    public function field(): string
    {
        return $this->field;
    }

    /** Meldung und Anweisung zusammen – so steht es beim Auftrag. */
    public function full(): string
    {
        return $this->remedy === ''
            ? $this->getMessage()
            : $this->getMessage() . ' ' . $this->remedy;
    }
}
