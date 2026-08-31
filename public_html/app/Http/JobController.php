<?php

declare(strict_types=1);

namespace WebAtze\Http;

use WebAtze\Core\{Audit, Db, Jobs, Request, Response, Worker};

/**
 * Stand eines Auftrags abfragen und Aufträge abbrechen.
 * Die Oberfläche fragt hier im Sekundentakt nach dem Fortschritt.
 */
final class JobController
{
    public function status(Request $request): Response
    {
        $job = Jobs::find($request->paramInt('id'));

        if ($job === null) {
            return Response::json(['ok' => false, 'error' => 'Auftrag nicht gefunden.'], 404)->noCache();
        }

        $redirect = '';
        if ($job['status'] === Jobs::DONE) {
            $redirect = (string) ($job['state']['redirect'] ?? '');
        }

        return Response::json([
            'ok' => true,
            'job' => [
                'id' => $job['id'],
                'type' => (string) $job['type'],
                'status' => (string) $job['status'],
                'step' => (string) $job['step'],
                'progress' => $job['progress'],
                'message' => (string) $job['message'],
                'error' => (string) ($job['error'] ?? ''),
                'attempts' => $job['attempts'],
                'redirect' => $redirect,
            ],
        ])->noCache();
    }

    /**
     * Einen angehaltenen Auftrag fortsetzen.
     *
     * Gedacht für den Fall, dass eine Einstellung gefehlt hat: nachtragen,
     * hier klicken, und es geht dort weiter, wo es geklemmt hat. Der
     * Zwischenstand bleibt erhalten – schon geschriebene Texte werden
     * nicht noch einmal bezahlt.
     */
    public function retry(Request $request): Response
    {
        $id = (int) $request->param('id');
        $job = Jobs::find($id);

        if ($job === null) {
            return Response::json(['ok' => false, 'error' => 'Unbekannter Auftrag.'], 404)->noCache();
        }

        if (!Jobs::retry($id)) {
            return Response::json([
                'ok' => false,
                'error' => 'Dieser Auftrag läuft bereits oder ist fertig.',
            ], 409)->noCache();
        }

        Audit::log('job.retry', (string) $id, ['schritt' => (string) $job['step']], $request);

        // Sofort anstossen, statt bis zur nächsten Minute zu warten.
        Worker::run(5);

        return Response::json(['ok' => true])->noCache()->noIndex();
    }

    public function cancel(Request $request): Response
    {
        $job = Jobs::find($request->paramInt('id'));

        if ($job === null) {
            return Response::json(['ok' => false, 'error' => 'Auftrag nicht gefunden.'], 404)->noCache();
        }

        Jobs::cancel($job['id']);
        Audit::log('job.cancelled', 'Auftrag #' . $job['id'], ['typ' => $job['type']], $request);

        if ($job['project_id'] !== null) {
            Db::update('projects', ['status' => 'draft', 'updated_at' => Db::now()],
                'id = :id AND status = :s', ['id' => $job['project_id'], 's' => 'building']);
        }

        return Response::json(['ok' => true])->noCache();
    }
}
