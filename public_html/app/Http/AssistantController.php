<?php

declare(strict_types=1);

namespace WebAtze\Http;

use WebAtze\Ai\{ClaudeClient, SectionEditor};
use WebAtze\Core\{Audit, Db, Logger, RateLimit, Request, Response, Security};

/**
 * Das Relais für die Bearbeitungsbereiche der Kunden.
 *
 * Ein Kundenbackend liegt beim Kunden auf dessen Hosting. Es hat keinen
 * Zugang zur KI und soll auch keinen bekommen – sonst läge der
 * API-Schlüssel auf fremden Servern.
 *
 * Stattdessen schickt es seine Anfrage hierher. Ausgewiesen wird es über
 * einen Schlüssel, der nur für dieses eine Projekt gilt. Hier läuft die
 * KI-Anfrage, hier bleibt der API-Schlüssel, und hier wird auch
 * abgerechnet.
 *
 * Nach aussen heisst das Ganze "WebAtze-Assistent". Der Kunde erfährt
 * nicht, was darunter arbeitet.
 */
final class AssistantController
{
    private const LIMIT_PER_HOUR = 30;

    /** Kurzer Test, ob der Schlüssel gilt – vom Kundenbackend beim Start. */
    public function ping(Request $request): Response
    {
        $project = $this->authenticate($request);
        if ($project === null) {
            return $this->denied();
        }

        return Response::json([
            'ok' => true,
            'assistant' => 'WebAtze-Assistent',
            'available' => ClaudeClient::isConfigured(),
        ])->noCache()->noIndex();
    }

    /**
     * Einen Abschnitt ändern – angestossen aus dem Kundenbackend.
     */
    public function edit(Request $request): Response
    {
        $project = $this->authenticate($request);
        if ($project === null) {
            return $this->denied();
        }

        $projectId = (int) $project['id'];

        if (!RateLimit::hit('assistant:' . $projectId, self::LIMIT_PER_HOUR, 3600)) {
            return Response::json([
                'ok' => false,
                'error' => 'Es wurden viele Änderungen in kurzer Zeit angefragt. '
                    . 'Bitte in einer Stunde weitermachen.',
            ], 429)->noCache();
        }

        $body = $request->jsonBody();

        $sectionId = (int) ($body['section_id'] ?? 0);
        $instruction = trim((string) ($body['instruction'] ?? ''));

        $section = Db::first(
            'SELECT * FROM project_sections WHERE id = :id AND project_id = :p',
            ['id' => $sectionId, 'p' => $projectId]
        );

        if ($section === null) {
            return Response::json([
                'ok' => false,
                'error' => 'Dieser Abschnitt gehört nicht zu dieser Website.',
            ], 404)->noCache();
        }

        if (!ClaudeClient::isConfigured()) {
            return Response::json([
                'ok' => false,
                'error' => 'Der Assistent ist gerade nicht verfügbar. Bitte später erneut versuchen.',
            ], 503)->noCache();
        }

        $section['content'] = json_decode((string) $section['content'], true) ?: [];
        $section['overrides'] = json_decode((string) $section['overrides'], true) ?: [];

        $editor = new SectionEditor($projectId);

        try {
            // Quelle 'assistant': im Protokoll erscheint der neutrale Name,
            // nicht Claude.
            $result = $editor->apply(
                $section,
                $instruction,
                (string) ($project['admin_username'] ?: 'Kunde'),
                'assistant'
            );
        } catch (\Throwable $e) {
            $id = Logger::exception($e);

            // Nach aussen keine technischen Einzelheiten.
            return Response::json([
                'ok' => false,
                'error' => 'Die Änderung hat nicht geklappt. Bitte anders formulieren '
                    . 'oder es später erneut versuchen.',
                'ref' => $id,
            ], 500)->noCache();
        }

        if (!$result['ok']) {
            return Response::json(['ok' => false, 'error' => $result['error']], 422)->noCache();
        }

        Audit::log('assistant.edit', (string) $project['name'], [
            'abschnitt' => $sectionId,
            'anweisung' => mb_substr($instruction, 0, 200),
        ], $request);

        $updated = Db::first('SELECT * FROM project_sections WHERE id = :id', ['id' => $sectionId]);

        return Response::json([
            'ok' => true,
            'summary' => $result['summary'],
            'label' => SectionEditor::actorLabel('assistant', ''),
            'content' => json_decode((string) ($updated['content'] ?? '{}'), true) ?: [],
            'overrides' => json_decode((string) ($updated['overrides'] ?? '{}'), true) ?: [],
            'template' => (string) ($updated['template_key'] ?? ''),
        ])->noCache()->noIndex();
    }

    // ------------------------------------------------------------------

    /**
     * Wer ruft hier an?
     *
     * Der Schlüssel steht im Kopf der Anfrage. Verglichen wird
     * zeitunabhängig, damit sich nichts über die Antwortdauer erraten lässt.
     */
    private function authenticate(Request $request): ?array
    {
        $token = trim($request->header('X-WebAtze-Token'));

        if ($token === '') {
            $body = $request->jsonBody();
            $token = trim((string) ($body['token'] ?? ''));
        }

        if (strlen($token) < 20 || strlen($token) > 100) {
            return null;
        }

        // Alle Kandidaten holen und zeitunabhängig vergleichen. Eine
        // direkte Abfrage nach dem Schlüssel wäre schneller, würde aber
        // über die Dauer verraten, ob es ihn gibt.
        $rows = Db::all(
            "SELECT * FROM projects WHERE assistant_token <> '' AND wants_admin = 1"
        );

        foreach ($rows as $row) {
            if (Security::equals((string) $row['assistant_token'], $token)) {
                return $row;
            }
        }

        return null;
    }

    private function denied(): Response
    {
        // Bewusst dieselbe Antwort, egal ob der Schlüssel fehlt, falsch
        // ist oder zu einem Projekt ohne Backend gehört.
        return Response::json([
            'ok' => false,
            'error' => 'Zugang verweigert.',
        ], 403)->noCache()->noIndex();
    }
}
