<?php

declare(strict_types=1);

namespace WebAtze\Domain;

use WebAtze\Core\{Db, Logger};

/**
 * Die Zeichen an den Menüpunkten.
 *
 * Der Adminbereich hat zwanzig Einträge. Ohne ein Zeichen daran heisst
 * «schau nach, ob etwas da ist» in jeden einzelnen hineinklicken – und
 * genau das tut niemand jeden Tag. Eine Supportfrage lag deshalb schon
 * einmal drei Tage, weil sie niemand gesehen hat.
 *
 * Also: eine Zahl je Menüpunkt, an einer Stelle gesammelt, bei jedem
 * Seitenaufbau einmal. Es sind ausschliesslich COUNT-Abfragen über
 * indizierte Spalten; zusammen kosten sie weniger als das Rendern der
 * Seite.
 *
 * Was ein Zeichen bekommt, ist bewusst eng gefasst: nur, was auf mich
 * wartet. Eine Zahl, die nichts von mir verlangt, wird nach drei Tagen
 * übersehen – und dann übersehe ich auch die, die etwas verlangt.
 */
final class Notices
{
    /**
     * Alle Zahlen, nach Menüpfad.
     *
     * @return array<string, array{anzahl:int, was:string, dringend:bool}>
     */
    public static function all(): array
    {
        try {
            return array_filter([
                '/anfragen' => self::zeichen(
                    self::zahl("SELECT COUNT(*) FROM leads WHERE status = 'new'"),
                    'neue Anfrage', 'neue Anfragen'
                ),
                '/support' => self::zeichen(
                    self::zahl('SELECT COUNT(*) FROM support_threads WHERE unread_for_owner = 1'),
                    'unbeantwortete Frage', 'unbeantwortete Fragen',
                    true
                ),
                '/wartung' => self::zeichen(
                    self::zahl('SELECT COUNT(*) FROM monitors WHERE active = 1 AND last_ok = 0'),
                    'Website ist ausgefallen', 'Websites sind ausgefallen',
                    true
                ),
                '/rechnungen' => self::zeichen(
                    self::zahl(
                        "SELECT COUNT(*) FROM documents
                         WHERE kind = 'invoice' AND paid_on = :leer
                           AND due_on <> :leer2 AND due_on < :heute",
                        ['leer' => '', 'leer2' => '', 'heute' => date('Y-m-d')]
                    ),
                    'Rechnung ist überfällig', 'Rechnungen sind überfällig',
                    true
                ),
                '/fragebogen' => self::zeichen(
                    self::zahl("SELECT COUNT(*) FROM questionnaires WHERE status = 'submitted'"),
                    'ausgefüllter Fragebogen', 'ausgefüllte Fragebögen'
                ),
                '/potenzielle-kunden' => self::zeichen(
                    self::zahl("SELECT COUNT(*) FROM prospects WHERE status = 'neu'"),
                    'Vorschlag wartet', 'Vorschläge warten'
                ),
                '/kalender' => self::zeichen(
                    self::zahl(
                        'SELECT COUNT(*) FROM appointments WHERE starts_at >= :von AND starts_at <= :bis',
                        ['von' => date('Y-m-d') . 'T00:00', 'bis' => date('Y-m-d') . 'T23:59']
                    ),
                    'Termin heute', 'Termine heute'
                ),
            ], static fn (?array $z): bool => $z !== null);
        } catch (\Throwable $e) {
            // Ein Zeichen im Menü darf niemals den ganzen Bereich
            // kosten. Fehlt es, fehlt eben ein Hinweis.
            Logger::exception($e);

            return [];
        }
    }

    /**
     * Ein einzelnes Zeichen – oder keines.
     *
     * @return array{anzahl:int, was:string, dringend:bool}|null
     */
    private static function zeichen(int $anzahl, string $eines, string $mehrere, bool $dringend = false): ?array
    {
        if ($anzahl <= 0) {
            return null;
        }

        return [
            'anzahl' => $anzahl,
            'was' => $anzahl === 1 ? $eines : $mehrere,
            'dringend' => $dringend,
        ];
    }

    /**
     * Eine Zahl holen – und eine fehlende Tabelle als Null werten.
     *
     * Auf einem Server, der gerade erst aktualisiert wurde, kann eine
     * Tabelle noch fehlen. Dann soll das Menü trotzdem stehen.
     *
     * @param array<string, mixed> $werte
     */
    private static function zahl(string $sql, array $werte = []): int
    {
        try {
            return (int) Db::value($sql, $werte, 0);
        } catch (\Throwable) {
            return 0;
        }
    }
}
