<?php

declare(strict_types=1);

namespace WebAtze\Build;

use RuntimeException;
use WebAtze\Ai\{ClaudeClient, ContentWriter, JsonSchema, Prompts, SitePlanner, Translator};
use WebAtze\Core\{Audit, Config, ConfigurationError, Db, Jobs, Logger};
use WebAtze\Domain\Brief;

/**
 * Der Ablauf vom Formular bis zur fertigen Website.
 *
 * Die Arbeit ist in Schritte zerlegt. Jeder Aufruf von step() erledigt
 * so viel, wie in das übergebene Zeitfenster passt, merkt sich den Stand
 * und kehrt zurück. Der nächste Durchlauf macht weiter.
 *
 * Das ist keine Zier, sondern Notwendigkeit: Auf gemietetem Hosting
 * bricht PHP eine Anfrage nach 30 bis 120 Sekunden ab. Eine Website zu
 * erzeugen dauert länger.
 *
 *   analyse -> plan -> theme -> inhalte -> bilder -> bauen
 *           -> vorschau -> zip -> [hochladen] -> [referenz] -> fertig
 */
final class Pipeline
{
    /** Reihenfolge der Schritte und ihr Anteil am Fortschritt. */
    private const STEPS = [
        'analyse' => ['label' => 'Bestehende Website wird gelesen', 'progress' => 10],
        'plan' => ['label' => 'Aufbau wird geplant', 'progress' => 22],
        'theme' => ['label' => 'Farben und Schriften werden festgelegt', 'progress' => 28],
        'inhalte' => ['label' => 'Texte werden geschrieben', 'progress' => 62],
        'bilder' => ['label' => 'Bilder werden eingesetzt', 'progress' => 70],
        'sprachen' => ['label' => 'Weitere Sprachen werden übersetzt', 'progress' => 76],
        'bauen' => ['label' => 'Website wird zusammengesetzt', 'progress' => 82],
        'pruefen' => ['label' => 'Die fertige Website wird geprüft', 'progress' => 88],
        'vorschau' => ['label' => 'Vorschau wird bereitgestellt', 'progress' => 91],
        'zip' => ['label' => 'Paket wird geschnürt', 'progress' => 96],
        'referenz' => ['label' => 'Referenz wird angelegt', 'progress' => 98],
        'fertig' => ['label' => 'Fertig', 'progress' => 100],
    ];

    /**
     * Einen Schritt des Auftrags ausführen.
     *
     * @param float $budget wie viele Sekunden noch zur Verfügung stehen
     */
    public static function step(array $job, float $budget): void
    {
        $type = (string) $job['type'];

        match ($type) {
            'generate', 'rebuild' => self::generate($job, $budget),
            'zip' => self::zipOnly($job),
            'deploy' => self::deploy($job, $budget),
            default => throw new RuntimeException('Unbekannte Auftragsart: ' . $type),
        };
    }

    // ==================================================================
    // Website erzeugen
    // ==================================================================

    private static function generate(array $job, float $budget): void
    {
        $started = microtime(true);

        $projectId = (int) ($job['project_id'] ?? 0);
        $project = Db::first('SELECT * FROM projects WHERE id = :id', ['id' => $projectId]);

        if ($project === null) {
            Jobs::fail($job['id'], 'Das Projekt wurde inzwischen gelöscht.', false);
            return;
        }

        $state = $job['state'];
        $step = $state['step'] ?? 'analyse';
        $brief = json_decode((string) $project['brief'], true) ?: [];

        while ($step !== 'fertig') {
            // Vor jedem Schritt prüfen, ob noch genug Zeit bleibt.
            $used = microtime(true) - $started;
            if ($used > $budget - 3.0) {
                $state['step'] = $step;
                Jobs::yield($job['id'], $state);
                return;
            }

            $info = self::STEPS[$step] ?? null;
            if ($info === null) {
                throw new RuntimeException('Unbekannter Schritt: ' . $step);
            }

            // Mitschreiben, was gelaufen ist. Steht danach beim Auftrag –
            // sowohl für die Anzeige als auch für den Probelauf, der
            // sonst nicht sehen kann, welche Schritte wirklich dran waren.
            if (!in_array($step, (array) ($state['schritte'] ?? []), true)) {
                $state['schritte'][] = $step;
            }

            Jobs::progress($job['id'], $step, $info['progress'], $info['label'], $state);

            $remaining = $budget - (microtime(true) - $started);

            $state = match ($step) {
                'analyse' => self::stepAnalyse($project, $brief, $state, $remaining, $job),
                'plan' => self::stepPlan($project, $brief, $state, $job),
                'theme' => self::stepTheme($project, $brief, $state),
                'inhalte' => self::stepContent($project, $brief, $state, $remaining, $job),
                'bilder' => self::stepImages($project, $state),
                'sprachen' => self::stepTranslate($project, $state, $remaining, $job),
                'bauen' => self::stepBuild($project, $state),
                'pruefen' => self::stepCheck($project, $state, $remaining),
                'vorschau' => self::stepPreview($project, $state),
                'zip' => self::stepZip($project, $state),
                'referenz' => self::stepShowcase($project, $state),
                default => $state,
            };

            // Ein Schritt darf sich wiederholen – die Texte etwa entstehen
            // Seite für Seite. Er sagt das über 'repeat'. Ohne dieses
            // ausdrückliche Signal geht es immer weiter zum nächsten
            // Schritt; sonst bliebe der Ablauf beim ersten hängen, der
            // seinen Namen im Zustand stehen lässt.
            if (($state['repeat'] ?? false) === true) {
                unset($state['repeat']);
                $repeats = (int) ($state['repeat_count'] ?? 0) + 1;

                // Sicherung gegen einen Schritt, der nie fertig wird.
                if ($repeats > 60) {
                    throw new RuntimeException(
                        'Der Schritt "' . $step . '" kommt nicht zum Ende. Bitte den Auftrag neu starten.'
                    );
                }
                $state['repeat_count'] = $repeats;
            } else {
                $state['repeat_count'] = 0;
                $step = self::nextStep($step);
            }

            $state['step'] = $step;

            // Projekt neu laden – die Schritte ändern es.
            $project = Db::first('SELECT * FROM projects WHERE id = :id', ['id' => $projectId]) ?? $project;
        }

        Db::update('projects', [
            'status' => 'ready',
            'updated_at' => Db::now(),
        ], 'id = :id', ['id' => $projectId]);

        $previewUrl = self::previewUrl($project);

        Jobs::progress($job['id'], 'fertig', 100, 'Fertig.', $state);
        Jobs::finish($job['id'], 'Die Website ist fertig.');

        Db::update('jobs', ['state' => array_merge($state, ['redirect' => $previewUrl])],
            'id = :id', ['id' => (int) $job['id']]);

        Audit::log('project.built', (string) $project['name'], ['id' => $projectId]);
    }

    private static function nextStep(string $current): string
    {
        $keys = array_keys(self::STEPS);
        $index = array_search($current, $keys, true);

        return $index === false || $index + 1 >= count($keys)
            ? 'fertig'
            : $keys[$index + 1];
    }

    // ------------------------------------------------------------------
    // Schritt 1: bestehende Website lesen
    // ------------------------------------------------------------------

    private static function stepAnalyse(array $project, array $brief, array $state, float $budget, array $job): array
    {
        $url = trim((string) ($brief['old_url'] ?? ''));

        if ($url === '') {
            $state['old_site'] = null;
            return $state;
        }

        $importer = new OldSiteImporter($url);
        $crawl = $importer->crawl(max(8.0, min($budget - 5.0, 40.0)));

        $state['old_site'] = [
            'summary' => OldSiteImporter::summarise($crawl),
            'colors' => $crawl['colors'],
            'pages' => count($crawl['pages']),
            'errors' => array_slice($crawl['errors'], 0, 5),
        ];

        // Bilder herunterladen, wenn gewünscht
        if (!empty($brief['take_images']) && $crawl['images'] !== []) {
            $saved = OldSiteImporter::fetchImages(
                $crawl['images'],
                (int) $project['id'],
                (string) $project['slug'],
                24
            );
            $state['images_imported'] = $saved;
            Logger::info('Bilder übernommen', ['projekt' => (int) $project['id'], 'anzahl' => $saved]);
        }

        if ($crawl['pages'] === []) {
            // Kein Grund zum Abbruch – die Website entsteht dann eben
            // allein aus den Angaben im Formular.
            Logger::warning('Alte Website nicht lesbar', ['url' => $url, 'fehler' => $crawl['errors']]);
            $state['old_site']['summary'] = '';
        }

        return $state;
    }

    // ------------------------------------------------------------------
    // Schritt 2: Aufbau planen
    // ------------------------------------------------------------------

    private static function stepPlan(array $project, array $brief, array $state, array $job): array
    {
        $planner = new SitePlanner((int) $project['id'], (int) $job['id']);
        $plan = $planner->plan($brief, (string) ($state['old_site']['summary'] ?? ''));

        Db::update('projects', [
            'plan' => $plan,
            'updated_at' => Db::now(),
        ], 'id = :id', ['id' => (int) $project['id']]);

        $state['plan'] = $plan;
        $state['page_index'] = 0;

        return $state;
    }

    // ------------------------------------------------------------------
    // Schritt 3: Farben und Schriften
    // ------------------------------------------------------------------

    private static function stepTheme(array $project, array $brief, array $state): array
    {
        $mode = (string) ($brief['color_mode'] ?? 'auto');
        $wishes = [];

        if ($mode === 'manual') {
            $wishes = [
                'primary' => (string) ($brief['color_primary'] ?? ''),
                'secondary' => (string) ($brief['color_secondary'] ?? ''),
                'accent' => (string) ($brief['color_accent'] ?? ''),
            ];
        } elseif ($mode === 'from_old') {
            $colors = $state['old_site']['colors'] ?? [];
            $wishes = [
                'primary' => (string) ($colors[0]['hex'] ?? ''),
                'secondary' => (string) ($colors[1]['hex'] ?? ''),
            ];
        }

        $theme = Theme::build($wishes, (string) ($brief['style'] ?? 'clean'));

        Db::update('projects', [
            'theme' => $theme,
            'updated_at' => Db::now(),
        ], 'id = :id', ['id' => (int) $project['id']]);

        $state['theme_report'] = $theme['report'];

        return $state;
    }

    // ------------------------------------------------------------------
    // Schritt 4: Texte schreiben, Seite für Seite
    // ------------------------------------------------------------------

    private static function stepContent(array $project, array $brief, array $state, float $budget, array $job): array
    {
        $plan = $state['plan'] ?? json_decode((string) $project['plan'], true) ?: [];
        $pages = $plan['pages'] ?? [];
        $index = (int) ($state['page_index'] ?? 0);

        if ($pages === []) {
            throw new RuntimeException('Der Plan enthält keine Seiten.');
        }

        // Beim ersten Durchlauf die alten Seiten wegraeumen - aber nur
        // dann. Wird mitten in der ersten Seite fortgesetzt (Seite 0,
        // Gruppe 1), steht der Zaehler ebenfalls auf null; wegraeumen
        // wuerde genau das loeschen, was gerade bezahlt wurde.
        if ($index === 0 && (int) ($state['group_index'] ?? 0) === 0) {
            self::clearPages((int) $project['id']);
        }

        $writer = new ContentWriter((int) $project['id'], (int) $job['id']);
        $started = microtime(true);
        $gruppe = (int) ($state['group_index'] ?? 0);
        $getanNow = 0;

        while ($index < count($pages)) {
            $page = $pages[$index];
            $gruppen = $writer->groupsFor($page);
            $anzahl = max(1, count($gruppen));

            // Eine Gruppe dauert 15 bis 35 Sekunden. Wer weniger Zeit
            // hat, hoert auf und macht im naechsten Durchlauf weiter -
            // bei genau dieser Gruppe, nicht am Anfang der Seite.
            //
            // Entscheidend ist "in DIESEM Durchlauf schon etwas
            // geschafft". Bei einem knappen Zeitfenster waere die
            // Bedingung sonst sofort erfuellt, es kaeme nie etwas dazu,
            // und der Schritt liefe endlos im Kreis.
            if ($getanNow > 0 && microtime(true) - $started > $budget - 35.0) {
                break;
            }

            // Anteil der Seiten plus Anteil der Gruppen innerhalb der
            // Seite - sonst steht die Anzeige minutenlang still.
            $anteil = ($index + min($gruppe, $anzahl) / $anzahl) / max(1, count($pages));
            $percent = 28 + (int) round(34 * $anteil);

            $wo = sprintf('Texte fuer "%s" (%d von %d)', (string) ($page['title'] ?? ''), $index + 1, count($pages));

            if ($anzahl > 1) {
                $wo .= sprintf(', Teil %d von %d', min($gruppe + 1, $anzahl), $anzahl);
            }

            Jobs::progress(
                (int) $job['id'],
                'inhalte',
                $percent,
                $wo,
                array_merge($state, ['page_index' => $index, 'group_index' => $gruppe])
            );

            // Waechter: Vor jeder Gruppe festhalten, dass sie begonnen
            // wurde. Kommt dieselbe Gruppe zum vierten Mal dran, ohne je
            // fertig geworden zu sein, bricht der Server den Vorgang
            // immer an derselben Stelle ab. Weiterprobieren kostet dann
            // nur noch Geld - besser einmal deutlich sagen, woran es
            // liegt.
            $marke = $index . ':' . $gruppe;
            $versuche = (int) ($state['gruppe_versuche'][$marke] ?? 0) + 1;
            $state['gruppe_versuche'] = [$marke => $versuche];

            if ($versuche > 3) {
                throw new ConfigurationError(
                    'Der Server bricht das Schreiben immer an derselben Stelle ab '
                    . '(Seite ' . ($index + 1) . ', Teil ' . ($gruppe + 1) . '). '
                    . 'Das passiert, wenn der Cronjob die Adresse per curl oder wget '
                    . 'aufruft: Dann gilt die Zeitgrenze des Webservers, und die ist '
                    . 'kuerzer als eine Anfrage an die KI dauert.',
                    'Stelle den Cronjob in cPanel auf die Kommandozeile um. Die Zeile '
                    . 'lautet: /usr/local/bin/php -q ' . BASE_DIR . '/worker.php '
                    . '- also ohne curl und ohne Adresse. Danach den Auftrag '
                    . 'fortsetzen; das bereits Geschriebene bleibt erhalten.',
                    ''
                );
            }

            // Die Sperre auffrischen. Ein Aufruf dauert laenger als die
            // Sperre; ohne das koennte ein zweiter Arbeiter mitten hinein
            // denselben Auftrag greifen und alles doppelt bezahlen.
            Jobs::keepAlive((int) $job['id']);

            // Die Seitenzeile zuerst - dann haengt jede Gruppe an einer
            // Stelle, die es schon gibt, auch nach einem Abbruch.
            $pageId = $writer->preparePage((int) $project['id'], $page);

            $writer->writeGroup(
                (int) $project['id'],
                $pageId,
                $page,
                $brief,
                (string) ($state['old_site']['summary'] ?? ''),
                $gruppe
            );

            // Geschafft - der Zaehler des Waechters darf zurueck.
            $state['gruppe_versuche'] = [];

            $getanNow++;
            $gruppe++;

            if ($gruppe >= $anzahl) {
                $index++;
                $gruppe = 0;
            }
        }

        $state['group_index'] = $gruppe;
        $state['page_index'] = $index;

        // Noch nicht alle Seiten geschrieben: denselben Schritt wiederholen.
        if ($index < count($pages)) {
            $state['repeat'] = true;
        }

        return $state;
    }

    // ------------------------------------------------------------------
    // Schritt 4b: Die weiteren Sprachen
    // ------------------------------------------------------------------

    /**
     * Seite für Seite und Sprache für Sprache übersetzen.
     *
     * Mit demselben Zeitbudget wie beim Schreiben: Ein Auftrag darf
     * nicht ins Zeitlimit des Hostings laufen, nur weil eine Website
     * drei Sprachen hat.
     */
    private static function stepTranslate(array $project, array $state, float $budget, array $job): array
    {
        $extra = Translator::extraLocales($project);

        if ($extra === []) {
            return $state;
        }

        $pages = Db::all(
            'SELECT id, title FROM project_pages WHERE project_id = :p ORDER BY sort_order ASC, id ASC',
            ['p' => (int) $project['id']]
        );

        $done = (array) ($state['translated'] ?? []);
        $started = microtime(true);
        $translator = new Translator((int) $project['id'], (int) $job['id']);
        $didSomething = false;

        foreach ($pages as $page) {
            foreach ($extra as $locale) {
                $key = $page['id'] . ':' . $locale;

                if (in_array($key, $done, true)) {
                    continue;
                }

                // Mindestens eine Übersetzung je Durchgang, danach nur
                // weiter, solange Zeit bleibt.
                if ($didSomething && microtime(true) - $started > $budget - 25.0) {
                    $state['translated'] = $done;
                    $state['repeat'] = true;
                    return $state;
                }

                Jobs::progress(
                    (int) $job['id'],
                    'sprachen',
                    self::STEPS['sprachen']['progress'],
                    sprintf('%s auf %s', (string) $page['title'], Translator::NAMES[$locale] ?? $locale)
                );

                $translator->translatePage((int) $page['id'], $locale);

                $done[] = $key;
                $didSomething = true;
            }
        }

        $state['translated'] = $done;

        return $state;
    }

    // ------------------------------------------------------------------
    // Schritt 5: Bilder auf die Abschnitte verteilen
    // ------------------------------------------------------------------

    private static function stepImages(array $project, array $state): array
    {
        $assigned = ImagePlacer::place((int) $project['id']);
        $state['images_placed'] = $assigned;

        return $state;
    }

    // ------------------------------------------------------------------
    // Schritt 6: Website bauen
    // ------------------------------------------------------------------

    private static function stepBuild(array $project, array $state): array
    {
        $dist = STORAGE_DIR . '/projects/' . (string) $project['slug'] . '/dist';
        delete_tree($dist);

        $builder = new SiteBuilder($project, $dist);
        $result = $builder->build();

        $state['build'] = $result;

        return $state;
    }

    // ------------------------------------------------------------------
    // Schritt 7: die fertige Website prüfen
    // ------------------------------------------------------------------

    /**
     * Geprüft wird das Ergebnis, nicht der Plan.
     *
     * Eine Prüfung darf den Auftrag nie scheitern lassen: Der Kunde
     * bekäme sonst wegen eines Tippfehlers gar keine Website. Was
     * auffällt, steht danach beim Projekt – dort, wo man es liest,
     * bevor man die Seite freigibt.
     */
    private static function stepCheck(array $project, array $state, float $budget): array
    {
        try {
            $state['checks'] = SiteChecker::runAll($project, max(5.0, $budget - 5.0));
        } catch (\Throwable $e) {
            Logger::info('Prüfung übersprungen: ' . $e->getMessage());
            $state['checks'] = [];
        }

        return $state;
    }

    // ------------------------------------------------------------------
    // Schritt 8: Vorschau bereitstellen
    // ------------------------------------------------------------------

    private static function stepPreview(array $project, array $state): array
    {
        $token = (string) ($project['preview_token'] ?? '');
        if ($token === '') {
            $token = random_token(18);
        }

        $target = STORAGE_DIR . '/previews/' . $token;
        delete_tree($target);
        ensure_dir($target);

        self::copyTree(STORAGE_DIR . '/projects/' . (string) $project['slug'] . '/dist', $target);

        $hours = (int) Config::get('preview_ttl_hours', 24);

        Db::update('projects', [
            'preview_token' => $token,
            'preview_expires_at' => date('Y-m-d H:i:s', time() + $hours * 3600),
            'updated_at' => Db::now(),
        ], 'id = :id', ['id' => (int) $project['id']]);

        $state['preview_token'] = $token;

        return $state;
    }

    // ------------------------------------------------------------------
    // Schritt 9: ZIP
    // ------------------------------------------------------------------

    private static function stepZip(array $project, array $state): array
    {
        $result = ZipExporter::create($project);
        $state['zip'] = $result;

        return $state;
    }

    /**
     * Die fertige Website in die Referenzen aufnehmen.
     *
     * Der Text entsteht dabei automatisch – und verrät nicht, wie die
     * Website entstanden ist. Der Eintrag bleibt zunächst unsichtbar:
     * Kein Kunde soll auf der Website auftauchen, bevor er die Seite
     * überhaupt gesehen hat. Sichtbar schalten geht mit einem Klick.
     *
     * Scheitert das, ist die Website trotzdem fertig. Eine fehlende
     * Referenz darf keinen Auftrag zum Scheitern bringen.
     */
    private static function stepShowcase(array $project, array $state): array
    {
        try {
            $entry = ShowcaseBuilder::create($project, false);
            $state['referenz'] = ['id' => (int) ($entry['id'] ?? 0)];
        } catch (\Throwable $e) {
            Logger::warning('Die Referenz konnte nicht angelegt werden: ' . $e->getMessage(), [
                'projekt' => (int) $project['id'],
            ]);
            $state['referenz'] = ['fehler' => $e->getMessage()];
        }

        return $state;
    }

    // ==================================================================
    // Andere Auftragsarten
    // ==================================================================

    private static function zipOnly(array $job): void
    {
        $project = Db::first('SELECT * FROM projects WHERE id = :id', ['id' => (int) $job['project_id']]);
        if ($project === null) {
            Jobs::fail($job['id'], 'Projekt nicht gefunden.', false);
            return;
        }

        Jobs::progress($job['id'], 'zip', 50, 'Paket wird geschnürt …');
        $result = ZipExporter::create($project);

        Jobs::progress($job['id'], 'fertig', 100, 'Paket ist fertig.', ['zip' => $result]);
        Jobs::finish($job['id'], sprintf('Paket erstellt (%s).', format_bytes((int) $result['bytes'])));
    }

    private static function deploy(array $job, float $budget): void
    {
        $project = Db::first('SELECT * FROM projects WHERE id = :id', ['id' => (int) $job['project_id']]);
        if ($project === null) {
            Jobs::fail($job['id'], 'Projekt nicht gefunden.', false);
            return;
        }

        Jobs::progress($job['id'], 'hochladen', 20, 'Verbindung wird aufgebaut …');

        $result = FtpDeployer::deploy($project, static function (int $done, int $total, string $file) use ($job): void {
            $percent = 20 + (int) round(70 * ($total > 0 ? $done / $total : 0));
            Jobs::progress($job['id'], 'hochladen', $percent, sprintf('%d von %d Dateien', $done, $total));
        }, $budget - 5.0);

        if (!$result['ok']) {
            Jobs::fail($job['id'], $result['error'], $result['retryable'] ?? true);
            return;
        }

        Jobs::progress($job['id'], 'fertig', 100, 'Hochgeladen.', ['deploy' => $result]);
        Jobs::finish($job['id'], sprintf('%d Dateien hochgeladen.', $result['files']));

        Db::update('projects', [
            'status' => 'live',
            'published_at' => Db::now(),
            'updated_at' => Db::now(),
        ], 'id = :id', ['id' => (int) $project['id']]);

        Audit::log('project.deployed', (string) $project['name'], ['dateien' => $result['files']]);
    }

    // ==================================================================
    // Helfer
    // ==================================================================

    /** Alte Seiten und Abschnitte entfernen, bevor neu geschrieben wird. */
    private static function clearPages(int $projectId): void
    {
        Db::transaction(static function () use ($projectId): void {
            Db::delete('project_sections', 'project_id = :p', ['p' => $projectId]);
            Db::delete('project_pages', 'project_id = :p', ['p' => $projectId]);
        });
    }

    public static function previewUrl(array $project): string
    {
        $token = (string) ($project['preview_token'] ?? '');
        return $token === '' ? '' : '/vorschau/' . $token . '/';
    }

    /** Verzeichnis samt Inhalt kopieren. */
    public static function copyTree(string $from, string $to): int
    {
        if (!is_dir($from)) {
            return 0;
        }

        ensure_dir($to);
        $count = 0;

        $items = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($from, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::SELF_FIRST
        );

        foreach ($items as $item) {
            /** @var \SplFileInfo $item */
            $relative = substr($item->getPathname(), strlen($from) + 1);
            $target = $to . '/' . $relative;

            if ($item->isDir()) {
                ensure_dir($target);
            } elseif ($item->isFile()) {
                ensure_dir(dirname($target));
                if (@copy($item->getPathname(), $target)) {
                    $count++;
                }
            }
        }

        return $count;
    }
}
