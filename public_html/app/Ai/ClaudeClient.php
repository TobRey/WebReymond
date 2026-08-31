<?php

declare(strict_types=1);

namespace WebAtze\Ai;

use RuntimeException;
use WebAtze\Core\{Config, ConfigurationError, Db, Logger, Settings};

/**
 * Anbindung an die Claude-API von Anthropic.
 *
 * Bewusst mit cURL statt mit einer fertigen Bibliothek: auf gemietetem
 * Hosting lässt sich Composer oft nicht ausführen. cURL ist überall da.
 *
 * Drei Dinge, die hier wichtig sind:
 *
 *   1. Strukturierte Antworten. Die API bekommt ein JSON-Schema mit und
 *      liefert garantiert passende Daten – kein Herausfischen aus Fliesstext.
 *   2. Zwischenspeicherung. Der grosse, immer gleiche Teil der Anweisung
 *      wird von Anthropic zwischengespeichert. Ein zweiter Aufruf mit
 *      demselben Anfang kostet dafür nur einen Bruchteil.
 *   3. Kostenprotokoll. Jeder Aufruf wird mit Tokenzahlen und errechneten
 *      Kosten festgehalten und ist im Adminbereich einsehbar.
 */
final class ClaudeClient
{
    private const ENDPOINT = 'https://api.anthropic.com/v1/messages';

    /** Dort stehen die Arbeitsbereiche der Organisation. */
    private const WORKSPACES_ENDPOINT = 'https://api.anthropic.com/v1/organizations/workspaces';

    private const VERSION = '2023-06-01';
    private const MAX_RETRIES = 3;

    /**
     * Womit es sich noch versuchen lässt, wenn nichts anderes bleibt.
     *
     * Wer nur den Standardbereich hat, findet in der Konsole nirgends
     * eine "wrkspc_"-Kennung – dort heisst er einfach "default".
     * Geraten wird trotzdem nicht: Jeder Kandidat wird erst geprüft und
     * nur bei einer bestätigten Antwort übernommen.
     */
    private const LAST_RESORT = ['default'];

    /**
     * Preise in US-Dollar je einer Million Token (Stand Juni 2026).
     *
     * Zwischengespeicherte Eingaben kosten weniger: das Anlegen etwa das
     * 1.25-fache, das Wiederverwenden etwa ein Zehntel des Eingabepreises.
     */
    private const PRICING = [
        'claude-opus-5'    => ['in' => 5.00, 'out' => 25.00],
        'claude-fable-5'   => ['in' => 10.00, 'out' => 50.00],
        'claude-sonnet-5'  => ['in' => 2.00, 'out' => 10.00],
        'claude-haiku-4-5' => ['in' => 1.00, 'out' => 5.00],
    ];

    private const CACHE_WRITE_FACTOR = 1.25;
    private const CACHE_READ_FACTOR = 0.10;

    private ?int $projectId = null;
    private ?int $jobId = null;

    public function forProject(?int $projectId, ?int $jobId = null): self
    {
        $this->projectId = $projectId;
        $this->jobId = $jobId;
        return $this;
    }

    public static function isConfigured(): bool
    {
        return trim((string) Config::get('anthropic.api_key', '')) !== '';
    }

    /**
     * Eine Frage mit fest vorgegebener Antwortform stellen.
     *
     * @param string $purpose     wofür – erscheint im Kostenprotokoll
     * @param string $system      die dauerhafte Anweisung (wird zwischengespeichert)
     * @param string $userMessage die eigentliche Aufgabe
     * @param array  $schema      JSON-Schema der erwarteten Antwort
     * @param array  $options     model, effort, max_tokens
     *
     * @return array die Antwort, bereits geprüft und als Array
     */
    public function structured(
        string $purpose,
        string $system,
        string $userMessage,
        array $schema,
        array $options = []
    ): array {
        $model = $options['model'] ?? (string) Config::get('anthropic.model_content', 'claude-sonnet-5');

        $payload = [
            'model' => $model,
            'max_tokens' => (int) ($options['max_tokens'] ?? 16000),

            // Der Systemteil ist bei jedem Aufruf derselbe. Die Markierung
            // sagt Anthropic: das darfst du zwischenspeichern.
            'system' => [[
                'type' => 'text',
                'text' => $system,
                'cache_control' => ['type' => 'ephemeral'],
            ]],

            'messages' => [[
                'role' => 'user',
                'content' => $userMessage,
            ]],

            'output_config' => [
                'format' => [
                    'type' => 'json_schema',
                    'schema' => self::sealSchema($schema),
                ],
                'effort' => $options['effort'] ?? 'high',
            ],
        ];

        $response = $this->send($payload, $purpose);

        $text = self::firstText($response);
        if ($text === '') {
            throw new RuntimeException('Die KI hat keine verwertbare Antwort geliefert.');
        }

        $data = json_decode($text, true);
        if (!is_array($data)) {
            Logger::warning('Antwort war kein gültiges JSON', ['purpose' => $purpose]);
            throw new RuntimeException('Die Antwort der KI war unvollständig. Bitte noch einmal versuchen.');
        }

        return $data;
    }

    /** Freie Textantwort, z.B. für einen Referenztext. */
    public function text(
        string $purpose,
        string $system,
        string $userMessage,
        array $options = []
    ): string {
        $payload = [
            'model' => $options['model'] ?? (string) Config::get('anthropic.model_content', 'claude-sonnet-5'),
            'max_tokens' => (int) ($options['max_tokens'] ?? 4000),
            'system' => [[
                'type' => 'text',
                'text' => $system,
                'cache_control' => ['type' => 'ephemeral'],
            ]],
            'messages' => [['role' => 'user', 'content' => $userMessage]],
            'output_config' => ['effort' => $options['effort'] ?? 'medium'],
        ];

        return self::firstText($this->send($payload, $purpose));
    }

    /**
     * Anfrage abschicken, mit Wiederholung bei vorübergehenden Störungen.
     */
    private function send(array $payload, string $purpose): array
    {
        $apiKey = trim((string) Config::get('anthropic.api_key', ''));
        if ($apiKey === '') {
            throw new RuntimeException(
                'Es ist kein Anthropic-Schlüssel hinterlegt. '
                . 'Er gehört in app/config.php unter anthropic.api_key.'
            );
        }

        $timeout = (int) Config::get('anthropic.timeout', 120);
        $lastError = '';

        for ($attempt = 1; $attempt <= self::MAX_RETRIES; $attempt++) {
            $started = microtime(true);

            $result = $this->request($payload, $apiKey, $timeout);
            $duration = (int) round((microtime(true) - $started) * 1000);

            // Erfolg
            if ($result['status'] >= 200 && $result['status'] < 300) {
                $data = $result['data'];

                // Der Arbeitsbereich hat sich bewährt – ab jetzt ist er
                // keine Vermutung mehr, sondern die Einstellung.
                if (self::wasGuessed()) {
                    self::remember('anthropic_workspace_guessed', '');
                }

                $this->record($purpose, $payload['model'], $data['usage'] ?? [], $duration, true);

                // Die Sicherheitsprüfung kann eine Anfrage ablehnen. Das ist
                // kein Fehler im technischen Sinn – die Antwort kommt mit
                // Status 200, enthält aber keinen Inhalt.
                if (($data['stop_reason'] ?? '') === 'refusal') {
                    $category = $data['stop_details']['category'] ?? 'unbekannt';
                    Logger::warning('Anfrage wurde abgelehnt', ['grund' => $category, 'zweck' => $purpose]);
                    throw new RuntimeException(
                        'Die KI hat diese Anfrage abgelehnt. Bitte die Beschreibung im Formular anpassen.'
                    );
                }

                if (($data['stop_reason'] ?? '') === 'max_tokens') {
                    Logger::warning('Antwort war zu lang und wurde abgeschnitten', ['zweck' => $purpose]);
                }

                return $data;
            }

            // Fehler
            $status = $result['status'];
            $apiMessage = $result['data']['error']['message'] ?? $result['error'];
            $lastError = sprintf('HTTP %d: %s', $status, $apiMessage);

            $this->record($purpose, $payload['model'], [], $duration, false);

            // Fehlt der Arbeitsbereich, versuchen wir ihn selbst zu finden
            // und sofort weiterzumachen. Klappt das, merkt niemand etwas
            // davon – und beim nächsten Mal steht er schon da.
            if (self::isWorkspaceMissing($status, $apiMessage)) {
                if (self::healWorkspace($apiKey)) {
                    Logger::info('Arbeitsbereich selbst ermittelt, Anfrage wird wiederholt.');
                    continue;
                }

                throw self::workspaceError($apiMessage);
            }

            // 4xx ausser 408 und 429 sind unsere Schuld – ein zweiter
            // Versuch mit denselben Daten scheitert genauso.
            $retryable = $status === 0 || $status === 408 || $status === 429 || $status >= 500;

            if (!$retryable || $attempt === self::MAX_RETRIES) {
                Logger::error('Claude-Anfrage endgültig fehlgeschlagen', [
                    'status' => $status,
                    'zweck' => $purpose,
                ]);

                // Ein 4xx ist eine falsche Anfrage oder eine falsche
                // Einstellung. Beides behebt sich durch Warten nicht –
                // der Auftrag hält an, statt es alle halbe Minute erneut
                // zu versuchen.
                if ($status >= 400 && $status < 500 && $status !== 408 && $status !== 429) {
                    throw new ConfigurationError(
                        self::friendlyError($status, $apiMessage),
                        self::remedyFor($status),
                        $status === 401 || $status === 403 ? 'anthropic_api_key' : ''
                    );
                }

                throw new RuntimeException(self::friendlyError($status, $apiMessage));
            }

            // Wartezeit verdoppelt sich, plus ein wenig Zufall, damit nicht
            // alle gleichzeitig wieder anklopfen.
            $wait = min(30, (2 ** $attempt)) + (random_int(0, 400) / 1000);
            Logger::warning('Claude-Anfrage wird wiederholt', [
                'status' => $status, 'versuch' => $attempt, 'wartet' => $wait,
            ]);
            usleep((int) ($wait * 1_000_000));
        }

        throw new RuntimeException($lastError !== '' ? $lastError : 'Die KI war nicht erreichbar.');
    }

    /** @return array{status:int, data:array, error:string} */
    private function request(array $payload, string $apiKey, int $timeout): array
    {
        $ch = curl_init();

        $headers = [
            'Content-Type: application/json',
            'x-api-key: ' . $apiKey,
            'anthropic-version: ' . self::VERSION,
        ];

        // Ein identitätsgebundener Schlüssel gehört keinem Arbeitsbereich,
        // sondern einer Person. Die API weiss dann nicht, für welchen
        // Bereich sie abrechnen soll, und lehnt ab. Diese Kopfzeile sagt
        // es ihr. Bei einem gewöhnlichen Schlüssel stört sie nicht.
        $workspace = self::workspaceId();

        if ($workspace !== '') {
            $headers[] = 'anthropic-workspace-id: ' . $workspace;
        }

        curl_setopt_array($ch, [
            CURLOPT_URL => self::ENDPOINT,
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => $timeout,
            CURLOPT_CONNECTTIMEOUT => 15,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
            CURLOPT_HTTPHEADER => $headers,
        ]);

        $raw = curl_exec($ch);
        $status = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        $error = curl_error($ch);
        curl_close($ch);

        if ($raw === false) {
            return ['status' => 0, 'data' => [], 'error' => $error ?: 'Verbindung fehlgeschlagen.'];
        }

        $decoded = json_decode((string) $raw, true);

        return [
            'status' => $status,
            'data' => is_array($decoded) ? $decoded : [],
            'error' => is_array($decoded) ? '' : 'Antwort war kein JSON.',
        ];
    }

    /** Ersten Textblock aus der Antwort holen. */
    private static function firstText(array $response): string
    {
        foreach ($response['content'] ?? [] as $block) {
            if (($block['type'] ?? '') === 'text' && ($block['text'] ?? '') !== '') {
                return (string) $block['text'];
            }
        }
        return '';
    }

    /**
     * Sorgt dafür, dass jedes Objekt im Schema geschlossen ist.
     *
     * Ohne additionalProperties:false dürfte die Antwort zusätzliche Felder
     * enthalten, mit denen der Aufbau später nichts anfangen kann.
     */
    private static function sealSchema(array $schema): array
    {
        if (($schema['type'] ?? '') === 'object') {
            $schema['additionalProperties'] = false;

            if (!isset($schema['required']) && isset($schema['properties'])) {
                $schema['required'] = array_keys($schema['properties']);
            }

            foreach ($schema['properties'] ?? [] as $key => $property) {
                if (is_array($property)) {
                    $schema['properties'][$key] = self::sealSchema($property);
                }
            }
        }

        if (($schema['type'] ?? '') === 'array' && isset($schema['items']) && is_array($schema['items'])) {
            $schema['items'] = self::sealSchema($schema['items']);
        }

        return $schema;
    }

    /**
     * Aufruf im Protokoll festhalten.
     *
     * Die Kosten werden in Millionstel-Dollar gespeichert, damit keine
     * Rundungsfehler entstehen. Ein Fehlschlag wird ebenfalls vermerkt –
     * sonst fehlt später die Erklärung für eine Lücke.
     */
    private function record(string $purpose, string $model, array $usage, int $durationMs, bool $ok): void
    {
        $input = (int) ($usage['input_tokens'] ?? 0);
        $output = (int) ($usage['output_tokens'] ?? 0);
        $cacheWrite = (int) ($usage['cache_creation_input_tokens'] ?? 0);
        $cacheRead = (int) ($usage['cache_read_input_tokens'] ?? 0);

        try {
            Db::insert('ai_calls', [
                'project_id' => $this->projectId,
                'job_id' => $this->jobId,
                'purpose' => mb_substr($purpose, 0, 40),
                'model' => mb_substr($model, 0, 60),
                'input_tokens' => $input,
                'cache_write_tokens' => $cacheWrite,
                'cache_read_tokens' => $cacheRead,
                'output_tokens' => $output,
                'cost_micro' => self::costMicroDollars($model, $input, $cacheWrite, $cacheRead, $output),
                'duration_ms' => $durationMs,
                'ok' => $ok ? 1 : 0,
                'created_at' => Db::now(),
            ]);
        } catch (\Throwable $e) {
            Logger::warning('Kostenprotokoll konnte nicht geschrieben werden: ' . $e->getMessage());
        }
    }

    /** Kosten eines Aufrufs in Millionstel-Dollar. */
    public static function costMicroDollars(
        string $model,
        int $input,
        int $cacheWrite,
        int $cacheRead,
        int $output
    ): int {
        $price = self::PRICING[$model] ?? self::PRICING['claude-sonnet-5'];

        $dollars =
            ($input / 1_000_000) * $price['in']
            + ($cacheWrite / 1_000_000) * $price['in'] * self::CACHE_WRITE_FACTOR
            + ($cacheRead / 1_000_000) * $price['in'] * self::CACHE_READ_FACTOR
            + ($output / 1_000_000) * $price['out'];

        return (int) round($dollars * 1_000_000);
    }

    // ==================================================================
    // Der Arbeitsbereich
    // ==================================================================

    /**
     * Die hinterlegte Arbeitsbereich-Kennung.
     *
     * Zwei Orte, mit Absicht in dieser Reihenfolge: Was in config.php
     * steht, gilt. Sonst das, was im Adminbereich eingetragen wurde –
     * denn dorthin kommt man ohne FTP-Zugang, und das zählt, wenn
     * gerade nichts geht.
     */
    public static function workspaceId(): string
    {
        $fromConfig = trim((string) Config::get('anthropic.workspace_id', ''));

        if ($fromConfig !== '') {
            return $fromConfig;
        }

        try {
            return trim(Settings::get('anthropic_workspace_id', ''));
        } catch (\Throwable) {
            // Ohne Datenbank – etwa im Testlauf – ist das kein Fehler.
            return '';
        }
    }

    /**
     * Aus dem, was jemand einfügt, die Kennung herauslesen.
     *
     * Wer die Kennung sucht, findet sie in der Adresszeile – und fügt
     * dann meistens die ganze Adresse ein. Sie daraufhin abzuweisen
     * wäre kleinlich: Was gemeint ist, steht ja da.
     */
    public static function cleanWorkspaceId(string $value): string
    {
        $value = trim($value);

        if ($value === '') {
            return '';
        }

        if (preg_match('/(wrkspc_[A-Za-z0-9]+)/', $value, $m) === 1) {
            return $m[1];
        }

        // Etwas anderes, aber plausibel Kennungsartiges lassen wir durch –
        // die Schnittstelle entscheidet, nicht wir.
        return preg_match('/^[A-Za-z0-9_-]{6,120}$/', $value) === 1 ? $value : '';
    }

    /**
     * Ist das der Fehler, dem die Arbeitsbereich-Kennung fehlt?
     *
     * Geprüft wird nicht nur der Wortlaut: Die API könnte ihn ändern.
     * Deshalb reicht schon der Fehlercode, und der Wortlaut ist der
     * zweite Weg.
     */
    public static function isWorkspaceMissing(int $status, string $message): bool
    {
        if ($status !== 400) {
            return false;
        }

        $needle = mb_strtolower($message);

        return str_contains($needle, 'workspace_id_required')
            || (str_contains($needle, 'anthropic-workspace-id') && str_contains($needle, 'required'))
            || (str_contains($needle, 'workspace') && str_contains($needle, 'identity-linked'));
    }

    /**
     * Den Arbeitsbereich selbst herausfinden und merken.
     *
     * Ein identitätsgebundener Schlüssel gehört einer Person, und die
     * gehört zu einer Organisation. Die Liste der Arbeitsbereiche
     * beantwortet die Frage also oft selbst. Gibt es genau einen, ist
     * die Sache eindeutig – dann wird er eingetragen und weitergemacht,
     * ohne dass jemand etwas tun muss.
     *
     * Gibt es mehrere, wird nicht geraten. Auf den falschen Bereich zu
     * buchen wäre schlimmer als eine Rückfrage.
     *
     * @return bool ob es geklappt hat
     */
    public static function healWorkspace(string $apiKey): bool
    {
        // Steht schon einer da, half er offensichtlich nicht.
        if (self::workspaceId() !== '') {
            Logger::warning('Der hinterlegte Arbeitsbereich wird abgewiesen.', [
                'kennung' => self::workspaceId(),
            ]);

            // War er nur unsere Vermutung, hat sie sich soeben erledigt.
            // Sie stehen zu lassen würde die Einstellungen mit etwas
            // füllen, das nachweislich nicht stimmt.
            if (self::wasGuessed()) {
                self::remember('anthropic_workspace_id', '');
                self::remember('anthropic_workspace_guessed', '');
                Logger::info('Die Vermutung wurde wieder entfernt.');
            }

            return false;
        }

        // Hat die Abfrage schon einmal mit "darfst du nicht" geantwortet,
        // wird sie das wieder tun. Ein zweiter Versuch bei jedem Auftrag
        // kostet nur Zeit.
        if (self::rememberedAsHopeless()) {
            return false;
        }

        $found = self::listWorkspaces($apiKey);

        if (count($found) !== 1) {
            if ($found !== []) {
                Logger::warning('Mehrere Arbeitsbereiche gefunden – hier wird nicht geraten.', [
                    'anzahl' => count($found),
                ]);
                // Zur Auswahl merken, damit die Oberfläche sie anbieten kann.
                self::remember('anthropic_workspace_choices', json_encode($found, JSON_UNESCAPED_UNICODE));

                return false;
            }

            // Nachschlagen ging nicht. Bleibt ein letzter Versuch: Wer nur
            // den Standardbereich hat, findet nirgends eine Kennung – der
            // heisst in der Konsole schlicht "default".
            //
            // Geprüft wird das an der Anfrage, die gerade gescheitert ist,
            // nicht an einer anderen: Nur die beantwortet die Frage
            // wirklich. Deshalb wird der Wert vorläufig gesetzt und als
            // Vermutung markiert. Bewährt er sich, bleibt er; scheitert
            // er, wird er wieder entfernt – ein falscher Wert, der still
            // in den Einstellungen stehen bleibt, wäre schlimmer als
            // keiner.
            $candidate = self::LAST_RESORT[0];

            self::remember('anthropic_workspace_id', $candidate);
            self::remember('anthropic_workspace_guessed', '1');

            Logger::info('Standard-Arbeitsbereich wird versuchsweise angenommen.', [
                'kennung' => $candidate,
            ]);

            return true;
        }

        $id = (string) ($found[0]['id'] ?? '');

        if ($id === '') {
            return false;
        }

        self::remember('anthropic_workspace_id', $id);

        Logger::info('Arbeitsbereich eingetragen.', [
            'kennung' => $id,
            'name' => (string) ($found[0]['name'] ?? ''),
        ]);

        return true;
    }

    /**
     * Die Arbeitsbereiche der Organisation abfragen.
     *
     * Das gelingt nur, wenn der Schlüssel die Organisation lesen darf.
     * Tut er es nicht, kommt 401 oder 403 – kein Grund für Aufregung,
     * dann wird eben gefragt.
     *
     * @return array<int, array{id:string, name:string}>
     */
    public static function listWorkspaces(string $apiKey): array
    {
        $ch = curl_init();

        curl_setopt_array($ch, [
            CURLOPT_URL => self::WORKSPACES_ENDPOINT . '?limit=100',
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 20,
            CURLOPT_CONNECTTIMEOUT => 10,
            CURLOPT_HTTPHEADER => [
                'x-api-key: ' . $apiKey,
                'anthropic-version: ' . self::VERSION,
            ],
        ]);

        $raw = curl_exec($ch);
        $status = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        curl_close($ch);

        if ($raw === false || $status < 200 || $status >= 300) {
            Logger::info('Arbeitsbereiche liessen sich nicht abfragen.', ['status' => $status]);

            // 401 und 403 heissen: Dieser Schlüssel darf die Organisation
            // nicht lesen. Das ändert sich nicht von selbst.
            if ($status === 401 || $status === 403) {
                self::remember('anthropic_workspace_lookup', 'nein');
            }

            return [];
        }

        $data = json_decode((string) $raw, true);
        $out = [];

        foreach ((array) ($data['data'] ?? []) as $entry) {
            if (!is_array($entry) || ($entry['id'] ?? '') === '') {
                continue;
            }

            // Stillgelegte Bereiche zählen nicht mit – auf einen davon
            // zu buchen ginge ohnehin schief.
            if (($entry['archived_at'] ?? null) !== null) {
                continue;
            }

            $out[] = [
                'id' => (string) $entry['id'],
                'name' => (string) ($entry['name'] ?? ''),
            ];
        }

        return $out;
    }

    /**
     * Der hinterlegte Schlüssel, so weit man ihn zeigen darf.
     *
     * Anfang und Ende, dazwischen Punkte. Das reicht, um ihn mit dem in
     * der Konsole zu vergleichen – und genau daran scheitert es
     * erfahrungsgemäss: Man legt einen neuen an und trägt den alten ein.
     * Den ganzen Schlüssel anzuzeigen wäre unnötig; wer über die
     * Schulter schaut, hätte ihn sonst.
     */
    public static function maskedKey(): string
    {
        $key = trim((string) Config::get('anthropic.api_key', ''));

        if ($key === '') {
            return '';
        }

        if (mb_strlen($key) < 20) {
            return '… (auffällig kurz – ist das der ganze Schlüssel?)';
        }

        return mb_substr($key, 0, 17) . '…' . mb_substr($key, -4);
    }

    /**
     * Einmal anklopfen, ohne etwas zu erzeugen.
     *
     * Fragt die Modellliste ab. Das kostet keine Token und beantwortet
     * trotzdem alle drei Fragen, die vor einem Auftrag zählen: Stimmt
     * der Schlüssel, ist er freigeschaltet, und fehlt der
     * Arbeitsbereich? Besser hier als mitten im Bauen.
     *
     * @return array{ok:bool, text:string, hint:string}
     */
    public static function probe(?string $workspaceOverride = null): array
    {
        $apiKey = trim((string) Config::get('anthropic.api_key', ''));

        if ($apiKey === '') {
            return [
                'ok' => false,
                'text' => 'kein Schlüssel',
                'hint' => 'Ohne Schlüssel läuft der Generator im Übungsmodus und erzeugt '
                    . 'Beispieltexte. Der Schlüssel gehört in app/config.php unter '
                    . 'anthropic.api_key.',
            ];
        }

        $headers = [
            'x-api-key: ' . $apiKey,
            'anthropic-version: ' . self::VERSION,
        ];

        // Zum Ausprobieren darf eine Kennung mitgegeben werden, die noch
        // nirgends gespeichert ist.
        $workspace = $workspaceOverride ?? self::workspaceId();

        if ($workspace !== '') {
            $headers[] = 'anthropic-workspace-id: ' . $workspace;
        }

        $ch = curl_init();

        curl_setopt_array($ch, [
            CURLOPT_URL => 'https://api.anthropic.com/v1/models?limit=1',
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 15,
            CURLOPT_CONNECTTIMEOUT => 8,
            CURLOPT_HTTPHEADER => $headers,
        ]);

        $raw = curl_exec($ch);
        $status = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        curl_close($ch);

        if ($raw === false || $status === 0) {
            return [
                'ok' => false,
                'text' => 'nicht erreichbar',
                'hint' => 'Von diesem Server aus kommt keine Verbindung zustande. '
                    . 'Lässt das Hosting ausgehendes HTTPS zu?',
            ];
        }

        $message = (string) (json_decode((string) $raw, true)['error']['message'] ?? '');

        if (self::isWorkspaceMissing($status, $message)) {
            return [
                'ok' => false,
                'text' => 'Arbeitsbereich fehlt',
                'hint' => 'Der Schlüssel gehört einer Person, nicht einem Arbeitsbereich. '
                    . 'Trag die Kennung oben unter "Arbeitsbereich" ein – oder leg in der '
                    . 'Anthropic-Konsole einen Schlüssel an, der einem Arbeitsbereich '
                    . 'gehört. Beim nächsten Auftrag versucht die Anwendung es ohnehin '
                    . 'selbst herauszufinden.',
            ];
        }

        if ($status === 401 || $status === 403) {
            return [
                'ok' => false,
                'text' => 'abgewiesen (HTTP ' . $status . ')',
                'hint' => 'Der Schlüssel stimmt nicht oder darf nicht. In der '
                    . 'Anthropic-Konsole prüfen: gültig, Guthaben vorhanden?',
            ];
        }

        if ($status < 200 || $status >= 300) {
            return [
                'ok' => false,
                'text' => 'HTTP ' . $status,
                'hint' => $message !== '' ? $message : 'Unerwartete Antwort der Schnittstelle.',
            ];
        }

        return [
            'ok' => true,
            'text' => $workspace !== '' ? 'in Ordnung (' . $workspace . ')' : 'in Ordnung',
            'hint' => 'Schlüssel gültig und freigeschaltet.',
        ];
    }

    /** Die Meldung, wenn wir es nicht selbst lösen konnten. */
    private static function workspaceError(string $apiMessage): ConfigurationError
    {
        $choices = [];

        try {
            $choices = json_decode(Settings::get('anthropic_workspace_choices', '[]'), true) ?: [];
        } catch (\Throwable) {
            $choices = [];
        }

        if ($choices !== []) {
            $list = [];

            foreach (array_slice($choices, 0, 6) as $entry) {
                $list[] = (string) ($entry['name'] ?? '?') . ' (' . (string) ($entry['id'] ?? '') . ')';
            }

            return new ConfigurationError(
                'Der Schlüssel gehört zu einer Person, nicht zu einem Arbeitsbereich – '
                . 'deshalb muss dabeistehen, für welchen Bereich gearbeitet wird.',
                'Es gibt mehrere: ' . implode(', ', $list) . '. '
                . 'Trag den richtigen unter Einstellungen → Arbeitsbereich ein, '
                . 'dann läuft der Auftrag weiter.',
                'anthropic_workspace_id'
            );
        }

        // Selbst nachschlagen konnten wir es nicht: Die Liste der
        // Arbeitsbereiche darf nur ein Admin-Schlüssel lesen. Also bleibt
        // ein Handgriff – und der wird hier so genau beschrieben, dass er
        // beim ersten Versuch sitzt.
        return new ConfigurationError(
            'Der Schlüssel gehört zu einer Person, nicht zu einem Arbeitsbereich – '
            . 'deshalb muss dabeistehen, für welchen Bereich gearbeitet wird. '
            . 'Selbst herausfinden lässt sich das nicht: Die Liste der Arbeitsbereiche '
            . 'darf nur ein Admin-Schlüssel lesen.',
            'Zwei Wege, beide einmalig. Entweder in der Anthropic-Konsole unter '
            . '"API keys" einen neuen Schlüssel anlegen und dabei einen Arbeitsbereich '
            . 'auswählen statt "Personal" – dann braucht es hier nie wieder etwas. '
            . 'Oder platform.claude.com öffnen und aus der Adresszeile den Teil ab '
            . '"wrkspc_" unter Einstellungen → Arbeitsbereich einsetzen; die ganze '
            . 'Adresse einzufügen geht auch.',
            'anthropic_workspace_id'
        );
    }

    /** Was zu tun ist, je nach Fehlercode. */
    private static function remedyFor(int $status): string
    {
        return match ($status) {
            401 => 'Der Schlüssel stimmt nicht. Unter Einstellungen prüfen '
                . 'oder in der Anthropic-Konsole einen neuen anlegen.',
            403 => 'Der Schlüssel darf das nicht. Hat er noch Guthaben, und '
                . 'gehört er zum richtigen Arbeitsbereich?',
            404 => 'Die angefragte Adresse gibt es nicht. Ist das Modell noch aktuell?',
            413 => 'Die Anfrage war zu gross. Kürzere Beschreibung im Formular hilft.',
            default => '',
        };
    }

    /** Ist der hinterlegte Arbeitsbereich nur geraten? */
    private static function wasGuessed(): bool
    {
        try {
            return Settings::get('anthropic_workspace_guessed', '') === '1';
        } catch (\Throwable) {
            return false;
        }
    }

    /** Wissen wir schon, dass das Nachschlagen nichts bringt? */
    private static function rememberedAsHopeless(): bool
    {
        try {
            return Settings::get('anthropic_workspace_lookup', '') === 'nein';
        } catch (\Throwable) {
            return false;
        }
    }

    /** Einen Wert merken, ohne dass ein Datenbankfehler alles anhält. */
    private static function remember(string $key, string $value): void
    {
        try {
            Settings::put($key, $value);
        } catch (\Throwable $e) {
            Logger::warning('Wert liess sich nicht merken: ' . $e->getMessage());
        }
    }

    /** Aus einer technischen Meldung eine verständliche machen. */
    private static function friendlyError(int $status, string $message): string
    {
        return match (true) {
            $status === 401 => 'Der Anthropic-Schlüssel wird nicht akzeptiert. Bitte in app/config.php prüfen.',
            $status === 400 => 'Die Anfrage wurde abgelehnt: ' . $message,
            $status === 429 => 'Das Anfragelimit ist erreicht. In einigen Minuten geht es weiter.',
            $status === 402 || str_contains(mb_strtolower($message), 'credit')
                => 'Das Anthropic-Guthaben ist aufgebraucht. Bitte im Anthropic-Konto aufladen.',
            $status >= 500 => 'Die KI ist gerade nicht erreichbar. Der Auftrag wird automatisch wiederholt.',
            $status === 0 => 'Keine Verbindung zur KI. Läuft auf diesem Server ausgehendes HTTPS?',
            default => 'Unerwartete Antwort der KI (' . $status . ').',
        };
    }
}
