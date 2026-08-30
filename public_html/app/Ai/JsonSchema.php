<?php

declare(strict_types=1);

namespace WebAtze\Ai;

use WebAtze\Templates\{Catalog, Icons, Schema};

/**
 * Übersetzt die Inhaltsbeschreibung der Abschnitte in ein JSON-Schema
 * für die Claude-API.
 *
 * Der Nutzen: Die Antwort kann gar nicht mehr die falsche Form haben.
 * Kein Suchen nach JSON in Fliesstext, kein Raten bei fehlenden Feldern –
 * was zurückkommt, passt zum Aufbau oder die Anfrage schlägt fehl.
 */
final class JsonSchema
{
    /** Schema für die Struktur der ganzen Website. */
    public static function sitePlan(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'site_title' => [
                    'type' => 'string',
                    'description' => 'Titel der Startseite, 3 bis 8 Wörter.',
                ],
                'meta_description' => [
                    'type' => 'string',
                    'description' => 'Beschreibung für Suchmaschinen, 120 bis 160 Zeichen.',
                ],
                'tone_note' => [
                    'type' => 'string',
                    'description' => 'Ein Satz: Wie soll die Seite klingen?',
                ],
                'pages' => [
                    'type' => 'array',
                    'minItems' => 1,
                    'maxItems' => 12,
                    'items' => [
                        'type' => 'object',
                        'properties' => [
                            'path' => [
                                'type' => 'string',
                                'description' => 'Adresse, beginnt mit Schrägstrich. Startseite ist "/".',
                            ],
                            'title' => ['type' => 'string', 'description' => 'Seitentitel, 1 bis 3 Wörter.'],
                            'meta_description' => ['type' => 'string'],
                            'in_navigation' => ['type' => 'boolean'],
                            'sections' => [
                                'type' => 'array',
                                'minItems' => 3,
                                'maxItems' => 12,
                                'items' => [
                                    'type' => 'object',
                                    'properties' => [
                                        'type' => [
                                            'type' => 'string',
                                            'enum' => Schema::types(),
                                        ],
                                        'template' => [
                                            'type' => 'string',
                                            'description' => 'Kennung einer Vorlage dieses Typs.',
                                        ],
                                        'purpose' => [
                                            'type' => 'string',
                                            'description' => 'Ein Satz: Was soll dieser Abschnitt bewirken?',
                                        ],
                                    ],
                                    'required' => ['type', 'template', 'purpose'],
                                ],
                            ],
                        ],
                        'required' => ['path', 'title', 'meta_description', 'in_navigation', 'sections'],
                    ],
                ],
            ],
            'required' => ['site_title', 'meta_description', 'tone_note', 'pages'],
        ];
    }

    /**
     * Schema für die Inhalte einer Seite.
     *
     * Enthält für jeden Abschnitt genau die Felder seines Typs – nicht
     * mehr und nicht weniger.
     */
    public static function pageContent(array $sections): array
    {
        $items = [];

        foreach ($sections as $index => $section) {
            $type = (string) ($section['type'] ?? '');
            $definition = Schema::forType($type);

            if ($definition === null) {
                continue;
            }

            $items[] = [
                'type' => 'object',
                'properties' => [
                    'index' => ['type' => 'integer', 'description' => 'Nummer des Abschnitts, beginnend bei 0.'],
                    'section_type' => ['type' => 'string', 'enum' => [$type]],
                    'content' => self::fields($definition['fields']),
                ],
                'required' => ['index', 'section_type', 'content'],
            ];
        }

        // Die Abschnitte einer Seite haben verschiedene Formen. Statt einer
        // Auswahl je Eintrag – die manche Modelle schlecht treffen – wird
        // jede Position einzeln beschrieben.
        return [
            'type' => 'object',
            'properties' => [
                'sections' => [
                    'type' => 'array',
                    'minItems' => count($items),
                    'maxItems' => count($items),
                    'items' => count($items) === 1 ? $items[0] : ['anyOf' => $items],
                ],
            ],
            'required' => ['sections'],
        ];
    }

    /** Schema für den Inhalt genau eines Abschnitts. */
    public static function sectionContent(string $type): array
    {
        $definition = Schema::forType($type);
        if ($definition === null) {
            return ['type' => 'object', 'properties' => [], 'required' => []];
        }

        return [
            'type' => 'object',
            'properties' => [
                'content' => self::fields($definition['fields']),
                'overrides' => self::overrides(),
                'summary' => [
                    'type' => 'string',
                    'description' => 'Ein Satz zur Änderung, für das Protokoll.',
                ],
                'suggest_template' => [
                    'type' => 'string',
                    'description' => 'Nur füllen, wenn eine andere Vorlage besser passt. Sonst leer lassen.',
                ],
            ],
            'required' => ['content', 'summary'],
        ];
    }

    /** Schema für einen automatisch erzeugten Referenztext. */
    public static function reference(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'subtitle' => ['type' => 'string', 'description' => 'Ein Satz, höchstens 90 Zeichen.'],
                'body_de' => ['type' => 'string', 'description' => 'Zwei bis drei Absätze, mit Leerzeile getrennt.'],
                'body_en' => ['type' => 'string', 'description' => 'Dieselben Absätze auf Englisch.'],
                'tags' => [
                    'type' => 'array',
                    'minItems' => 1,
                    'maxItems' => 4,
                    'items' => ['type' => 'string'],
                    'description' => 'Kurze Schlagwörter, je 1 bis 2 Wörter.',
                ],
            ],
            'required' => ['subtitle', 'body_de', 'body_en', 'tags'],
        ];
    }

    // ------------------------------------------------------------------

    /** Feldbeschreibung -> JSON-Schema. */
    private static function fields(array $fields): array
    {
        $properties = [];
        $required = [];

        foreach ($fields as $name => $field) {
            $properties[$name] = self::field($field);
            // Alle Felder anfordern; nicht gebrauchte bleiben leer.
            // Das ist verlässlicher, als das Modell entscheiden zu lassen.
            $required[] = $name;
        }

        return [
            'type' => 'object',
            'properties' => $properties,
            'required' => $required,
        ];
    }

    private static function field(array $field): array
    {
        $label = (string) ($field['label'] ?? '');
        $max = (int) ($field['max'] ?? 0);

        $note = $label . ($max > 0 ? sprintf(' (höchstens %d Zeichen, leer lassen wenn nicht passend)', $max) : '');

        return match ($field['type']) {
            'text', 'textarea' => ['type' => 'string', 'description' => $note],

            'richtext' => [
                'type' => 'string',
                'description' => $note . '. Absätze mit Leerzeile trennen. '
                    . 'Erlaubt sind höchstens <strong>, <em>, <ul>, <li>, <h3>.',
            ],

            'icon' => [
                'type' => 'string',
                'enum' => array_merge([''], Icons::names()),
                'description' => $label . ' – nur aus dieser Liste, sonst leer.',
            ],

            'image' => [
                'type' => 'object',
                'properties' => [
                    'src' => ['type' => 'string', 'description' => 'Leer lassen.'],
                    'alt' => ['type' => 'string', 'description' => 'Was soll auf dem Bild zu sehen sein?'],
                ],
                'required' => ['src', 'alt'],
            ],

            'link' => [
                'type' => 'object',
                'properties' => [
                    'label' => ['type' => 'string', 'description' => $label . ' – Beschriftung, leer wenn keine.'],
                    'url' => ['type' => 'string', 'description' => 'Ziel, z.B. "kontakt.html".'],
                ],
                'required' => ['label', 'url'],
            ],

            'flag' => ['type' => 'boolean', 'description' => $label],

            'number' => [
                'type' => 'integer',
                'description' => $label,
                'minimum' => (int) ($field['min'] ?? 0),
                'maximum' => (int) ($field['max'] ?? 100),
            ],

            'list' => [
                'type' => 'array',
                'minItems' => (int) ($field['min'] ?? 0),
                'maxItems' => (int) ($field['max'] ?? 12),
                'description' => $label,
                'items' => self::fields($field['item'] ?? []),
            ],

            default => ['type' => 'string', 'description' => $note],
        };
    }

    /** Die Werte, die je Bildschirmgrösse gesetzt werden dürfen. */
    private static function overrides(): array
    {
        $breakpoint = [
            'type' => 'object',
            'properties' => [
                'align' => ['type' => 'string', 'enum' => ['', 'start', 'center', 'end']],
                'columns' => ['type' => 'string', 'description' => 'Spaltenzahl als Zahl, z.B. "3".'],
                'gap' => ['type' => 'string', 'description' => 'Abstand, z.B. "2rem".'],
                'padding' => ['type' => 'string', 'description' => 'Aussenabstand, z.B. "6rem".'],
                'titleSize' => ['type' => 'string', 'description' => 'Grösse der Überschrift, z.B. "3rem".'],
                'ctaScale' => ['type' => 'string', 'description' => 'Grösse der Schaltflächen, z.B. "1.2".'],
                'maxWidth' => ['type' => 'string', 'description' => 'Breite, z.B. "60rem".'],
            ],
            'required' => ['align', 'columns', 'gap', 'padding', 'titleSize', 'ctaScale', 'maxWidth'],
        ];

        return [
            'type' => 'object',
            'properties' => [
                'spacing' => ['type' => 'string', 'enum' => ['', 'tight', 'normal', 'loose']],
                'desktop' => $breakpoint,
                'tablet' => $breakpoint,
                'mobile' => $breakpoint,
            ],
            'required' => ['spacing', 'desktop', 'tablet', 'mobile'],
        ];
    }
}
