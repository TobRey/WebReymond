<?php
declare(strict_types=1);

namespace App\Repository;

use App\Core\HttpException;
use App\Core\Json;
use App\Core\Logger;
use App\Core\Validator;

/**
 * Faelle: Laden, Speichern, Versionieren, Import/Export.
 * Jeder Fall liegt als eine JSON-Datei unter cases/{id}.json.
 */
final class CaseRepository
{
    private const VERSION_KEEP = 20;

    public function __construct(private JsonStore $store)
    {
    }

    /** @return array<string,mixed>|null */
    public function find(string $caseId): ?array
    {
        $caseId = Validator::id($caseId, 'Fall-ID');
        $data = $this->store->read('cases/' . $caseId . '.json', []);
        if ($data === []) {
            return null;
        }
        return $this->migrate($data);
    }

    public function get(string $caseId): array
    {
        $case = $this->find($caseId);
        if ($case === null) {
            throw HttpException::notFound('Fall nicht gefunden.');
        }
        return $case;
    }

    /** @return array<int,array<string,mixed>> Kurzuebersicht aller Faelle */
    public function listSummaries(bool $includeUnpublished = false): array
    {
        $out = [];
        foreach ($this->store->listIds('cases') as $id) {
            if (str_starts_with($id, '_')) {
                continue;
            }
            $case = $this->find($id);
            if ($case === null) {
                continue;
            }
            $status = (string)($case['status'] ?? 'draft');
            if (!$includeUnpublished && $status !== 'published') {
                continue;
            }
            $out[] = $this->summary($case);
        }
        usort($out, static fn(array $a, array $b): int => ($a['order'] ?? 99) <=> ($b['order'] ?? 99));
        return $out;
    }

    public function summary(array $case): array
    {
        $missing = $case['missing_person'] ?? [];
        return [
            'id'           => (string)($case['id'] ?? ''),
            'title'        => (string)($case['title'] ?? 'Unbenannter Fall'),
            'code'         => (string)($case['code'] ?? ''),
            'status'       => (string)($case['status'] ?? 'draft'),
            'difficulty'   => (string)($case['difficulty'] ?? 'mittel'),
            'duration'     => (string)($case['duration'] ?? '15-25 Min.'),
            'summary'      => (string)($case['summary'] ?? ''),
            'cover'        => (string)($case['cover'] ?? 'assets/img/scenes/case-cover-toby.svg'),
            'order'        => (int)($case['order'] ?? 10),
            'date'         => (string)($case['incident_date'] ?? ''),
            'location'     => (string)($case['location'] ?? ''),
            'content_warning' => (string)($case['content_warning'] ?? ''),
            'missing'      => [
                'name'  => (string)($missing['name'] ?? ''),
                'age'   => (int)($missing['age'] ?? 0),
                'photo' => (string)($missing['photo'] ?? ''),
            ],
            'counts' => [
                'npcs'     => count($case['npcs'] ?? []),
                'evidence' => count($case['evidence'] ?? []),
                'puzzles'  => count($case['puzzles'] ?? []),
                'devices'  => count($case['devices'] ?? []),
            ],
            'updated_at'   => (string)($case['updated_at'] ?? ''),
        ];
    }

    public function save(array $case, bool $snapshot = true): array
    {
        $id = Validator::id((string)($case['id'] ?? ''), 'Fall-ID');
        $case['id'] = $id;
        $case['_schema'] = WIT_SCHEMA_VERSION;
        $case['updated_at'] = gmdate('c');
        $case['created_at'] ??= $case['updated_at'];
        $case['status'] = in_array(($case['status'] ?? 'draft'), ['draft', 'published', 'disabled'], true)
            ? $case['status'] : 'draft';

        if ($snapshot && $this->store->exists('cases/' . $id . '.json')) {
            $this->snapshot($id);
        }
        $this->store->write('cases/' . $id . '.json', $case);
        Logger::info('Fall gespeichert', ['case' => $id, 'status' => $case['status']]);
        return $case;
    }

    public function delete(string $caseId): bool
    {
        $caseId = Validator::id($caseId, 'Fall-ID');
        $this->snapshot($caseId);
        Logger::info('Fall geloescht', ['case' => $caseId]);
        return $this->store->delete('cases/' . $caseId . '.json');
    }

    public function setStatus(string $caseId, string $status): array
    {
        $case = $this->get($caseId);
        $case['status'] = in_array($status, ['draft', 'published', 'disabled'], true) ? $status : 'draft';
        return $this->save($case, false);
    }

    public function duplicate(string $caseId, string $newId, string $newTitle): array
    {
        $case = $this->get($caseId);
        $case['id'] = Validator::id($newId, 'Neue Fall-ID');
        $case['title'] = Validator::text($newTitle, 120);
        $case['status'] = 'draft';
        $case['created_at'] = gmdate('c');
        unset($case['updated_at']);
        return $this->save($case, false);
    }

    public function exists(string $caseId): bool
    {
        return $this->store->exists('cases/' . Validator::id($caseId, 'Fall-ID') . '.json');
    }

    public function export(string $caseId): string
    {
        return Json::encode($this->get($caseId), true);
    }

    /** @return array{case:array,warnings:string[]} */
    public function import(string $json, ?string $forceId = null): array
    {
        $data = Json::decode($json);
        if ($data === null || !isset($data['id'])) {
            throw HttpException::badRequest('Die Datei enthaelt keinen gueltigen Fall (JSON mit Feld "id" erwartet).');
        }
        if ($forceId !== null && $forceId !== '') {
            $data['id'] = $forceId;
        }
        $data['id'] = Validator::id((string)$data['id'], 'Fall-ID');
        $warnings = [];
        if ($this->exists($data['id'])) {
            $warnings[] = 'Ein Fall mit dieser ID existierte bereits und wurde ueberschrieben (Version wurde gesichert).';
        }
        $data['status'] = 'draft';
        $case = $this->save($data);
        return ['case' => $case, 'warnings' => $warnings];
    }

    /* ------------------- Versionierung ------------------- */

    public function snapshot(string $caseId): void
    {
        $current = $this->store->read('cases/' . $caseId . '.json', []);
        if ($current === []) {
            return;
        }
        $dir = 'cases/_versions/' . $caseId;
        $name = gmdate('Ymd_His') . '_' . bin2hex(random_bytes(2));
        $this->store->write($dir . '/' . $name . '.json', $current);

        $versions = $this->store->listIds($dir);
        if (count($versions) > self::VERSION_KEEP) {
            sort($versions);
            foreach (array_slice($versions, 0, count($versions) - self::VERSION_KEEP) as $old) {
                $this->store->delete($dir . '/' . $old . '.json');
            }
        }
    }

    /** @return array<int,array{id:string,created:string,title:string}> */
    public function versions(string $caseId): array
    {
        $caseId = Validator::id($caseId, 'Fall-ID');
        $dir = 'cases/_versions/' . $caseId;
        $out = [];
        foreach ($this->store->listIds($dir) as $version) {
            $data = $this->store->read($dir . '/' . $version . '.json', []);
            $out[] = [
                'id'      => $version,
                'created' => (string)($data['updated_at'] ?? $version),
                'title'   => (string)($data['title'] ?? ''),
            ];
        }
        usort($out, static fn(array $a, array $b): int => strcmp($b['id'], $a['id']));
        return $out;
    }

    public function restoreVersion(string $caseId, string $versionId): array
    {
        $caseId = Validator::id($caseId, 'Fall-ID');
        $versionId = Validator::id($versionId, 'Versions-ID');
        $data = $this->store->read('cases/_versions/' . $caseId . '/' . $versionId . '.json', []);
        if ($data === []) {
            throw HttpException::notFound('Version nicht gefunden.');
        }
        $data['id'] = $caseId;
        return $this->save($data);
    }

    /* ------------------- Migration ------------------- */

    private function migrate(array $case): array
    {
        $schema = (int)($case['_schema'] ?? 1);
        if ($schema >= WIT_SCHEMA_VERSION) {
            return $case;
        }
        // Schema 1 -> 2: Beweise brauchen Kategorien
        if ($schema < 2) {
            foreach ($case['evidence'] ?? [] as $index => $evidence) {
                $case['evidence'][$index]['category'] ??= 'dokument';
            }
        }
        // Schema 2 -> 3: Raetsel brauchen Hinweisstufen-Array
        if ($schema < 3) {
            foreach ($case['puzzles'] ?? [] as $index => $puzzle) {
                if (!isset($puzzle['hints']) || !is_array($puzzle['hints'])) {
                    $case['puzzles'][$index]['hints'] = ['', '', ''];
                }
            }
        }
        $case['_schema'] = WIT_SCHEMA_VERSION;
        Logger::info('Fall-Schema migriert', ['case' => $case['id'] ?? '?', 'from' => $schema, 'to' => WIT_SCHEMA_VERSION]);
        return $case;
    }
}
