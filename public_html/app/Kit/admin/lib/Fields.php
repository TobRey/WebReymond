<?php

declare(strict_types=1);

namespace WebAtzeKit;

use WebAtze\Templates\Schema;

/**
 * Formularfelder eines Abschnitts – aus seinem Schema.
 *
 * Dieselbe Beschreibung erzeugt das Formular und prüft, was
 * zurückkommt. Deshalb kann das eine nicht vom anderen abweichen, und
 * deshalb landet über dieses Formular nichts im Abschnitt, was der
 * Abschnitt nicht kennt.
 *
 * Genau darin liegt auch die Zusage, dass eine Änderung nie den Rest der
 * Website berührt: Der Bearbeitungsbereich kann pro Vorgang genau einen
 * Abschnitt beschreiben, und in diesem Abschnitt genau die Felder, die
 * sein Typ vorsieht. Das ist keine Absichtserklärung, sondern der Bau.
 */
final class Fields
{
    /**
     * Eingaben gegen das Schema prüfen und mit dem bisherigen Inhalt
     * zusammenführen.
     */
    public static function merge(string $type, array $content, array $input): array
    {
        $fields = Schema::forType($type)['fields'] ?? [];

        foreach ($fields as $name => $field) {
            if (!array_key_exists($name, $input)) {
                continue;
            }

            $value = self::value($field, $input[$name], $content[$name] ?? null);

            if ($value === null) {
                continue;
            }

            $content[$name] = $value;
        }

        return $content;
    }

    /** Einen einzelnen Wert prüfen. null heisst: unverändert lassen. */
    private static function value(array $field, mixed $given, mixed $current): mixed
    {
        $type = (string) ($field['type'] ?? 'text');

        return match ($type) {
            'text', 'textarea', 'richtext' => self::plain(
                $given,
                (int) ($field['max'] ?? 500),
                $type !== 'text'
            ),

            'flag' => (bool) $given,

            'number' => max(
                (int) ($field['min'] ?? 0),
                min((int) ($field['max'] ?? 9999), (int) $given)
            ),

            'link' => is_array($given) ? [
                'label' => self::plain($given['label'] ?? '', 80),
                'url' => self::plain($given['url'] ?? '', 300),
            ] : null,

            // Bilder werden nicht über dieses Formular gesetzt, sondern
            // beim Hochladen. Hier lässt sich nur der Bildtext ändern –
            // wichtig für Menschen, die die Seite vorgelesen bekommen.
            'image' => is_array($given) ? array_merge(
                is_array($current) ? $current : [],
                ['alt' => self::plain($given['alt'] ?? '', 200)]
            ) : null,

            'icon' => self::plain($given, 40),

            'list' => self::list($field, $given, is_array($current) ? $current : []),

            default => null,
        };
    }

    /** Eine Liste: jeder Eintrag wird wie ein kleiner Abschnitt geprüft. */
    private static function list(array $field, mixed $given, array $current): ?array
    {
        if (!is_array($given)) {
            return null;
        }

        $itemFields = (array) ($field['item'] ?? []);
        $max = (int) ($field['max'] ?? 12);

        $out = [];
        $position = 0;

        foreach ($given as $row) {
            if (!is_array($row) || count($out) >= $max) {
                continue;
            }

            $existing = is_array($current[$position] ?? null) ? $current[$position] : [];
            $item = $existing;
            $empty = true;

            foreach ($itemFields as $name => $itemField) {
                if (!array_key_exists($name, $row)) {
                    continue;
                }

                $value = self::value($itemField, $row[$name], $existing[$name] ?? null);
                if ($value === null) {
                    continue;
                }

                $item[$name] = $value;

                if (is_string($value) ? trim($value) !== '' : !empty($value)) {
                    $empty = false;
                }
            }

            $position++;

            // Ein vollständig leerer Eintrag heisst: gelöscht.
            if ($empty && $item === $existing) {
                continue;
            }
            if ($empty) {
                continue;
            }

            $out[] = $item;
        }

        $min = (int) ($field['min'] ?? 0);
        if (count($out) < $min) {
            return null;
        }

        return $out;
    }

    /** Text säubern: keine Steuerzeichen, keine überlangen Eingaben. */
    private static function plain(mixed $value, int $max, bool $multiline = false): string
    {
        $value = str_replace("\0", '', (string) $value);

        if (!$multiline) {
            $value = str_replace(["\r", "\n"], ' ', $value);
        } else {
            $value = str_replace("\r\n", "\n", $value);
        }

        return mb_substr(trim($value), 0, $max);
    }

    /**
     * Welche Bildfelder hat dieser Abschnittstyp?
     * Danach richtet sich, wofür es einen Knopf zum Austauschen gibt.
     */
    public static function imageFields(string $type): array
    {
        $out = [];

        foreach (Schema::forType($type)['fields'] ?? [] as $name => $field) {
            if (($field['type'] ?? '') === 'image') {
                $out[$name] = (string) ($field['label'] ?? $name);
            }
        }

        return $out;
    }
}
