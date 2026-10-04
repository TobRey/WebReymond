<?php
declare(strict_types=1);

/*
 * Spiellogik. Phasen eines Raums:
 *   lobby            – warten auf die zweite Person
 *   question_loading – Claude denkt sich eine Frage aus
 *   question         – beide antworten geheim
 *   scoring          – beide haben abgegeben, Claude bewertet
 *   reveal           – Aufdeckung + Punkte der Runde
 *   title_loading    – Claude vergibt den Duo-Titel
 *   finished         – Endergebnis
 *
 * Aufgaben, die Claude brauchen, laufen als "Job": Sie werden unter der
 * Raumsperre beansprucht, ohne Sperre ausgeführt und danach unter der Sperre
 * eingetragen. Bricht eine Anfrage ab, übernimmt der nächste Abruf den Job.
 */

if (!defined('GH_APP')) {
    http_response_code(404);
    exit;
}

final class GhError extends RuntimeException
{
    public function __construct(string $message, public readonly int $status = 400, public readonly string $errorCode = 'error')
    {
        parent::__construct($message);
    }
}

function gh_job_stale_seconds(): int
{
    return (int) gh_config()['api_timeout'] * 2 + 20;
}

function gh_new_token(): string
{
    return bin2hex(random_bytes(24));
}

function gh_hash_token(string $token): string
{
    return hash('sha256', $token);
}

function gh_new_player(int $id, array $taken): array
{
    $identity = gh_random_identity($taken);
    return $identity + [
        'id' => $id,
        'tokenHash' => '',
        'lastSeen' => time(),
        'joined' => time(),
    ];
}

function gh_find_player(array $room, string $token): ?int
{
    if ($token === '') {
        return null;
    }
    $hash = gh_hash_token($token);
    foreach ($room['players'] as $i => $p) {
        if (hash_equals($p['tokenHash'], $hash)) {
            return $i;
        }
    }
    return null;
}

function gh_is_online(array $player): bool
{
    return time() - (int) $player['lastSeen'] <= GH_ONLINE_SECONDS;
}

/* ---------------- Aktionen ---------------- */

function gh_action_create(): array
{
    if (!gh_rate_limit_ok()) {
        throw new GhError('Zu viele neue Räume in kurzer Zeit. Bitte warte ein paar Minuten.', 429, 'rate_limited');
    }
    if (random_int(1, 15) === 1) {
        gh_cleanup_rooms();
    }
    $token = gh_new_token();
    $player = gh_new_player(0, []);
    $player['tokenHash'] = gh_hash_token($token);
    for ($i = 0; $i < 20; $i++) {
        $room = [
            'code' => gh_random_code(),
            'created' => time(),
            'updated' => time(),
            'version' => 1,
            'game' => 1,
            'phase' => 'lobby',
            'phaseSince' => time(),
            'round' => 0,
            'question' => null,
            'answers' => [null, null],
            'ready' => [false, false],
            'results' => [],
            'gameQuestions' => [],
            'allQuestions' => [],
            'title' => null,
            'job' => null,
            'players' => [$player],
        ];
        if (gh_create_room_file($room)) {
            return ['code' => $room['code'], 'token' => $token];
        }
    }
    throw new GhError('Es konnte kein freier Raumcode gefunden werden. Bitte nochmals versuchen.', 500);
}

function gh_action_join(string $code, string $token): array
{
    if (!gh_room_exists($code)) {
        throw new GhError('Diesen Raum gibt es nicht (mehr). Prüfe den Code.', 404, 'room_not_found');
    }
    $res = gh_with_room($code, function (array &$room) use ($token) {
        $me = gh_find_player($room, $token);
        if ($me !== null) {
            $room['players'][$me]['lastSeen'] = time();
            return ['token' => $token, 'me' => $me];
        }
        $newToken = gh_new_token();
        if (count($room['players']) < 2) {
            $p = gh_new_player(1, $room['players']);
            $p['tokenHash'] = gh_hash_token($newToken);
            $room['players'][] = $p;
            if ($room['phase'] === 'lobby') {
                gh_start_game($room, false);
            }
            return ['token' => $newToken, 'me' => 1];
        }
        // Raum voll: einen lange verwaisten Platz übernehmen (z. B. Gerätewechsel).
        foreach ($room['players'] as $i => $p) {
            if (time() - (int) $p['lastSeen'] > GH_SEAT_TAKEOVER_SECONDS) {
                $room['players'][$i]['tokenHash'] = gh_hash_token($newToken);
                $room['players'][$i]['lastSeen'] = time();
                return ['token' => $newToken, 'me' => $i, 'takeover' => true];
            }
        }
        return ['full' => true];
    });
    if ($res === null) {
        throw new GhError('Diesen Raum gibt es nicht (mehr).', 404, 'room_not_found');
    }
    if (!empty($res[0]['full'])) {
        throw new GhError('Dieser Raum ist schon voll – hier spielen bereits zwei Hirnzellen.', 409, 'room_full');
    }
    return $res[0];
}

/** Gemeinsamer Einstieg für alle Aktionen mit Token. */
function gh_mutate(string $code, string $token, callable $fn): array
{
    $res = gh_with_room($code, function (array &$room) use ($token, $fn) {
        $me = gh_find_player($room, $token);
        if ($me === null) {
            return ['_auth' => false];
        }
        if (time() - (int) $room['players'][$me]['lastSeen'] >= 4) {
            $room['players'][$me]['lastSeen'] = time();
        }
        gh_maybe_auto_advance($room);
        $fn($room, $me);
        return ['_auth' => true, 'me' => $me];
    });
    if ($res === null) {
        throw new GhError('Diesen Raum gibt es nicht (mehr).', 404, 'room_not_found');
    }
    if (!$res[0]['_auth']) {
        throw new GhError('Du gehörst (noch) nicht zu diesem Raum.', 403, 'not_member');
    }
    return [$res[0]['me'], $res[1]];
}

function gh_start_game(array &$room, bool $isRestart): void
{
    if ($isRestart) {
        $room['game']++;
    }
    $room['round'] = 1;
    $room['results'] = [];
    $room['gameQuestions'] = [];
    $room['title'] = null;
    $room['ready'] = [false, false];
    gh_begin_question($room);
}

function gh_begin_question(array &$room): void
{
    $room['phase'] = 'question_loading';
    $room['phaseSince'] = time();
    $room['question'] = null;
    $room['answers'] = [null, null];
    $room['ready'] = [false, false];
    gh_queue_job($room, 'question');
}

function gh_queue_job(array &$room, string $type): void
{
    $room['job'] = [
        'type' => $type,
        'id' => null,
        'claimedAt' => 0,
        'game' => $room['game'],
        'round' => $room['round'],
    ];
}

function gh_clean_answer(string $raw): string
{
    // Unicode-Leerzeichen am Anfang/Ende entfernen.
    $s = preg_replace('/^[\s\p{Z}\x{200B}\x{FEFF}]+|[\s\p{Z}\x{200B}\x{FEFF}]+$/u', '', $raw) ?? trim($raw);
    return $s;
}

function gh_normalize_answer(string $s): string
{
    return mb_strtolower(gh_clean_answer($s));
}

function gh_action_answer(string $code, string $token, string $answer): array
{
    $answer = gh_clean_answer($answer);
    if ($answer === '') {
        throw new GhError('Bitte gib ein Wort ein.', 422, 'empty');
    }
    if (preg_match('/[\s\p{Z}]/u', $answer)) {
        throw new GhError('Genau ein Wort, bitte – ohne Leerzeichen.', 422, 'one_word');
    }
    if (mb_strlen($answer) > GH_ANSWER_MAX_LEN) {
        throw new GhError('Das Wort ist zu lang (max. ' . GH_ANSWER_MAX_LEN . ' Zeichen).', 422, 'too_long');
    }
    if (preg_match('/[\p{Cc}\p{Cf}]/u', str_replace(["\u{200D}", "\u{FE0F}"], '', $answer))) {
        throw new GhError('Das Wort enthält unsichtbare Steuerzeichen.', 422, 'invalid');
    }
    return gh_mutate($code, $token, function (array &$room, int $me) use ($answer) {
        if ($room['phase'] !== 'question') {
            throw new GhError('Gerade kann nicht geantwortet werden.', 409, 'wrong_phase');
        }
        if ($room['answers'][$me] !== null) {
            return; // schon abgegeben – geheim bleibt geheim, keine Änderung
        }
        $room['answers'][$me] = $answer;
        if ($room['answers'][0] !== null && $room['answers'][1] !== null) {
            $room['phase'] = 'scoring';
            $room['phaseSince'] = time();
            gh_queue_job($room, 'score');
        }
    });
}

function gh_action_next(string $code, string $token): array
{
    return gh_mutate($code, $token, function (array &$room, int $me) {
        if ($room['phase'] !== 'reveal') {
            return;
        }
        $room['ready'][$me] = true;
        $other = 1 - $me;
        $otherAway = isset($room['players'][$other])
            && time() - (int) $room['players'][$other]['lastSeen'] > GH_SKIP_WAIT_SECONDS;
        if (($room['ready'][0] && $room['ready'][1]) || $otherAway) {
            gh_advance_after_reveal($room);
        }
    });
}

function gh_advance_after_reveal(array &$room): void
{
    $room['ready'] = [false, false];
    if ($room['round'] >= GH_ROUNDS) {
        $room['phase'] = 'title_loading';
        $room['phaseSince'] = time();
        gh_queue_job($room, 'title');
    } else {
        $room['round']++;
        gh_begin_question($room);
    }
}

/** Wartet jemand im Reveal auf eine Person, die seit langem weg ist, geht es weiter. */
function gh_maybe_auto_advance(array &$room): void
{
    if ($room['phase'] !== 'reveal') {
        return;
    }
    foreach ([0, 1] as $i) {
        $other = 1 - $i;
        if ($room['ready'][$i] && isset($room['players'][$other])
            && time() - (int) $room['players'][$other]['lastSeen'] > GH_SKIP_WAIT_SECONDS) {
            gh_advance_after_reveal($room);
            return;
        }
    }
}

function gh_action_restart(string $code, string $token): array
{
    return gh_mutate($code, $token, function (array &$room) {
        if ($room['phase'] !== 'finished' || count($room['players']) < 2) {
            return;
        }
        gh_start_game($room, true);
    });
}

function gh_action_rename(string $code, string $token): array
{
    return gh_mutate($code, $token, function (array &$room, int $me) {
        $others = $room['players'];
        unset($others[$me]);
        $identity = gh_random_identity(array_values($others));
        $room['players'][$me]['name'] = $identity['name'];
        $room['players'][$me]['emoji'] = $identity['emoji'];
        // Bei Ergebnissen gespeicherte Namen sind nicht betroffen.
    });
}

/* ---------------- Jobs (Claude) ---------------- */

/** Führt einen offenen Job aus, falls vorhanden und niemand anderes daran arbeitet. */
function gh_run_pending_job(string $code): void
{
    $claimId = bin2hex(random_bytes(8));
    $res = gh_with_room($code, function (array &$room) use ($claimId) {
        $job = $room['job'] ?? null;
        if (!$job) {
            return false;
        }
        if ($job['id'] !== null && time() - (int) $job['claimedAt'] < gh_job_stale_seconds()) {
            return false; // läuft bereits in einer anderen Anfrage
        }
        $room['job']['id'] = $claimId;
        $room['job']['claimedAt'] = time();
        return true;
    });
    if ($res === null || $res[0] !== true) {
        return;
    }
    $room = $res[1];
    $job = $room['job'];

    ignore_user_abort(true);

    $apply = null;
    switch ($job['type']) {
        case 'question':
            $previous = array_values(array_unique(array_merge($room['allQuestions'], $room['gameQuestions'])));
            $q = gh_claude_question($previous);
            $source = 'claude';
            if ($q === null) {
                $q = gh_fallback_question($previous, $room['gameQuestions']);
                $source = 'fallback';
            }
            $apply = function (array &$room) use ($q, $source) {
                $room['question'] = ['text' => $q, 'source' => $source];
                $room['gameQuestions'][] = $q;
                $room['allQuestions'][] = $q;
                $room['allQuestions'] = array_slice($room['allQuestions'], -150);
                $room['phase'] = 'question';
                $room['phaseSince'] = time();
            };
            break;

        case 'score':
            [$a, $b] = $room['answers'];
            $exact = gh_normalize_answer($a) === gh_normalize_answer($b);
            $rating = gh_claude_rate($room['question']['text'], $a, $b, $exact);
            $source = 'claude';
            if ($rating === null) {
                $score = $exact ? 10 : gh_letter_similarity_score($a, $b);
                $rating = ['score' => $score, 'comment' => gh_fallback_comment($score)];
                $source = 'fallback';
            }
            $apply = function (array &$room) use ($rating, $exact, $source, $a, $b) {
                $room['results'][] = [
                    'round' => $room['round'],
                    'question' => $room['question']['text'],
                    'answers' => [$a, $b],
                    'score' => $exact ? 10 : max(1, min(9, (int) $rating['score'])),
                    'exact' => $exact,
                    'comment' => $rating['comment'],
                    'source' => $source,
                ];
                $room['phase'] = 'reveal';
                $room['phaseSince'] = time();
                $room['ready'] = [false, false];
            };
            break;

        case 'title':
            $title = gh_claude_title($room);
            if ($title === null) {
                $title = gh_fallback_title(gh_total($room));
                $title['source'] = 'fallback';
            } else {
                $title['source'] = 'claude';
            }
            $apply = function (array &$room) use ($title) {
                $room['title'] = $title;
                $room['phase'] = 'finished';
                $room['phaseSince'] = time();
            };
            break;
    }

    gh_with_room($code, function (array &$room) use ($claimId, $apply) {
        if (($room['job']['id'] ?? null) !== $claimId) {
            return false; // jemand anderes hat übernommen oder das Spiel ging weiter
        }
        $room['job'] = null;
        $apply($room);
        return true;
    });
}

function gh_fallback_question(array $previous, array $thisGame): string
{
    $pick = function (array $avoid): array {
        $seen = array_map('gh_norm_question', $avoid);
        return array_values(array_filter(GH_FALLBACK_QUESTIONS, fn($q) => !in_array(gh_norm_question($q), $seen, true)));
    };
    // Bevorzugt nie gespielte Fragen, sonst wenigstens keine aus diesem Spiel.
    $pool = $pick($previous) ?: ($pick($thisGame) ?: GH_FALLBACK_QUESTIONS);
    return $pool[random_int(0, count($pool) - 1)];
}

function gh_total(array $room): int
{
    return array_sum(array_map(fn($r) => (int) $r['score'], $room['results']));
}

/* ---------------- Sicht für eine Person ---------------- */

function gh_view(array $room, int $me): array
{
    $players = [];
    foreach ($room['players'] as $i => $p) {
        $players[] = [
            'name' => $p['name'],
            'emoji' => $p['emoji'],
            'color' => $p['color'],
            'online' => gh_is_online($p),
            'answered' => $room['answers'][$i] !== null,
            'ready' => (bool) ($room['ready'][$i] ?? false),
            'isMe' => $i === $me,
            'secondsAway' => max(0, time() - (int) $p['lastSeen']),
        ];
    }
    $phase = $room['phase'];
    $results = $room['results'];

    $view = [
        'code' => $room['code'],
        'version' => $room['version'],
        'me' => $me,
        'phase' => $phase,
        'phaseAge' => time() - (int) $room['phaseSince'],
        'game' => $room['game'],
        'round' => $room['round'],
        'totalRounds' => GH_ROUNDS,
        'players' => $players,
        'question' => in_array($phase, ['question', 'scoring', 'reveal'], true) ? $room['question']['text'] ?? null : null,
        'myAnswer' => $room['answers'][$me] ?? null,
        'results' => $results,
        'total' => gh_total($room),
        'title' => $phase === 'finished' ? $room['title'] : null,
        'skipWaitSeconds' => GH_SKIP_WAIT_SECONDS,
        'aiEnabled' => gh_claude_available(),
    ];
    return $view;
}
