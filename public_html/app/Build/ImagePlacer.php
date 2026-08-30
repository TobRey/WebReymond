<?php

declare(strict_types=1);

namespace WebAtze\Build;

use WebAtze\Core\Db;

/**
 * Verteilt die vorhandenen Bilder auf die Abschnitte.
 *
 * Grundsatz: Es wird nie ein Bild erfunden. Sind keine da, bleiben die
 * Platzhalter stehen – sie sehen ordentlich aus und sagen deutlich, dass
 * hier noch ein Bild hingehört. Ein zufälliges Fremdbild wäre schlimmer
 * als eine ehrliche Lücke.
 *
 * Die Zuordnung folgt der Beschreibung: Steht im "alt"-Feld eines
 * Abschnitts "Werkstatt", bekommt er möglichst ein Bild, dessen eigene
 * Beschreibung dazu passt.
 */
final class ImagePlacer
{
    /** Wie viele Bilder ein Abschnittstyp höchstens braucht. */
    private const NEEDS = [
        'hero' => 1,
        'about' => 1,
        'cta' => 1,
        'gallery' => 12,
        'team' => 8,
        'services' => 6,
        'features' => 6,
        'testimonials' => 4,
        'logos' => 8,
    ];

    public static function place(int $projectId): int
    {
        $assets = Db::all(
            "SELECT * FROM project_assets
             WHERE project_id = :p AND kind = 'image'
             ORDER BY width DESC, id ASC",
            ['p' => $projectId]
        );

        if ($assets === []) {
            return 0;
        }

        $sections = Db::all(
            'SELECT * FROM project_sections WHERE project_id = :p ORDER BY page_id ASC, sort_order ASC',
            ['p' => $projectId]
        );

        $used = [];
        $placed = 0;

        foreach ($sections as $section) {
            $type = (string) $section['type'];
            $need = self::NEEDS[$type] ?? 0;

            if ($need === 0) {
                continue;
            }

            $content = json_decode((string) $section['content'], true);
            if (!is_array($content)) {
                continue;
            }

            $changed = false;

            // Einzelbild des Abschnitts
            if (isset($content['image']) && is_array($content['image'])
                && ($content['image']['src'] ?? '') === '') {
                $asset = self::pick($assets, $used, (string) ($content['image']['alt'] ?? ''));
                if ($asset !== null) {
                    $content['image'] = self::toImage($asset, (string) ($content['image']['alt'] ?? ''));
                    $used[(int) $asset['id']] = true;
                    $placed++;
                    $changed = true;
                }
            }

            // Bilder in den Einträgen
            if (isset($content['items']) && is_array($content['items'])) {
                $budget = $need;

                foreach ($content['items'] as $key => $item) {
                    if ($budget <= 0) {
                        break;
                    }
                    if (!is_array($item) || !isset($item['image']) || !is_array($item['image'])) {
                        continue;
                    }
                    if (($item['image']['src'] ?? '') !== '') {
                        continue;
                    }

                    $hint = trim(((string) ($item['image']['alt'] ?? '')) . ' ' . ((string) ($item['title'] ?? '')));
                    $asset = self::pick($assets, $used, $hint);

                    if ($asset === null) {
                        break;
                    }

                    $content['items'][$key]['image'] = self::toImage($asset, (string) ($item['image']['alt'] ?? ''));
                    $used[(int) $asset['id']] = true;
                    $placed++;
                    $budget--;
                    $changed = true;
                }
            }

            if ($changed) {
                Db::update('project_sections', [
                    'content' => $content,
                    'updated_at' => Db::now(),
                ], 'id = :id', ['id' => (int) $section['id']]);
            }
        }

        return $placed;
    }

    /**
     * Ein noch freies Bild aussuchen, möglichst passend zur Beschreibung.
     */
    private static function pick(array $assets, array $used, string $hint): ?array
    {
        $hint = mb_strtolower(trim($hint));
        $best = null;
        $bestScore = -1;

        foreach ($assets as $asset) {
            if (isset($used[(int) $asset['id']])) {
                continue;
            }

            $score = 0;

            if ($hint !== '') {
                $alt = mb_strtolower((string) $asset['alt']);
                foreach (preg_split('/\W+/u', $hint) ?: [] as $word) {
                    // Kurze Wörter ("und", "der") sagen nichts aus.
                    if (mb_strlen($word) >= 4 && $alt !== '' && str_contains($alt, $word)) {
                        $score += 10;
                    }
                }
            }

            // Bei Gleichstand gewinnt das grössere Bild.
            $score += min(5, (int) ((int) $asset['width'] / 400));

            if ($score > $bestScore) {
                $bestScore = $score;
                $best = $asset;
            }
        }

        return $best;
    }

    private static function toImage(array $asset, string $fallbackAlt): array
    {
        $alt = trim((string) $asset['alt']);
        if ($alt === '') {
            $alt = $fallbackAlt;
        }

        return [
            'src' => 'assets/img/' . basename((string) $asset['path']),
            'alt' => $alt,
            'width' => (int) $asset['width'],
            'height' => (int) $asset['height'],
        ];
    }
}
