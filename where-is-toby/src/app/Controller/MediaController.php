<?php
declare(strict_types=1);

namespace App\Controller;

use App\Core\HttpException;
use App\Core\Request;
use App\Core\Response;
use App\Core\Validator;
use App\Service\AudioSynth;

/**
 * Ausliefern geschuetzter Medien (Uploads) und erzeugter Audiodateien.
 */
final class MediaController extends Controller
{
    public function show(Request $request, array $args): Response
    {
        $this->container->auth()->requireUser();
        $file = Validator::filename((string)($args['file'] ?? ''));
        $path = $this->container->uploads()->pathFor($file);
        return $this->fileResponse($path);
    }

    public function thumb(Request $request, array $args): Response
    {
        $this->container->auth()->requireUser();
        $file = Validator::filename((string)($args['file'] ?? ''));
        $path = $this->container->uploads()->pathFor($file, true);
        return $this->fileResponse($path);
    }

    /** Erzeugt (und puffert) die Spielaudios prozedural als WAV. */
    public function audio(Request $request, array $args): Response
    {
        $track = Validator::id((string)($args['track'] ?? ''), 'Audiokennung');
        $cacheDir = WIT_STORAGE . '/cache/audio';
        if (!is_dir($cacheDir)) {
            @mkdir($cacheDir, 0750, true);
        }
        $cacheFile = $cacheDir . '/' . $track . '.wav';

        if (!is_file($cacheFile)) {
            $synth = new AudioSynth();
            $wav = $synth->render($track);
            if ($wav === null) {
                throw HttpException::notFound('Unbekannte Audiospur.');
            }
            @file_put_contents($cacheFile, $wav);
        }
        $data = (string)@file_get_contents($cacheFile);
        if ($data === '') {
            throw HttpException::notFound('Audiodatei nicht lesbar.');
        }
        return Response::raw($data, 'audio/wav')
            ->withHeader('Cache-Control', 'public, max-age=86400')
            ->withHeader('Content-Length', (string)strlen($data));
    }

    private function fileResponse(string $path): Response
    {
        if (!is_file($path)) {
            throw HttpException::notFound('Datei nicht gefunden.');
        }
        $mime = 'application/octet-stream';
        if (function_exists('finfo_open')) {
            $finfo = finfo_open(FILEINFO_MIME_TYPE);
            if ($finfo !== false) {
                $mime = (string)finfo_file($finfo, $path) ?: $mime;
                finfo_close($finfo);
            }
        }
        // Niemals als HTML/PHP ausliefern
        if (preg_match('~(html|php|javascript)~i', $mime)) {
            $mime = 'text/plain';
        }
        $data = (string)file_get_contents($path);
        return Response::raw($data, $mime)
            ->withHeader('Cache-Control', 'private, max-age=3600')
            ->withHeader('Content-Length', (string)strlen($data))
            ->withHeader('X-Content-Type-Options', 'nosniff');
    }
}
