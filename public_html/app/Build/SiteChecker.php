<?php

declare(strict_types=1);

namespace WebAtze\Build;

use WebAtze\Ai\ClaudeClient;
use WebAtze\Core\{Db, Http, Logger};

/**
 * Die Prüfungen nach dem Bauen.
 *
 * Vier Stück, und alle vier prüfen das, was tatsächlich herausgekommen
 * ist – nicht das, was geplant war. Der Unterschied ist der ganze Sinn
 * der Übung: Ein Bauplan, der stimmt, und eine Website, die nicht
 * stimmt, kommen häufiger vor, als einem lieb ist.
 *
 *   tempo     Wie schwer ist die Seite, und was bremst sie?
 *   verweise  Zeigt ein Verweis ins Leere?
 *   text      Tippfehler und schiefe Sätze.
 *   recht     Impressum, Datenschutz, fremde Dienste.
 *
 * Ergebnisse landen in site_checks und werden im Projekt angezeigt.
 * Sie halten den Bau nie auf: Eine Prüfung, die den Auftrag scheitern
 * lässt, wird beim nächsten Mal abgeschaltet – und dann prüft
 * niemand mehr.
 */
final class SiteChecker
{
    /** Ab hier gilt eine Seite als schwer. */
    private const HEAVY_PAGE_BYTES = 900 * 1024;

    /** Ein einzelnes Bild sollte darunter bleiben. */
    private const HEAVY_IMAGE_BYTES = 300 * 1024;

    /** So viele fremde Adressen werden angeklopft, nicht mehr. */
    private const MAX_EXTERNAL = 12;

    /**
     * Fremde Dienste, die eine Einwilligung nötig machen.
     *
     * Nicht, weil sie böse wären, sondern weil sie beim Aufruf der Seite
     * ungefragt die Adresse des Besuchers an einen Dritten geben. Genau
     * das ist es, wofür ein Zustimmungsbanner gebraucht wird – und den
     * wollen wir nicht.
     */
    private const THIRD_PARTY = [
        'fonts.googleapis.com' => 'Google Fonts',
        'fonts.gstatic.com' => 'Google Fonts',
        'google-analytics.com' => 'Google Analytics',
        'googletagmanager.com' => 'Google Tag Manager',
        'connect.facebook.net' => 'Facebook-Pixel',
        'youtube.com/embed' => 'YouTube',
        'player.vimeo.com' => 'Vimeo',
        'maps.googleapis.com' => 'Google Maps',
        'google.com/maps/embed' => 'Google Maps',
        'cdn.jsdelivr.net' => 'jsDelivr',
        'cdnjs.cloudflare.com' => 'cdnjs',
        'unpkg.com' => 'unpkg',
        'hotjar.com' => 'Hotjar',
        'doubleclick.net' => 'Google Ads',
    ];

    /**
     * Alle Prüfungen für ein Projekt.
     *
     * @return array<string, array{passed:bool, score:int, findings:array<int, array{level:string, text:string}>}>
     */
    public static function runAll(array $project, float $budget = 30.0): array
    {
        $dist = STORAGE_DIR . '/projects/' . (string) $project['slug'] . '/dist';

        if (!is_dir($dist)) {
            return [];
        }

        $started = microtime(true);
        $results = [];

        $results['tempo'] = self::speed($project, $dist);
        $results['verweise'] = self::links($project, $dist, max(3.0, $budget - (microtime(true) - $started)));
        $results['recht'] = self::legal($project, $dist);

        // Die Textprüfung kostet Geld und Zeit. Sie kommt zuletzt und
        // fällt weg, wenn die Zeit knapp ist.
        if (microtime(true) - $started < $budget * 0.6) {
            $results['text'] = self::proofread($project, $dist);
        }

        foreach ($results as $kind => $result) {
            self::store((int) $project['id'], $kind, $result);
        }

        return $results;
    }

    // ------------------------------------------------------------------ Tempo

    /**
     * Wie schnell ist die Seite?
     *
     * Gemessen wird an dem, was ausgeliefert wird: Wie viele Bytes muss
     * ein Besucher laden, wie viele Dateien holt der Browser, wie gross
     * ist das grösste Bild. Das sind die drei Grössen, aus denen sich
     * Ladezeit fast vollständig ergibt – und die einzigen, die sich
     * ohne einen echten Browser ehrlich bestimmen lassen.
     *
     * Ist die Website schon erreichbar, kommt eine echte Messung dazu.
     */
    public static function speed(array $project, string $dist): array
    {
        $findings = [];
        $score = 100;

        $pages = glob($dist . '/*.html') ?: [];

        $heaviest = 0;
        $heaviestName = '';

        foreach ($pages as $page) {
            $html = (string) file_get_contents($page);
            $bytes = strlen($html) + self::weightOfAssets($html, $dist);

            if ($bytes > $heaviest) {
                $heaviest = $bytes;
                $heaviestName = basename($page);
            }
        }

        if ($heaviest > self::HEAVY_PAGE_BYTES) {
            $score -= 25;
            $findings[] = self::note(
                'warn',
                sprintf(
                    'Die schwerste Seite (%s) bringt %s auf die Waage. Über einen '
                    . 'Mobilfunkanschluss dauert das spürbar.',
                    $heaviestName,
                    self::bytes($heaviest)
                )
            );
        } else {
            $findings[] = self::note(
                'ok',
                sprintf('Die schwerste Seite bleibt bei %s.', self::bytes($heaviest))
            );
        }

        // Grosse Bilder
        $big = [];
        foreach (self::allFiles($dist) as $file) {
            if (!preg_match('/\.(jpe?g|png|webp|avif|gif)$/i', $file)) {
                continue;
            }

            $size = (int) filesize($file);

            if ($size > self::HEAVY_IMAGE_BYTES) {
                $big[] = basename($file) . ' (' . self::bytes($size) . ')';
            }
        }

        if ($big !== []) {
            $score -= min(25, count($big) * 5);
            $findings[] = self::note(
                'warn',
                'Diese Bilder sind gross: ' . implode(', ', array_slice($big, 0, 5))
                . (count($big) > 5 ? ' und ' . (count($big) - 5) . ' weitere' : '') . '.'
            );
        }

        // Bilder ohne feste Grösse lassen die Seite beim Laden springen.
        $jumping = 0;
        foreach ($pages as $page) {
            $html = (string) file_get_contents($page);
            preg_match_all('/<img\b[^>]*>/i', $html, $matches);

            foreach ($matches[0] as $tag) {
                if (!preg_match('/\bwidth=/i', $tag) || !preg_match('/\bheight=/i', $tag)) {
                    $jumping++;
                }
            }
        }

        if ($jumping > 0) {
            $score -= min(20, $jumping * 4);
            $findings[] = self::note(
                'warn',
                $jumping . ' Bilder ohne Breite und Höhe im Quelltext. Die Seite '
                . 'springt beim Laden, weil der Browser den Platz erst kennt, wenn '
                . 'das Bild da ist.'
            );
        } else {
            $findings[] = self::note('ok', 'Alle Bilder haben eine feste Grösse – die Seite springt nicht.');
        }

        // Was den ersten Anblick aufhält
        $blocking = 0;
        foreach ($pages as $page) {
            $head = (string) substr((string) file_get_contents($page), 0, 8000);
            preg_match_all('/<script\b(?![^>]*\b(?:defer|async|type="application\/ld\+json"))[^>]*\bsrc=/i', $head, $m);
            $blocking += count($m[0]);
        }

        if ($blocking > 0) {
            $score -= 15;
            $findings[] = self::note(
                'warn',
                $blocking . ' Skripte halten die Darstellung auf. Sie brauchen ein '
                . '"defer" – sonst wartet der Browser, bevor er überhaupt etwas zeigt.'
            );
        }

        // Und wenn die Seite schon steht: einmal wirklich abrufen.
        $live = self::measureLive($project);
        if ($live !== null) {
            $findings[] = $live['ms'] <= 800
                ? self::note('ok', sprintf('Der Server antwortet in %d ms.', $live['ms']))
                : self::note('warn', sprintf(
                    'Der Server braucht %d ms bis zur ersten Antwort. Das liegt am '
                    . 'Hosting, nicht an der Seite.',
                    $live['ms']
                ));

            if ($live['ms'] > 1500) {
                $score -= 10;
            }
        }

        return self::result($score, $findings);
    }

    /** Wie schwer sind die Dateien, die eine Seite nachlädt? */
    private static function weightOfAssets(string $html, string $dist): int
    {
        $bytes = 0;
        $seen = [];

        preg_match_all('/(?:src|href)="([^"]+)"/i', $html, $matches);

        foreach ($matches[1] as $reference) {
            if (str_starts_with($reference, 'http') || str_starts_with($reference, '#')
                || str_starts_with($reference, 'mailto:') || str_starts_with($reference, 'data:')) {
                continue;
            }

            if (!preg_match('/\.(css|js|jpe?g|png|webp|avif|svg|woff2?)$/i', $reference)) {
                continue;
            }

            $path = $dist . '/' . ltrim(explode('?', $reference)[0], '/');

            if (isset($seen[$path]) || !is_file($path)) {
                continue;
            }

            $seen[$path] = true;
            $bytes += (int) filesize($path);
        }

        return $bytes;
    }

    /** Eine echte Messung – nur, wenn die Seite schon erreichbar ist. */
    private static function measureLive(array $project): ?array
    {
        $domain = trim((string) ($project['domain'] ?? ''));

        if ($domain === '') {
            return null;
        }

        $url = 'https://' . preg_replace('~^https?://~i', '', $domain);

        $started = microtime(true);
        $answer = Http::get($url, 10);
        $ms = (int) round((microtime(true) - $started) * 1000);

        return ($answer['status'] ?? 0) === 200 ? ['ms' => $ms] : null;
    }

    // --------------------------------------------------------------- Verweise

    /**
     * Zeigt ein Verweis ins Leere?
     *
     * Interne Verweise werden gegen die gebauten Dateien geprüft – das
     * ist schnell und vollständig. Fremde Adressen werden angeklopft,
     * aber nur eine Handvoll und mit kurzem Zeitlimit: Ein fremder
     * Server, der nicht antwortet, darf den Bau nicht aufhalten.
     */
    public static function links(array $project, string $dist, float $budget = 15.0): array
    {
        $findings = [];
        $score = 100;

        $internal = [];
        $external = [];

        foreach (glob($dist . '/*.html') ?: [] as $page) {
            $html = (string) file_get_contents($page);
            preg_match_all('/href="([^"]+)"/i', $html, $matches);

            foreach ($matches[1] as $href) {
                if ($href === '' || str_starts_with($href, '#')
                    || str_starts_with($href, 'mailto:') || str_starts_with($href, 'tel:')
                    || str_starts_with($href, 'data:')) {
                    continue;
                }

                if (preg_match('~^https?://~i', $href)) {
                    $external[$href] = true;
                } else {
                    $internal[$href][] = basename($page);
                }
            }
        }

        $broken = [];

        foreach ($internal as $href => $pages) {
            $target = explode('#', explode('?', (string) $href)[0])[0];

            if ($target === '' || $target === './') {
                continue;
            }

            $path = $dist . '/' . ltrim($target, '/');

            // /kontakt findet kontakt.html – so steht es in der .htaccess.
            if (is_file($path) || is_dir($path) || is_file($path . '.html')
                || is_file(rtrim($path, '/') . '/index.html')) {
                continue;
            }

            $broken[] = $target . ' (auf ' . implode(', ', array_unique($pages)) . ')';
        }

        if ($broken !== []) {
            $score -= min(60, count($broken) * 15);
            $findings[] = self::note(
                'error',
                'Diese Verweise zeigen ins Leere: ' . implode('; ', array_slice($broken, 0, 8)) . '.'
            );
        } else {
            $findings[] = self::note('ok', 'Alle Verweise innerhalb der Website führen irgendwohin.');
        }

        // Fremde Adressen
        $started = microtime(true);
        $dead = [];
        $checked = 0;

        foreach (array_keys($external) as $url) {
            if ($checked >= self::MAX_EXTERNAL || microtime(true) - $started > $budget) {
                break;
            }

            $checked++;
            $answer = Http::get((string) $url, 6);
            $status = (int) ($answer['status'] ?? 0);

            // 0 heisst: gar keine Antwort. 403 sperrt oft nur uns aus –
            // das ist kein toter Verweis, und wir behaupten es auch nicht.
            if ($status === 404 || $status === 410) {
                $dead[] = (string) $url;
            }
        }

        if ($dead !== []) {
            $score -= min(30, count($dead) * 10);
            $findings[] = self::note(
                'warn',
                'Diese fremden Adressen antworten mit "gibt es nicht": ' . implode(', ', $dead) . '.'
            );
        } elseif ($checked > 0) {
            $findings[] = self::note('ok', $checked . ' fremde Adressen geprüft, alle erreichbar.');
        }

        return self::result($score, $findings);
    }

    // ------------------------------------------------------------------ Recht

    /**
     * Impressum, Datenschutz, fremde Dienste.
     *
     * Das ist keine Rechtsberatung und gibt sich auch nicht als solche
     * aus. Geprüft wird, ob die Pflichtseiten da sind, ob sie mehr als
     * eine Überschrift enthalten und ob die Seite ungefragt jemanden
     * Dritten einbindet. Genau diese drei Punkte sind es, an denen es in
     * der Praxis scheitert.
     */
    public static function legal(array $project, string $dist): array
    {
        $findings = [];
        $score = 100;

        $needed = [
            'impressum' => ['impressum', 'imprint', 'legal'],
            'datenschutz' => ['datenschutz', 'privacy', 'privatsphaere'],
        ];

        $files = array_map('basename', glob($dist . '/*.html') ?: []);

        foreach ($needed as $label => $variants) {
            $found = '';

            foreach ($files as $file) {
                foreach ($variants as $variant) {
                    if (str_contains(mb_strtolower($file), $variant)) {
                        $found = $file;
                        break 2;
                    }
                }
            }

            if ($found === '') {
                $score -= 35;
                $findings[] = self::note(
                    'error',
                    'Es gibt keine Seite "' . ucfirst($label) . '". In der Schweiz und in '
                    . 'der EU ist sie für eine geschäftliche Website Pflicht.'
                );
                continue;
            }

            $text = trim(strip_tags((string) file_get_contents($dist . '/' . $found)));

            if (mb_strlen($text) < 300) {
                $score -= 15;
                $findings[] = self::note(
                    'warn',
                    ucfirst($label) . ' ist da, aber mit ' . mb_strlen($text)
                    . ' Zeichen sehr knapp. Fehlt dort noch etwas?'
                );
            } else {
                $findings[] = self::note('ok', ucfirst($label) . ' ist vorhanden (' . $found . ').');
            }
        }

        // Vollständigkeit des Impressums
        $brief = json_decode((string) ($project['brief'] ?? '{}'), true) ?: [];
        $missing = [];

        foreach ([
            'contact_email' => 'E-Mail-Adresse',
            'contact_address' => 'Postadresse',
        ] as $field => $label) {
            if (trim((string) ($brief[$field] ?? '')) === '') {
                $missing[] = $label;
            }
        }

        if ($missing !== []) {
            $score -= 10;
            $findings[] = self::note(
                'warn',
                'Im Impressum fehlt: ' . implode(' und ', $missing)
                . '. Ohne diese Angaben ist es unvollständig.'
            );
        }

        // Fremde Dienste
        $third = [];

        foreach (self::allFiles($dist) as $file) {
            if (!preg_match('/\.(html|css|js)$/i', $file)) {
                continue;
            }

            $content = (string) file_get_contents($file);

            foreach (self::THIRD_PARTY as $needle => $name) {
                if (str_contains($content, $needle)) {
                    $third[$name] = true;
                }
            }
        }

        if ($third !== []) {
            $score -= 25;
            $findings[] = self::note(
                'error',
                'Die Seite bindet fremde Dienste ein: ' . implode(', ', array_keys($third))
                . '. Damit geht die Adresse jedes Besuchers ungefragt an einen Dritten – '
                . 'und dafür bräuchte die Seite einen Zustimmungsbanner. Besser: die '
                . 'Dateien mitliefern statt nachladen.'
            );
        } else {
            $findings[] = self::note(
                'ok',
                'Kein fremder Dienst wird eingebunden. Die Seite kommt ohne '
                . 'Zustimmungsbanner aus.'
            );
        }

        // Cookies
        $cookies = false;
        foreach (self::allFiles($dist) as $file) {
            if (preg_match('/\.js$/i', $file) && str_contains((string) file_get_contents($file), 'document.cookie')) {
                $cookies = true;
                break;
            }
        }

        if ($cookies) {
            $score -= 10;
            $findings[] = self::note(
                'warn',
                'Es wird ein Cookie gesetzt. Prüfe, wofür – ist es nicht technisch '
                . 'nötig, braucht es eine Einwilligung.'
            );
        }

        return self::result($score, $findings);
    }

    // ------------------------------------------------------------------- Text

    /**
     * Tippfehler und schiefe Sätze.
     *
     * Dafür gibt es keine mitgelieferte Wörterbuchdatei – ein deutsches
     * Wörterbuch wäre grösser als die ganze Anwendung, und für Französisch
     * und Italienisch bräuchte es je ein weiteres. Also liest ein
     * Sprachmodell die Texte gegen.
     *
     * Gemeldet wird nur, was sicher falsch ist. Eine Prüfung, die bei
     * jedem zweiten Satz etwas anmerkt, liest nach dem dritten Mal
     * niemand mehr.
     */
    public static function proofread(array $project, string $dist): array
    {
        if (!ClaudeClient::isConfigured()) {
            return self::result(0, [self::note(
                'info',
                'Ohne Schlüssel zur Sprach-API wird nicht gegengelesen.'
            )]);
        }

        $texts = [];

        foreach (Db::all(
            'SELECT s.content, p.title FROM project_sections s
             JOIN project_pages p ON p.id = s.page_id
             WHERE s.project_id = :p ORDER BY p.sort_order, s.sort_order',
            ['p' => (int) $project['id']]
        ) as $row) {
            $content = json_decode((string) $row['content'], true);

            if (!is_array($content)) {
                continue;
            }

            foreach ($content as $key => $value) {
                if (is_string($value) && mb_strlen(trim($value)) > 15) {
                    $texts[] = (string) $row['title'] . ' · ' . $key . ': ' . trim($value);
                }
            }
        }

        if ($texts === []) {
            return self::result(100, [self::note('ok', 'Kein Text zum Prüfen gefunden.')]);
        }

        // Nicht die ganze Website auf einmal – das wird teuer und die
        // Antwort ungenau.
        $sample = implode("\n", array_slice($texts, 0, 120));

        try {
            $answer = (new ClaudeClient())->forProject((int) $project['id'])->structured(
                'korrektur',
                'Du liest Texte einer Firmenwebsite Korrektur. Melde ausschliesslich '
                . 'eindeutige Fehler: Rechtschreibung, Zeichensetzung, Grammatik, '
                . 'falsche Wortwahl, unvollständige Sätze. '
                . 'Melde NICHT: Stilfragen, Geschmack, Formulierungsvorschläge, '
                . 'Wiederholungen, Länge. '
                . 'Schweizer Rechtschreibung: "ss" statt "ß" ist richtig, nicht falsch. '
                . 'Findest du nichts, gib eine leere Liste zurück – das ist ein '
                . 'gültiges und häufiges Ergebnis.',
                "Diese Texte gehören zu einer Website:\n\n" . $sample,
                [
                    'type' => 'object',
                    'properties' => [
                        'fehler' => [
                            'type' => 'array',
                            'items' => [
                                'type' => 'object',
                                'properties' => [
                                    'stelle' => ['type' => 'string'],
                                    'falsch' => ['type' => 'string'],
                                    'richtig' => ['type' => 'string'],
                                ],
                                'required' => ['stelle', 'falsch', 'richtig'],
                            ],
                        ],
                    ],
                    'required' => ['fehler'],
                ],
                ['max_tokens' => 4000, 'effort' => 'medium']
            );
        } catch (\Throwable $e) {
            Logger::info('Korrekturlesen fehlgeschlagen: ' . $e->getMessage());
            return self::result(0, [self::note('info', 'Das Gegenlesen hat nicht geklappt.')]);
        }

        $errors = (array) ($answer['fehler'] ?? []);

        if ($errors === []) {
            return self::result(100, [self::note('ok', 'Keine Fehler gefunden.')]);
        }

        $findings = [];

        foreach (array_slice($errors, 0, 20) as $error) {
            $findings[] = self::note('warn', sprintf(
                '%s: "%s" → "%s"',
                mb_substr((string) ($error['stelle'] ?? ''), 0, 80),
                mb_substr((string) ($error['falsch'] ?? ''), 0, 120),
                mb_substr((string) ($error['richtig'] ?? ''), 0, 120)
            ));
        }

        return self::result(max(0, 100 - count($errors) * 8), $findings);
    }

    // -------------------------------------------------------------- Ergebnisse

    /** @return array<int, array<string, mixed>> */
    public static function latest(int $projectId): array
    {
        $rows = Db::all(
            'SELECT * FROM site_checks WHERE project_id = :p ORDER BY created_at DESC, id DESC',
            ['p' => $projectId]
        );

        $seen = [];
        $out = [];

        foreach ($rows as $row) {
            $kind = (string) $row['kind'];

            if (isset($seen[$kind])) {
                continue;
            }

            $seen[$kind] = true;
            $row['findings'] = json_decode((string) $row['findings'], true) ?: [];
            $out[] = $row;
        }

        return $out;
    }

    private static function store(int $projectId, string $kind, array $result): void
    {
        Db::insert('site_checks', [
            'project_id' => $projectId,
            'kind' => $kind,
            'passed' => $result['passed'] ? 1 : 0,
            'score' => $result['score'],
            'findings' => json_encode($result['findings'], JSON_UNESCAPED_UNICODE),
            'created_at' => Db::now(),
        ]);
    }

    // --------------------------------------------------------------- Kleinkram

    private static function result(int $score, array $findings): array
    {
        $score = max(0, min(100, $score));

        $hasError = false;
        foreach ($findings as $finding) {
            if ($finding['level'] === 'error') {
                $hasError = true;
                break;
            }
        }

        return ['passed' => !$hasError && $score >= 70, 'score' => $score, 'findings' => $findings];
    }

    private static function note(string $level, string $text): array
    {
        return ['level' => $level, 'text' => $text];
    }

    /** @return array<int, string> */
    private static function allFiles(string $directory): array
    {
        if (!is_dir($directory)) {
            return [];
        }

        $out = [];

        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($directory, \FilesystemIterator::SKIP_DOTS)
        );

        foreach ($iterator as $file) {
            if ($file->isFile()) {
                $out[] = $file->getPathname();
            }
        }

        return $out;
    }

    private static function bytes(int $bytes): string
    {
        return $bytes >= 1048576
            ? number_format($bytes / 1048576, 1, ',', "'") . ' MB'
            : number_format(max(0, $bytes) / 1024, 0, ',', "'") . ' KB';
    }
}
