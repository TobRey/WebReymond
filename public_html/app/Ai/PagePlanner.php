<?php

declare(strict_types=1);

namespace WebAtze\Ai;

use WebAtze\Core\{Config, Logger};
use WebAtze\Templates\{Catalog, Schema};

/**
 * Eine Anweisung für eine ganze Seite.
 *
 * Ein Abschnittsprompt darf einen Abschnitt ändern. Ein Seitenprompt darf
 * mehr: Abschnitte anlegen, löschen, umordnen – "häng unten noch einen
 * Kontaktbereich dran und schieb die Referenzen nach oben".
 *
 * Er tut das aber nicht, indem er die Seite neu schreibt. Er schreibt
 * einen **Plan**: eine Liste von Schritten, jeder davon eine Bewegung,
 * die es im Editor auch von Hand gibt. Ausgeführt werden sie danach
 * einzeln, durch dieselben geprüften Wege.
 *
 * Der Unterschied ist nicht theoretisch. Eine Antwort, die die ganze
 * Seite auf einmal zurückgibt, schreibt bei jedem Versuch auch die
 * Abschnitte neu, die niemand angefasst haben wollte – und dann ist ein
 * mühsam gefeilter Text weg, weil jemand die Reihenfolge ändern wollte.
 * Ein Plan kann das nicht: Was nicht in seiner Liste steht, wird nicht
 * angefasst.
 */
final class PagePlanner
{
    /** Wie viele Schritte ein Plan höchstens hat. */
    private const MAX_SCHRITTE = 12;

    /**
     * Einen Plan erstellen.
     *
     * @return array{ok:bool, error:string, summary:string, schritte:list<array>}
     */
    public static function plan(array $projekt, array $seite, array $abschnitte, string $anweisung): array
    {
        if (!ClaudeClient::isConfigured()) {
            return self::fehler(
                'Für Anweisungen wird ein Anthropic-Schlüssel gebraucht. '
                . 'Er gehört in app/config.php.'
            );
        }

        $anweisung = mb_substr(trim($anweisung), 0, 2000);

        if ($anweisung === '') {
            return self::fehler('Es wurde nicht gesagt, was geändert werden soll.');
        }

        try {
            $antwort = (new ClaudeClient((int) $projekt['id']))->structured(
                'page-plan',
                self::system(),
                self::nachricht($seite, $abschnitte, $anweisung),
                self::schema(),
                [
                    'model' => (string) Config::get('anthropic.model_plan', 'claude-opus-5'),
                    'effort' => 'medium',
                    'max_tokens' => 4000,
                ]
            );
        } catch (\Throwable $e) {
            $id = Logger::exception($e);

            return self::fehler('Die Schnittstelle hat nicht geantwortet. Kennnummer: ' . $id);
        }

        return [
            'ok' => true,
            'error' => '',
            'summary' => mb_substr(trim((string) ($antwort['summary'] ?? '')), 0, 400),
            'schritte' => self::schritte($antwort['schritte'] ?? [], $abschnitte),
        ];
    }

    /**
     * Aus der Antwort die Schritte machen, die ausgeführt werden dürfen.
     *
     * Geprüft wird hier und nicht beim Ausführen: Ein Schritt, der auf
     * einen fremden Abschnitt zeigt, soll gar nicht erst entstehen.
     *
     * @return list<array<string, mixed>>
     */
    private static function schritte(mixed $roh, array $abschnitte): array
    {
        if (!is_array($roh)) {
            return [];
        }

        $eigene = array_map(static fn (array $a): int => (int) $a['id'], $abschnitte);
        $sauber = [];

        foreach ($roh as $schritt) {
            if (count($sauber) >= self::MAX_SCHRITTE || !is_array($schritt)) {
                continue;
            }

            $was = (string) ($schritt['was'] ?? '');

            if (!in_array($was, ['einsetzen', 'entfernen', 'ziehen', 'anpassen'], true)) {
                continue;
            }

            if ($was === 'einsetzen') {
                $typ = (string) ($schritt['typ'] ?? '');

                // Kopf und Fuss gibt es je Seite einmal, und sie stehen
                // nicht zur Disposition einer Anweisung.
                if (!Schema::exists($typ) || in_array($typ, ['header', 'footer'], true)) {
                    continue;
                }

                $sauber[] = [
                    'was' => 'einsetzen',
                    'typ' => $typ,
                    'position' => max(0, (int) ($schritt['position'] ?? 0)),
                ];

                continue;
            }

            $id = (int) ($schritt['abschnitt'] ?? 0);

            // Ein Schritt darf nur auf einen Abschnitt dieser Seite
            // zeigen. Ohne diese Zeile könnte eine Anweisung für Seite A
            // einen Abschnitt von Seite B löschen.
            if (!in_array($id, $eigene, true)) {
                continue;
            }

            $sauber[] = match ($was) {
                'entfernen' => ['was' => 'entfernen', 'abschnitt' => $id],
                'ziehen' => [
                    'was' => 'ziehen',
                    'abschnitt' => $id,
                    'position' => max(0, (int) ($schritt['position'] ?? 0)),
                ],
                default => [
                    'was' => 'anpassen',
                    'abschnitt' => $id,
                    'anweisung' => mb_substr(trim((string) ($schritt['anweisung'] ?? '')), 0, 600),
                ],
            };
        }

        return $sauber;
    }

    private static function system(): string
    {
        return Prompts::base() . "\n\n"
            . "DEINE AUFGABE\n"
            . "=============\n"
            . "Du planst die Änderung einer ganzen Seite. Du änderst nichts selbst –\n"
            . "du schreibst eine Liste von Schritten, die danach einzeln ausgeführt\n"
            . "werden.\n\n"
            . "Vier Schritte gibt es:\n"
            . "  einsetzen  – einen neuen Abschnitt eines Typs an eine Position\n"
            . "  entfernen  – einen bestehenden Abschnitt löschen\n"
            . "  ziehen     – einen bestehenden Abschnitt an eine andere Position\n"
            . "  anpassen   – einen bestehenden Abschnitt ändern, mit eigener Anweisung\n\n"
            . "REGELN\n"
            . "======\n"
            . "* So wenige Schritte wie möglich. Was nicht verlangt wurde, bleibt.\n"
            . "* Kopf und Fuss werden weder eingesetzt noch entfernt noch gezogen.\n"
            . "* Positionen zählen ab 0 und meinen den Platz in der fertigen Reihenfolge.\n"
            . "* Bei 'anpassen' schreibst du eine eigene, konkrete Anweisung für genau\n"
            . "  diesen Abschnitt – nicht die Anweisung des Nutzers noch einmal.\n"
            . "* Wenn die Anweisung nur einen Abschnitt betrifft, hat dein Plan einen\n"
            . "  Schritt. Ein Plan mit acht Schritten für 'mach die Überschrift kürzer'\n"
            . "  ist falsch.";
    }

    private static function nachricht(array $seite, array $abschnitte, string $anweisung): string
    {
        $liste = [];

        foreach ($abschnitte as $platz => $a) {
            $titel = (string) ($a['content']['title'] ?? '');

            $liste[] = sprintf(
                '  [%d] id=%d  %s (%s)%s%s',
                $platz,
                (int) $a['id'],
                Schema::forType((string) $a['type'])['label'] ?? $a['type'],
                (string) $a['type'],
                $titel !== '' ? ' – "' . $titel . '"' : '',
                !empty($a['hidden']) ? ' [ausgeblendet]' : ''
            );
        }

        $typen = [];

        foreach (Schema::all() as $typ => $b) {
            if (in_array($typ, ['header', 'footer'], true)) {
                continue;
            }

            $typen[] = '  ' . $typ . ' – ' . $b['label'] . ': ' . $b['description'];
        }

        return "SEITE\n=====\n" . (string) $seite['title'] . "\n\n"
            . "ABSCHNITTE IN IHRER REIHENFOLGE\n===============================\n"
            . implode("\n", $liste) . "\n\n"
            . "ABSCHNITTSTYPEN, DIE ES GIBT\n============================\n"
            . implode("\n", $typen) . "\n\n"
            . "ANWEISUNG\n=========\n" . $anweisung;
    }

    private static function schema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'summary' => [
                    'type' => 'string',
                    'description' => 'Ein Satz, was der Plan bewirkt.',
                ],
                'schritte' => [
                    'type' => 'array',
                    'items' => [
                        'type' => 'object',
                        'properties' => [
                            'was' => [
                                'type' => 'string',
                                'enum' => ['einsetzen', 'entfernen', 'ziehen', 'anpassen'],
                            ],
                            'abschnitt' => [
                                'type' => 'integer',
                                'description' => 'Die id aus der Liste. Bei "einsetzen" 0.',
                            ],
                            'typ' => [
                                'type' => 'string',
                                'description' => 'Nur bei "einsetzen": der Abschnittstyp.',
                            ],
                            'position' => [
                                'type' => 'integer',
                                'description' => 'Nur bei "einsetzen" und "ziehen".',
                            ],
                            'anweisung' => [
                                'type' => 'string',
                                'description' => 'Nur bei "anpassen": was an diesem Abschnitt anders wird.',
                            ],
                        ],
                        'required' => ['was', 'abschnitt', 'typ', 'position', 'anweisung'],
                    ],
                ],
            ],
            'required' => ['summary', 'schritte'],
        ];
    }

    /** @return array{ok:bool, error:string, summary:string, schritte:list<array>} */
    private static function fehler(string $text): array
    {
        return ['ok' => false, 'error' => $text, 'summary' => '', 'schritte' => []];
    }
}
