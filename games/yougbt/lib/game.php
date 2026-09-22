<?php
// YouGBT – Spiellogik: Zustandsmaschine, Punkte, KI-Jobs, öffentliche Sicht.
// Alle Regeln werden hier serverseitig durchgesetzt; der Browser zeigt nur an.
declare(strict_types=1);

require_once __DIR__ . '/claude.php';
require_once __DIR__ . '/prompts.php';

const YG_ROUND_OPTIONS = [3, 5, 11, 21];

function yg_is_special(int $roundIdx): bool
{
    return ($roundIdx + 1) % 3 === 0;
}

function yg_hash_token(string $t): string
{
    return hash('sha256', 'yougbt|' . $t);
}

function yg_valid_name(string $raw): string
{
    $n = yg_clean_text($raw, 18);
    $n = preg_replace('/[<>"\'`\\\\{}]/u', '', $n) ?? '';
    $n = trim($n);
    // Ein vom Nutzer selbst angehängtes "AI" nicht doppeln
    $n = trim((string) preg_replace('/\s+AI$/i', '', $n));
    if (mb_strlen($n) < 1 || !preg_match('/[\p{L}\p{N}]/u', $n)) {
        throw new YgError('name_invalid');
    }
    return $n . ' AI';
}

function yg_new_player(string $name, int $now): array
{
    $token = yg_rand_hex(24);
    return [[
        'id' => 'p' . yg_rand_hex(5),
        'name' => $name,
        'th' => yg_hash_token($token),
        'joined' => $now,
        'seen' => $now,
        'status' => 'active',
        'score' => 0,
        'hint_used' => false,
        'joker_used' => false,
        'ready' => false,
        'chat_times' => [],
        'last_chat' => '',
    ], $token];
}

function yg_sanitize_settings(array $in, array $cur = []): array
{
    $rounds = (int) ($in['rounds'] ?? ($cur['rounds'] ?? 5));
    if (!in_array($rounds, YG_ROUND_OPTIONS, true)) {
        $rounds = 5;
    }
    $mode = ($in['mode'] ?? ($cur['mode'] ?? 'normal')) === 'roulette' ? 'roulette' : 'normal';
    $lang = ($in['lang'] ?? ($cur['lang'] ?? 'de')) === 'en' ? 'en' : 'de';
    $max = (int) ($in['max_players'] ?? ($cur['max_players'] ?? 6));
    $max = max(2, min(10, $max));
    return ['rounds' => $rounds, 'mode' => $mode, 'lang' => $lang, 'max_players' => $max];
}

function yg_create_room(array $settings, string $name, bool $solo): array
{
    if (!yg_is_configured()) {
        throw new YgError('not_configured', 503);
    }
    $now = yg_now_ms();
    $name = yg_valid_name($name);
    return yg_with_lock('create', function () use ($settings, $name, $solo, $now) {
        if (yg_active_games() >= max(1, (int) yg_cfg('max_games'))) {
            throw new YgError('too_many_games', 503);
        }
        do {
            $code = yg_rand_code();
        } while (is_file(yg_room_file($code)));
        [$player, $token] = yg_new_player($name, $now);
        $s = yg_sanitize_settings($settings);
        if ($solo) {
            $s['max_players'] = 1;
        }
        $cats = array_keys(yg_categories());
        shuffle($cats);
        $room = [
            'code' => $code, 'v' => 1, 'created' => $now, 'updated' => $now,
            'solo' => $solo, 'host_id' => $player['id'],
            'players' => [$player['id'] => $player],
            'phase' => 'lobby', 'round' => 0, 'sub' => 0, 'gen' => 0,
            'wheel' => array_slice($cats, 0, 8), 'sp' => [],
            'category' => null, 'spin' => null, 'roundq' => null, 'q' => null,
            'answers' => [], 'hints' => [], 'results' => null, 'reveal_until' => null,
            'pending' => [], 'history' => [], 'avoid' => [], 'recent_cats' => [],
            'job' => null, 'stalled' => null, 'notice' => null, 'annulled' => 0,
            'chat' => [], 'chat_seq' => 0, 'conn_sig' => '', 'final' => null,
        ] + $s;
        yg_sys_chat($room, 'joined', $player['name']);
        yg_save_room($room);
        return ['code' => $code, 'token' => $token, 'pid' => $player['id']];
    });
}

function yg_sys_chat(array &$room, string $event, string $name = ''): void
{
    $room['chat_seq']++;
    $room['chat'][] = ['n' => $room['chat_seq'], 'sys' => $event, 'name' => $name, 't' => yg_now_ms()];
    $room['chat'] = array_slice($room['chat'], -80);
}

function yg_auth(array $room, string $pid, string $token): array
{
    $p = $room['players'][$pid] ?? null;
    if (!$p || $token === '' || !hash_equals($p['th'], yg_hash_token($token))) {
        throw new YgError('not_in_room', 403);
    }
    return $p;
}

function yg_active_ids(array $room): array
{
    $ids = [];
    foreach ($room['players'] as $id => $p) {
        if ($p['status'] === 'active') {
            $ids[] = $id;
        }
    }
    return $ids;
}

function yg_job_running(array $room, int $now): bool
{
    return is_array($room['job']) && ($now - (int) $room['job']['started']) < YG_JOB_STALE_MS;
}

// ---------------------------------------------------------------------------
// Übergänge ohne KI (dürfen auch beim Polling laufen)
// ---------------------------------------------------------------------------

function yg_tick(array &$room, int $now): void
{
    // Anwesenheit: lange ohne Kontakt => ausgeschieden; Verbindungsstatus-Signatur
    $sig = '';
    foreach ($room['players'] as $id => &$p) {
        if ($p['status'] === 'active' && !$room['solo'] && $now - $p['seen'] > YG_LEFT_AFTER_MS) {
            $p['status'] = 'left';
            yg_sys_chat($room, 'timeout', $p['name']);
            $room['_dirty'] = true;
        }
        $sig .= $id . ($p['status'] === 'active' && $now - $p['seen'] < 25000 ? '1' : '0');
    }
    unset($p);
    if ($sig !== $room['conn_sig']) {
        $room['conn_sig'] = $sig;
        $room['_dirty'] = true;
    }

    $active = yg_active_ids($room);
    if (!in_array($room['host_id'], $active, true) && $active) {
        $room['host_id'] = $active[0];
        yg_sys_chat($room, 'host', $room['players'][$active[0]]['name']);
        $room['_dirty'] = true;
    }
    if ($room['phase'] === 'final' || $room['phase'] === 'lobby') {
        return;
    }
    if (!$room['solo'] && count($active) < 2) {
        yg_finish($room, count($active) === 1 ? 'last_player' : 'abandoned');
        return;
    }
    if ($room['solo'] && !$active) {
        yg_finish($room, 'abandoned');
        return;
    }

    switch ($room['phase']) {
        case 'spinning':
            if ($now >= (int) $room['spin']['until']) {
                $room['phase'] = 'generating';
                $room['_dirty'] = true;
            }
            break;
        case 'answering':
            $all = true;
            foreach ($active as $id) {
                if (!isset($room['answers'][$id])) {
                    $all = false;
                    break;
                }
            }
            $timeUp = $room['q']['deadline'] !== null && $now > (int) $room['q']['deadline'] + YG_ANSWER_GRACE_MS;
            if ($all || $timeUp) {
                if (yg_is_special($room['round'])) {
                    // Spezialrunde: Antworten sammeln, nächste Rückfrage sofort; bewertet wird erst am Ende
                    $room['sp'][$room['sub']] = ['answers' => $room['answers'], 'hints' => $room['hints']];
                    if ($room['sub'] < 2) {
                        $room['sub']++;
                        yg_start_answering($room, $now);
                        break;
                    }
                    $room['phase'] = 'grading';
                    $room['job'] = null;
                    $room['_dirty'] = true;
                    if (!yg_special_has_answers($room)) {
                        yg_apply_special_results($room, [], $now);
                    }
                    break;
                }
                $room['phase'] = 'grading';
                $room['job'] = null;
                $room['_dirty'] = true;
                if (!yg_has_answers($room)) {
                    yg_apply_results($room, [], $now);
                }
            }
            break;
        case 'reveal':
            $allReady = true;
            foreach ($active as $id) {
                if (empty($room['players'][$id]['ready'])) {
                    $allReady = false;
                    break;
                }
            }
            $timeUp = !$room['solo'] && $room['reveal_until'] !== null && $now >= (int) $room['reveal_until'];
            if ($allReady || $timeUp) {
                yg_next_step($room, $now);
            }
            break;
    }
}

function yg_special_has_answers(array $room): bool
{
    foreach ($room['sp'] ?? [] as $part) {
        foreach ($part['answers'] as $a) {
            if (trim((string) $a['text']) !== '') {
                return true;
            }
        }
    }
    return false;
}

function yg_has_answers(array $room): bool
{
    foreach ($room['answers'] as $a) {
        if (trim((string) $a['text']) !== '') {
            return true;
        }
    }
    return false;
}

function yg_begin_round(array &$room, int $now): void
{
    $room['sub'] = 0;
    $room['roundq'] = null;
    $room['q'] = null;
    $room['results'] = null;
    $room['notice'] = null;
    $room['annulled'] = 0;
    $room['pending'] = [];
    $room['sp'] = [];
    if ($room['mode'] === 'roulette') {
        $idx = random_int(0, count($room['wheel']) - 1);
        $room['category'] = $room['wheel'][$idx];
        $room['spin'] = ['idx' => $idx, 'until' => $now + YG_SPIN_MS, 'turns' => random_int(4, 6)];
        $room['phase'] = 'spinning';
    } else {
        $cats = array_diff(array_keys(yg_categories()), array_slice($room['recent_cats'], -5));
        $cats = array_values($cats);
        $room['category'] = $cats[random_int(0, count($cats) - 1)];
        $room['spin'] = null;
        $room['phase'] = 'generating';
    }
    $room['recent_cats'][] = $room['category'];
    $room['recent_cats'] = array_slice($room['recent_cats'], -8);
    $room['job'] = null;
    $room['_dirty'] = true;
}

function yg_start_answering(array &$room, int $now): void
{
    $part = $room['roundq']['parts'][$room['sub']];
    $room['gen']++;
    $room['q'] = [
        'key' => $room['round'] . '-' . $room['sub'] . '-' . $room['gen'],
        'message' => $part['message'],
        'started' => $now,
        'deadline' => $room['solo'] ? null : $now + YG_ANSWER_SECONDS * 1000,
    ];
    $room['answers'] = [];
    $room['hints'] = [];
    $room['results'] = null;
    foreach ($room['players'] as &$p) {
        $p['ready'] = false;
    }
    unset($p);
    $room['phase'] = 'answering';
    $room['job'] = null;
    $room['stalled'] = null;
    $room['_dirty'] = true;
}

function yg_next_step(array &$room, int $now): void
{
    foreach ($room['players'] as &$p) {
        $p['ready'] = false;
    }
    unset($p);
    $room['notice'] = null;
    $room['_dirty'] = true;
    $room['round']++;
    if ($room['round'] >= $room['rounds']) {
        yg_finish($room, 'complete');
        return;
    }
    yg_begin_round($room, $now);
}

function yg_finish(array &$room, string $reason): void
{
    $room['phase'] = 'final';
    $room['final'] = ['reason' => $reason, 'at' => yg_now_ms()];
    $room['job'] = null;
    $room['_dirty'] = true;
}

// ---------------------------------------------------------------------------
// Punkte
// ---------------------------------------------------------------------------

/** Berechnet die Punkte einer Antwort aus Rohpunkten (0–100). Reine Funktion, testbar. */
function yg_points(int $raw, bool $special, bool $joker, bool $hint): array
{
    $raw = max(0, min(100, $raw));
    $jokerOk = null;
    if ($special) {
        $base = (int) round($raw / 2);
        $penalty = $hint ? (int) (YG_HINT_PENALTY / 2) : 0;
    } else {
        $base = $raw;
        if ($joker) {
            $jokerOk = $raw >= YG_JOKER_THRESHOLD;
            $base = $jokerOk ? $raw * 2 : 0;
        }
        $penalty = $hint ? YG_HINT_PENALTY : 0;
    }
    return ['points' => max(0, $base - $penalty), 'penalty' => $penalty, 'joker_ok' => $jokerOk, 'max' => $special ? 50 : 100];
}

/** Wendet Bewertungen an und wechselt in die Auflösung. $graded: pid => [score, reason]. */
function yg_apply_results(array &$room, array $graded, int $now): void
{
    $special = false;
    $part = $room['roundq']['parts'][$room['sub']];
    $results = [];
    $roundTotals = [];
    foreach ($room['players'] as $id => $p) {
        $ans = $room['answers'][$id] ?? null;
        if ($p['status'] !== 'active' && !$ans) {
            continue;
        }
        $text = $ans ? (string) $ans['text'] : '';
        $raw = $graded[$id]['score'] ?? 0;
        $reason = $graded[$id]['reason'] ?? '';
        $hint = !empty($room['hints'][$id]);
        $joker = !$special && !empty($ans['joker']);
        $pts = yg_points($ans ? (int) $raw : 0, $special, $joker, $hint);
        if ($p['status'] !== 'active') {
            $pts['points'] = 0;
        }
        $results[$id] = [
            'answer' => $text,
            'raw' => $ans ? (int) $raw : 0,
            'points' => $pts['points'],
            'max' => $pts['max'],
            'reason' => $ans ? $reason : '',
            'none' => $ans === null,
            'joker' => $joker,
            'joker_ok' => $pts['joker_ok'],
            'hint' => $hint ? $part['hint'] : null,
            'penalty' => $pts['penalty'],
            'perfect' => $ans !== null && (int) $raw === 100,
        ];
        if ($p['status'] === 'active') {
            $roundTotals[$id] = $pts['points'];
        }
    }
    yg_credit_round($room, $roundTotals, false);
    $room['results'] = [
        'key' => $room['q']['key'],
        'players' => $results,
        'round_totals' => $roundTotals,
    ];
    yg_enter_reveal($room, $now);
}

/** Punkte gutschreiben und Rundenverlauf speichern. */
function yg_credit_round(array &$room, array $totals, bool $special): void
{
    foreach ($totals as $id => $sum) {
        if (isset($room['players'][$id]) && $room['players'][$id]['status'] === 'active') {
            $room['players'][$id]['score'] += $sum;
        }
    }
    $room['history'][] = [
        'round' => $room['round'] + 1,
        'special' => $special,
        'category' => $room['category'],
        'persona' => $room['roundq']['persona']['name'] ?? '',
        'points' => $totals,
    ];
    $room['history'] = array_slice($room['history'], -25);
    $room['pending'] = [];
}

/** Spezialrunde: alle drei Antworten je Spieler gemeinsam auswerten (je 0–50, max. 150). $graded: pid => [part => [score, reason]] */
function yg_apply_special_results(array &$room, array $graded, int $now): void
{
    $results = [];
    $totals = [];
    foreach ($room['players'] as $id => $p) {
        $any = false;
        $parts = [];
        $sum = 0;
        $perfect = false;
        for ($i = 0; $i < 3; $i++) {
            $ans = $room['sp'][$i]['answers'][$id] ?? null;
            $hint = !empty($room['sp'][$i]['hints'][$id]);
            $raw = $ans ? (int) ($graded[$id][$i]['score'] ?? 0) : 0;
            $pts = yg_points($raw, true, false, $hint);
            $any = $any || $ans !== null;
            $perfect = $perfect || ($ans !== null && $raw === 100);
            $sum += $pts['points'];
            $parts[] = [
                'answer' => $ans ? (string) $ans['text'] : '',
                'raw' => $raw,
                'points' => $pts['points'],
                'reason' => $ans ? (string) ($graded[$id][$i]['reason'] ?? '') : '',
                'none' => $ans === null,
                'hint' => $hint ? ($room['roundq']['parts'][$i]['hint'] ?? null) : null,
                'penalty' => $pts['penalty'],
            ];
        }
        if ($p['status'] !== 'active' && !$any) {
            continue;
        }
        if ($p['status'] !== 'active') {
            $sum = 0;
        } else {
            $totals[$id] = $sum;
        }
        $results[$id] = [
            'special' => true, 'parts' => $parts, 'points' => $sum, 'max' => 150, 'raw' => null,
            'none' => !$any, 'joker' => false, 'joker_ok' => null, 'perfect' => $perfect,
            'answer' => '', 'reason' => '', 'hint' => null, 'penalty' => 0,
        ];
    }
    yg_credit_round($room, $totals, true);
    $room['results'] = ['key' => $room['q']['key'], 'players' => $results, 'round_totals' => $totals];
    yg_enter_reveal($room, $now);
}

function yg_enter_reveal(array &$room, int $now): void
{
    $room['phase'] = 'reveal';
    $room['reveal_until'] = $room['solo'] ? null : $now + YG_REVEAL_SECONDS * 1000;
    $room['job'] = null;
    $room['stalled'] = null;
    $room['_dirty'] = true;
}

// ---------------------------------------------------------------------------
// KI-Jobs: werden unter Sperre beansprucht, ohne Sperre ausgeführt, dann unter Sperre angewendet.
// ---------------------------------------------------------------------------

function yg_claim_job(array &$room, int $now): ?array
{
    if (!in_array($room['phase'], ['generating', 'grading'], true) || yg_job_running($room, $now)) {
        return null;
    }
    $kind = $room['phase'] === 'generating' ? 'gen' : 'grade';
    $job = ['kind' => $kind, 'id' => yg_rand_hex(8), 'started' => $now];
    if ($kind === 'gen') {
        $special = yg_is_special($room['round']);
        $job['special'] = $special;
        $job['category'] = $room['category'];
        $job['avoid'] = $room['avoid'];
        // Ersatz nur für eine annullierte Rückfrage der Spezialrunde
        if ($special && $room['sub'] > 0 && $room['roundq']) {
            $job['replace_sub'] = $room['sub'];
            $job['context'] = array_map(fn($p) => $p['message'], $room['roundq']['parts']);
        }
    } else {
        $job['key'] = $room['q']['key'];
        $job['special'] = yg_is_special($room['round']);
        // Liste von Fragen, jeweils mit den (nicht leeren) Antworten der Spieler
        $sets = $job['special'] ? array_map(fn($x) => $x['answers'], $room['sp']) : [$room['answers']];
        $job['items'] = [];
        foreach ($sets as $i => $answers) {
            $list = [];
            foreach ($answers as $pid => $a) {
                if (trim((string) $a['text']) !== '') {
                    $list[$pid] = (string) $a['text'];
                }
            }
            $job['items'][] = ['part' => $room['roundq']['parts'][$job['special'] ? $i : $room['sub']], 'answers' => $list];
        }
    }
    $room['job'] = ['kind' => $kind, 'id' => $job['id'], 'started' => $now];
    $room['_dirty'] = true;
    return $job;
}

/** Führt einen beanspruchten Job aus (ohne Raumsperre) und wendet das Ergebnis an. */
function yg_run_job(string $code, array $job): void
{
    @set_time_limit(180);
    ignore_user_abort(true);
    $lang = yg_load_room($code)['lang'] ?? 'de';
    $error = null;
    $output = null;
    try {
        if ($job['kind'] === 'gen') {
            if (isset($job['replace_sub'])) {
                $seed = yg_style_seed(true);
                $user = yg_gen_user($job['category'], true, [], $seed, ['original_and_followups' => $job['context']]);
                $output = yg_claude_json(yg_gen_system($lang), $user, 900, fn($d) => yg_valid_part($d));
            } else {
                $parts = $job['special'] ? 3 : 1;
                $seed = yg_style_seed($job['special']);
                $user = yg_gen_user($job['category'], $job['special'], $job['avoid'], $seed);
                $output = yg_claude_json(yg_gen_system($lang), $user, $job['special'] ? 2200 : 1100, fn($d) => yg_validate_generation($d, $parts));
            }
        } else {
            // Anonyme, gemischte IDs: Die KI sieht keine Namen und keine Reihenfolge der Abgabe
            $map = [];
            $blocks = [];
            foreach ($job['items'] as $qi => $item) {
                $pids = array_keys($item['answers']);
                shuffle($pids);
                $anon = [];
                foreach ($pids as $i => $pid) {
                    $aid = 'q' . ($qi + 1) . 'a' . ($i + 1);
                    $map[$aid] = [$pid, $qi];
                    $anon[$aid] = $item['answers'][$pid];
                }
                $blocks[] = ['part' => $item['part'], 'answers' => $anon];
            }
            $ids = array_keys($map);
            $grading = yg_claude_json(
                yg_grade_system($lang),
                yg_grade_user($blocks),
                400 + 120 * count($ids),
                fn($d) => yg_validate_grading($d, $ids)
            );
            $byPid = [];
            foreach ($grading['results'] as $aid => $r) {
                [$pid, $qi] = $map[$aid];
                if ($job['special']) {
                    $byPid[$pid][$qi] = $r;
                } else {
                    $byPid[$pid] = $r;
                }
            }
            $output = ['valid' => $grading['question_valid'], 'reason' => $grading['invalid_reason'], 'graded' => $byPid];
        }
    } catch (YgError $e) {
        $error = $e->errCode;
    } catch (Throwable $e) {
        $error = 'ai_error';
    }

    yg_mutate_room($code, function (array &$room) use ($job, $output, $error) {
        $now = yg_now_ms();
        if (!is_array($room['job']) || $room['job']['id'] !== $job['id']) {
            return; // veraltet (z. B. Partie beendet oder Job neu vergeben)
        }
        if ($error !== null) {
            $room['stalled'] = ['from' => $room['phase'], 'err' => $error, 'at' => $now];
            $room['phase'] = 'stalled';
            $room['job'] = null;
            $room['_dirty'] = true;
            return;
        }
        if ($job['kind'] === 'gen') {
            if (isset($job['replace_sub'])) {
                $room['roundq']['parts'][$job['replace_sub']] = $output;
            } else {
                $room['roundq'] = $output;
                $room['avoid'][] = $output['topic'];
                $room['avoid'][] = $output['persona']['name'];
                $room['avoid'] = array_slice($room['avoid'], -20);
            }
            yg_start_answering($room, $now);
            return;
        }
        // Bewertung
        if ($room['q']['key'] !== $job['key']) {
            return;
        }
        if ($job['special']) {
            yg_apply_special_results($room, $output['graded'], $now);
            return;
        }
        if (!$output['valid'] && $room['annulled'] < 1) {
            yg_annul($room, $output['reason'], $now);
            return;
        }
        yg_apply_results($room, $output['graded'], $now);
    });
}

/** Frage war nicht fair bewertbar: für alle annullieren, Hilfen zurückgeben, Ersatzfrage erzeugen. */
function yg_annul(array &$room, string $reason, int $now): void
{
    foreach ($room['hints'] as $pid => $_) {
        if (isset($room['players'][$pid])) {
            $room['players'][$pid]['hint_used'] = false;
        }
    }
    foreach ($room['answers'] as $pid => $a) {
        if (!empty($a['joker']) && isset($room['players'][$pid])) {
            $room['players'][$pid]['joker_used'] = false;
        }
    }
    $room['annulled']++;
    $room['notice'] = ['type' => 'annulled', 'reason' => $reason, 'question' => $room['q']['message']];
    $room['answers'] = [];
    $room['hints'] = [];
    $room['q'] = null;
    $room['phase'] = 'generating';
    $room['job'] = null;
    $room['_dirty'] = true;
    // Bei der Startfrage einer Spezialrunde wird die ganze Runde neu erzeugt
    if (yg_is_special($room['round']) && $room['sub'] === 0) {
        $room['roundq'] = null;
    }
}

// ---------------------------------------------------------------------------
// Öffentliche Sicht – enthält nie Token, Kriterien, Musterantwort oder fremde Antworten vor der Auflösung
// ---------------------------------------------------------------------------

function yg_view(array $room, string $me, int $now): array
{
    $phase = $room['phase'];
    $lang = $room['lang'];
    $special = yg_is_special($room['round']);
    $players = [];
    foreach ($room['players'] as $id => $p) {
        $players[] = [
            'id' => $id,
            'name' => $p['name'],
            'host' => $id === $room['host_id'],
            'status' => $p['status'],
            'online' => $p['status'] === 'active' && ($now - $p['seen'] < 25000),
            'submitted' => in_array($phase, ['answering', 'grading', 'stalled'], true) && isset($room['answers'][$id]),
            'ready' => !empty($p['ready']),
            'score' => $p['score'],
        ];
    }
    $mine = $room['players'][$me];
    $view = [
        'code' => $room['code'],
        'v' => $room['v'],
        'now' => $now,
        'solo' => $room['solo'],
        'phase' => $phase,
        'settings' => ['rounds' => $room['rounds'], 'mode' => $room['mode'], 'lang' => $lang, 'max_players' => $room['max_players']],
        'round' => min($room['round'] + 1, $room['rounds']),
        'special' => $special && $phase !== 'lobby' && $phase !== 'final',
        'sub' => $room['sub'],
        'is_host' => $me === $room['host_id'],
        'me' => [
            'id' => $me, 'name' => $mine['name'], 'status' => $mine['status'],
            'hint_used' => $mine['hint_used'], 'joker_used' => $mine['joker_used'], 'ready' => !empty($mine['ready']),
        ],
        'players' => $players,
        'wheel' => array_map(fn($k) => ['key' => $k, 'label' => yg_category_label($k, $lang)], $room['wheel']),
        'category' => $room['category'] ? ['key' => $room['category'], 'label' => yg_category_label($room['category'], $lang)] : null,
        'busy' => yg_job_running($room, $now),
        'notice' => $room['notice'],
        'chat' => array_slice($room['chat'], -50),
        'chat_paused' => $phase === 'answering',
        'history' => $room['history'],
    ];
    if ($phase === 'spinning') {
        $view['spin'] = $room['spin'];
    }
    if ($room['roundq'] && in_array($phase, ['answering', 'grading', 'reveal', 'stalled'], true) && $room['q']) {
        $view['persona'] = $room['roundq']['persona'];
        $view['q'] = [
            'key' => $room['q']['key'],
            'message' => $room['q']['message'],
            'deadline' => $room['q']['deadline'],
            'started' => $room['q']['started'],
        ];
        // Frühere Teile der Spezialrunde als Chatverlauf
        if ($special && $room['sub'] > 0) {
            $view['thread'] = [];
            for ($i = 0; $i < $room['sub']; $i++) {
                $view['thread'][] = ['q' => $room['roundq']['parts'][$i]['message'], 'mine' => $room['sp'][$i]['answers'][$me]['text'] ?? null];
            }
        }
        $a = $room['answers'][$me] ?? null;
        $view['my'] = [
            'submitted' => $a !== null,
            'answer' => $a['text'] ?? null,
            'joker' => !empty($a['joker']),
            'hint' => !empty($room['hints'][$me]) ? $room['roundq']['parts'][$room['sub']]['hint'] : null,
        ];
    }
    if ($phase === 'reveal' && $room['results']) {
        $res = [];
        foreach ($room['results']['players'] as $pid => $r) {
            $res[] = ['id' => $pid, 'name' => $room['players'][$pid]['name'] ?? '?'] + $r;
        }
        $view['results'] = [
            'list' => $res,
            'questions' => $special ? array_map(fn($p) => $p['message'], $room['roundq']['parts']) : null,
            'round_totals' => $room['results']['round_totals'],
            'reveal_until' => $room['reveal_until'],
        ];
    }
    if ($phase === 'stalled') {
        $view['stalled'] = ['err' => $room['stalled']['err'] ?? 'ai_error'];
    }
    if ($phase === 'final') {
        $view['final'] = ['reason' => $room['final']['reason'] ?? 'complete', 'ranking' => yg_ranking($room)];
    }
    $view['due'] = yg_is_due($room, $now);
    return $view;
}

/** Platzierungen mit fairen Gleichständen (1,1,3). Ausgeschiedene stehen hinter aktiven Spielern. */
function yg_ranking(array $room): array
{
    $list = [];
    foreach ($room['players'] as $id => $p) {
        $list[] = ['id' => $id, 'name' => $p['name'], 'score' => $p['score'], 'left' => $p['status'] !== 'active'];
    }
    usort($list, fn($a, $b) => [$a['left'], -$a['score'], $a['name']] <=> [$b['left'], -$b['score'], $b['name']]);
    foreach ($list as $i => &$row) {
        $rank = 1;
        foreach ($list as $o) {
            if ($o['left'] < $row['left'] || ($o['left'] === $row['left'] && $o['score'] > $row['score'])) {
                $rank++;
            }
        }
        $row['rank'] = $rank;
    }
    unset($row);
    return $list;
}

/** Muss ein Client "advance" aufrufen? (Nur dann entsteht ggf. ein kostenpflichtiger Aufruf.) */
function yg_is_due(array $room, int $now): bool
{
    return in_array($room['phase'], ['generating', 'grading'], true) && !yg_job_running($room, $now);
}
