<?php

declare(strict_types=1);

namespace WebAtze\Templates;

/**
 * Welche Inhalte hat welcher Abschnittstyp?
 *
 * Diese Beschreibung ist die Grundlage für drei Dinge gleichzeitig:
 *
 *   1. Sie wird der KI als JSON-Schema mitgegeben – die Antwort passt
 *      dadurch garantiert, es muss nichts aus Fliesstext gefischt werden.
 *   2. Der Bearbeitungsbereich baut daraus seine Eingabefelder.
 *   3. Eine Änderung durch die KI wird dagegen geprüft. Nur was hier steht,
 *      darf verändert werden – deshalb kann eine Anweisung für einen
 *      Abschnitt niemals den Rest der Website berühren.
 *
 * Weil alle Varianten eines Typs dasselbe Schema benutzen, lässt sich die
 * Vorlage wechseln, ohne dass Inhalte verlorengehen.
 */
final class Schema
{
    /**
     * @return array<string, array{label:string, description:string, fields:array}>
     */
    public static function all(): array
    {
        return [

            'header' => [
                'label' => 'Kopfzeile',
                'description' => 'Logo, Navigation und ein Aufruf zum Handeln.',
                'fields' => [
                    'brand' => self::text('Firmenname im Kopf', 60, true),
                    'cta' => self::link('Schaltfläche im Kopf'),
                ],
            ],

            'hero' => [
                'label' => 'Aufmacher',
                'description' => 'Der erste Eindruck: Überschrift, kurzer Text, Schaltflächen.',
                'fields' => [
                    'eyebrow' => self::text('Überzeile', 70),
                    'title' => self::text('Überschrift', 140, true),
                    'lead' => self::textarea('Einleitung', 400),
                    'cta' => self::link('Hauptschaltfläche'),
                    'cta_secondary' => self::link('Zweite Schaltfläche'),
                    'image' => self::image('Grossbild'),
                    'badges' => self::list('Kurze Vorteile', 0, 4, [
                        'text' => self::text('Vorteil', 60, true),
                    ]),
                ],
            ],

            'features' => [
                'label' => 'Vorteile',
                'description' => 'Mehrere kurze Punkte, die für die Firma sprechen.',
                'fields' => [
                    'eyebrow' => self::text('Überzeile', 70),
                    'title' => self::text('Überschrift', 140, true),
                    'lead' => self::textarea('Einleitung', 400),
                    'items' => self::list('Punkte', 2, 9, [
                        'icon' => self::icon('Sinnbild'),
                        'title' => self::text('Titel', 80, true),
                        'text' => self::textarea('Text', 300, true),
                    ]),
                ],
            ],

            'services' => [
                'label' => 'Leistungen',
                'description' => 'Was angeboten wird, jeweils mit Beschreibung.',
                'fields' => [
                    'eyebrow' => self::text('Überzeile', 70),
                    'title' => self::text('Überschrift', 140, true),
                    'lead' => self::textarea('Einleitung', 400),
                    'items' => self::list('Leistungen', 2, 9, [
                        'icon' => self::icon('Sinnbild'),
                        'title' => self::text('Titel', 80, true),
                        'text' => self::textarea('Beschreibung', 400, true),
                        'image' => self::image('Bild'),
                        'link' => self::link('Weiterführender Verweis'),
                    ]),
                    'cta' => self::link('Schaltfläche'),
                ],
            ],

            'about' => [
                'label' => 'Über uns',
                'description' => 'Die Geschichte hinter der Firma, meist mit Bild.',
                'fields' => [
                    'eyebrow' => self::text('Überzeile', 70),
                    'title' => self::text('Überschrift', 140, true),
                    'body' => self::richtext('Text', 2500, true),
                    'image' => self::image('Bild'),
                    'cta' => self::link('Schaltfläche'),
                    'highlights' => self::list('Kurze Merkmale', 0, 4, [
                        'title' => self::text('Titel', 60, true),
                        'text' => self::text('Text', 160),
                    ]),
                ],
            ],

            'gallery' => [
                'label' => 'Galerie',
                'description' => 'Eine Bildstrecke, zum Beispiel von Arbeiten oder Räumen.',
                'fields' => [
                    'eyebrow' => self::text('Überzeile', 70),
                    'title' => self::text('Überschrift', 140),
                    'lead' => self::textarea('Einleitung', 400),
                    'items' => self::list('Bilder', 2, 24, [
                        'image' => self::image('Bild', true),
                        'caption' => self::text('Bildunterschrift', 120),
                    ]),
                ],
            ],

            'testimonials' => [
                'label' => 'Kundenstimmen',
                'description' => 'Zitate zufriedener Kundinnen und Kunden.',
                'fields' => [
                    'eyebrow' => self::text('Überzeile', 70),
                    'title' => self::text('Überschrift', 140),
                    'items' => self::list('Stimmen', 1, 9, [
                        'quote' => self::textarea('Zitat', 500, true),
                        'name' => self::text('Name', 80, true),
                        'role' => self::text('Funktion oder Ort', 100),
                        'image' => self::image('Bild'),
                        'rating' => self::number('Sterne', 0, 5),
                    ]),
                ],
            ],

            'team' => [
                'label' => 'Team',
                'description' => 'Die Menschen hinter der Firma.',
                'fields' => [
                    'eyebrow' => self::text('Überzeile', 70),
                    'title' => self::text('Überschrift', 140),
                    'lead' => self::textarea('Einleitung', 400),
                    'items' => self::list('Personen', 1, 12, [
                        'name' => self::text('Name', 80, true),
                        'role' => self::text('Funktion', 100, true),
                        'text' => self::textarea('Kurztext', 300),
                        'image' => self::image('Bild'),
                        'email' => self::text('E-Mail', 190),
                    ]),
                ],
            ],

            'process' => [
                'label' => 'Ablauf',
                'description' => 'Schritt für Schritt, wie die Zusammenarbeit läuft.',
                'fields' => [
                    'eyebrow' => self::text('Überzeile', 70),
                    'title' => self::text('Überschrift', 140, true),
                    'lead' => self::textarea('Einleitung', 400),
                    'items' => self::list('Schritte', 2, 8, [
                        'title' => self::text('Titel', 80, true),
                        'text' => self::textarea('Beschreibung', 350, true),
                        'icon' => self::icon('Sinnbild'),
                    ]),
                ],
            ],

            'stats' => [
                'label' => 'Zahlen',
                'description' => 'Wenige aussagekräftige Zahlen, gross dargestellt.',
                'fields' => [
                    'title' => self::text('Überschrift', 140),
                    'items' => self::list('Zahlen', 2, 6, [
                        'value' => self::text('Wert', 20, true),
                        'label' => self::text('Bezeichnung', 80, true),
                    ]),
                ],
            ],

            'logos' => [
                'label' => 'Partner',
                'description' => 'Logos von Partnern, Marken oder Zertifikaten.',
                'fields' => [
                    'title' => self::text('Überschrift', 140),
                    'items' => self::list('Logos', 2, 16, [
                        'image' => self::image('Logo', true),
                        'name' => self::text('Name', 80, true),
                    ]),
                ],
            ],

            'faq' => [
                'label' => 'Häufige Fragen',
                'description' => 'Fragen und Antworten zum Aufklappen.',
                'fields' => [
                    'eyebrow' => self::text('Überzeile', 70),
                    'title' => self::text('Überschrift', 140, true),
                    'lead' => self::textarea('Einleitung', 400),
                    'items' => self::list('Fragen', 2, 15, [
                        'question' => self::text('Frage', 200, true),
                        'answer' => self::textarea('Antwort', 1200, true),
                    ]),
                ],
            ],

            'cta' => [
                'label' => 'Aufruf zum Handeln',
                'description' => 'Ein deutlicher Anstoss, Kontakt aufzunehmen.',
                'fields' => [
                    'title' => self::text('Überschrift', 140, true),
                    'lead' => self::textarea('Text', 400),
                    'cta' => self::link('Hauptschaltfläche', true),
                    'cta_secondary' => self::link('Zweite Schaltfläche'),
                    'image' => self::image('Hintergrundbild'),
                ],
            ],

            'contact' => [
                'label' => 'Kontakt',
                'description' => 'Formular und Kontaktangaben.',
                'fields' => [
                    'eyebrow' => self::text('Überzeile', 70),
                    'title' => self::text('Überschrift', 140, true),
                    'lead' => self::textarea('Einleitung', 500),
                    'email' => self::text('E-Mail', 190),
                    'phone' => self::text('Telefon', 60),
                    'address' => self::textarea('Adresse', 300),
                    'hours' => self::textarea('Öffnungszeiten', 400),
                    'show_form' => self::flag('Formular anzeigen', true),
                    'show_map' => self::flag('Karte anzeigen', false),
                    'map_query' => self::text('Adresse für die Karte', 200),
                ],
            ],

            'text' => [
                'label' => 'Textabschnitt',
                'description' => 'Freier Text, zum Beispiel für rechtliche Seiten.',
                'fields' => [
                    'eyebrow' => self::text('Überzeile', 70),
                    'title' => self::text('Überschrift', 140),
                    'body' => self::richtext('Text', 12000, true),
                ],
            ],

            'footer' => [
                'label' => 'Fusszeile',
                'description' => 'Abschluss der Seite mit Verweisen und Angaben.',
                'fields' => [
                    'about' => self::textarea('Kurzbeschreibung', 400),
                    'email' => self::text('E-Mail', 190),
                    'phone' => self::text('Telefon', 60),
                    'address' => self::textarea('Adresse', 300),
                    'social' => self::list('Soziale Netzwerke', 0, 6, [
                        'name' => self::text('Name', 40, true),
                        'url' => self::text('Adresse', 300, true),
                    ]),
                    'note' => self::text('Zusatzzeile', 200),
                ],
            ],

            // Der freie Abschnitt.
            //
            // Er hat als einziger kein festes Feldergerüst, sondern eine
            // Liste von Bausteinen (Templates\Blocks). Deshalb steht
            // hier nur der Platz dafür - geprüft wird der Inhalt dort,
            // nach denselben Regeln, aber mit einer eigenen Beschreibung.
            //
            // Warum er trotzdem ein Abschnittstyp ist und kein Sonderweg:
            // Alles andere - ziehen, umordnen, Effekte, Übersetzungen,
            // Veröffentlichen - soll für ihn gelten wie für jeden
            // anderen. Ein Sonderweg hätte jede dieser Stellen um einen
            // Wenn-dann-Fall erweitert.
            'frei' => [
                'label' => 'Freier Abschnitt',
                'description' => 'Eine leere Fläche für eigene Bausteine.',
                'fields' => [
                    'blocks' => ['type' => 'blocks', 'label' => 'Bausteine', 'required' => false],
                ],
            ],
        ];
    }

    public static function forType(string $type): ?array
    {
        return self::all()[$type] ?? null;
    }

    public static function types(): array
    {
        return array_keys(self::all());
    }

    public static function exists(string $type): bool
    {
        return isset(self::all()[$type]);
    }

    // ------------------------------------------------------------------
    // Bausteine der Beschreibung
    // ------------------------------------------------------------------

    private static function text(string $label, int $max, bool $required = false): array
    {
        return ['type' => 'text', 'label' => $label, 'max' => $max, 'required' => $required];
    }

    private static function textarea(string $label, int $max, bool $required = false): array
    {
        return ['type' => 'textarea', 'label' => $label, 'max' => $max, 'required' => $required];
    }

    /** Mehrzeiliger Text mit einfacher Auszeichnung (Absätze, fett, Listen). */
    private static function richtext(string $label, int $max, bool $required = false): array
    {
        return ['type' => 'richtext', 'label' => $label, 'max' => $max, 'required' => $required];
    }

    private static function image(string $label, bool $required = false): array
    {
        return ['type' => 'image', 'label' => $label, 'required' => $required];
    }

    private static function icon(string $label): array
    {
        return ['type' => 'icon', 'label' => $label, 'required' => false];
    }

    private static function link(string $label, bool $required = false): array
    {
        return ['type' => 'link', 'label' => $label, 'required' => $required];
    }

    private static function flag(string $label, bool $default): array
    {
        return ['type' => 'flag', 'label' => $label, 'default' => $default, 'required' => false];
    }

    private static function number(string $label, int $min, int $max): array
    {
        return ['type' => 'number', 'label' => $label, 'min' => $min, 'max' => $max, 'required' => false];
    }

    private static function list(string $label, int $min, int $max, array $item): array
    {
        return [
            'type' => 'list',
            'label' => $label,
            'min' => $min,
            'max' => $max,
            'item' => $item,
            'required' => $min > 0,
        ];
    }
}
