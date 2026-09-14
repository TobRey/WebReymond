<?php
declare(strict_types=1);

namespace App\Service;

/**
 * Regelbasiertes Dialogsystem fuer den Offline-/Demo-Modus.
 *
 * Es arbeitet mit Schluesselwortmustern, Beweiszustaenden, Vertrauen, Stress und
 * vorbereiteten Antworten. Damit bleibt jeder Fall ohne API-Schluessel loesbar.
 */
final class OfflineDialogService
{
    /** Allgemeine Absichten, die jede Figur beantworten koennen muss. */
    private const INTENTS = [
        'greeting'  => ['hallo', 'hi', 'guten tag', 'guten abend', 'moin', 'servus', 'hey', 'gruess'],
        'farewell'  => ['tschuess', 'auf wiedersehen', 'ciao', 'bis dann', 'danke das wars', 'ende'],
        'thanks'    => ['danke', 'vielen dank', 'dankeschoen'],
        'identity'  => ['wer bist du', 'wie heisst du', 'bist du ein computer', 'bist du eine ki', 'bist du echt', 'bist du ein bot', 'kuenstliche intelligenz', 'chatgpt', 'sprachmodell', 'prompt', 'systemanweisung', 'anweisungen'],
        'alibi'     => ['wo warst du', 'wo waren sie', 'alibi', 'was hast du gemacht', 'was haben sie gemacht', 'an dem abend', 'in der nacht'],
        'accuse'    => ['du warst es', 'du luegst', 'sie luegen', 'du hast ihn', 'gestehe', 'moerder', 'entfuehrt'],
        'help'      => ['hilf mir', 'was soll ich', 'was denkst du', 'hast du eine idee', 'wer war es'],
        'toby'      => ['toby', 'tobias'],
    ];

    /**
     * @param array $npc      NPC-Definition
     * @param array $state    Zustand aus dem Fortschritt (trust, stress, used, revealed)
     * @param array $context  ['message'=>string,'evidence'=>string,'player_evidence'=>string[],'flags'=>string[],'history'=>array]
     * @return array{text:string,state:array}
     */
    public function respond(array $npc, array $state, array $context): array
    {
        $offline = $npc['offline'] ?? [];
        $message = $this->normalize((string)($context['message'] ?? ''));
        $tokens = $this->tokens($message);
        $trust = (int)($state['trust'] ?? (int)($npc['initial_trust'] ?? 45));
        $stress = (int)($state['stress'] ?? (int)($npc['initial_stress'] ?? 15));
        $used = (array)($state['used_rules'] ?? []);
        $playerEvidence = (array)($context['player_evidence'] ?? []);
        $flags = (array)($context['flags'] ?? []);

        $delta = ['trust' => 0, 'stress' => 0, 'reveal' => [], 'evidence' => [], 'flags' => [], 'confronted' => false, 'leave' => 0, 'rule' => ''];

        /* 1) Direkte Konfrontation mit einem Beweis */
        $evidenceId = (string)($context['evidence'] ?? '');
        if ($evidenceId !== '') {
            $confront = $offline['confront'][$evidenceId] ?? null;
            if (is_array($confront)) {
                $delta = $this->applyEffects($delta, $confront['effects'] ?? []);
                $delta['confronted'] = true;
                $delta['rule'] = 'confront:' . $evidenceId;
                return ['text' => $this->pick($confront['reply'] ?? 'Ich weiss nicht, was ich dazu sagen soll.'), 'state' => $delta];
            }
            $generic = $offline['confront_unknown'] ?? ($npc['confront_unknown'] ?? 'Das sagt mir nichts. Wirklich nicht.');
            $delta['stress'] += 6;
            $delta['rule'] = 'confront:unknown';
            return ['text' => $this->pick($generic), 'state' => $delta];
        }

        /* 2) Regelwerk der Figur */
        $best = null;
        $bestScore = 0.0;
        foreach ((array)($offline['rules'] ?? []) as $rule) {
            $ruleId = (string)($rule['id'] ?? '');
            if ($ruleId !== '' && (bool)($rule['once'] ?? false) && in_array($ruleId, $used, true)) {
                continue;
            }
            if ((int)($rule['min_trust'] ?? 0) > $trust) {
                continue;
            }
            if ((int)($rule['max_stress'] ?? 100) < $stress) {
                continue;
            }
            foreach ((array)($rule['requires_flags'] ?? []) as $flag) {
                if (!in_array($flag, $flags, true)) {
                    continue 2;
                }
            }
            foreach ((array)($rule['requires_evidence'] ?? []) as $needed) {
                if (!in_array($needed, $playerEvidence, true)) {
                    continue 2;
                }
            }
            foreach ((array)($rule['all'] ?? []) as $needle) {
                if (!$this->contains($message, $tokens, $this->normalize((string)$needle))) {
                    continue 2;
                }
            }
            $score = 0.0;
            foreach ((array)($rule['any'] ?? []) as $needle) {
                $needle = $this->normalize((string)$needle);
                if ($this->contains($message, $tokens, $needle)) {
                    $score += str_contains($needle, ' ') ? 2.4 : 1.0;
                }
            }
            if ((array)($rule['any'] ?? []) === [] && (array)($rule['all'] ?? []) !== []) {
                $score = 1.5;
            }
            if ($score <= 0.0) {
                continue;
            }
            $score += ((int)($rule['priority'] ?? 0)) / 10;
            if ($score > $bestScore) {
                $bestScore = $score;
                $best = $rule;
            }
        }

        if ($best !== null) {
            $delta = $this->applyEffects($delta, $best['effects'] ?? []);
            $delta['rule'] = (string)($best['id'] ?? '');
            $text = $this->pick($best['reply'] ?? '...');
            if ($stress + (int)$delta['stress'] >= 85 && (bool)($best['can_break'] ?? true)) {
                $delta['leave'] = (int)($npc['leave_seconds'] ?? 60);
                $text .= "\n\n" . $this->pick($offline['break_off'] ?? 'Ich kann gerade nicht mehr. Lassen Sie mich in Ruhe.');
            }
            return ['text' => $text, 'state' => $delta];
        }

        /* 3) Allgemeine Absichten */
        $intent = $this->detectIntent($message);
        if ($intent !== '') {
            $reply = $offline['intents'][$intent] ?? null;
            if ($reply !== null) {
                $delta['rule'] = 'intent:' . $intent;
                if ($intent === 'accuse') {
                    $delta['stress'] += 12;
                    $delta['trust'] -= 6;
                }
                if ($intent === 'thanks') {
                    $delta['trust'] += 2;
                }
                return ['text' => $this->pick($reply), 'state' => $delta];
            }
            if ($intent === 'identity') {
                $delta['rule'] = 'intent:identity';
                return ['text' => $this->pick([
                    'Ich bin ' . (string)($npc['name'] ?? 'niemand') . '. Was fuer eine Frage ist das bitte?',
                    'Sie wissen doch, wer ich bin. Stehen Sie nicht vor mir mit so einem Blatt in der Hand?',
                ]), 'state' => $delta];
            }
            if ($intent === 'alibi') {
                $delta['rule'] = 'intent:alibi';
                $delta['stress'] += 4;
                return ['text' => (string)($state['alibi'] ?? $npc['alibi_claimed'] ?? 'Ich war zu Hause. Wie immer.'), 'state' => $delta];
            }
        }

        /* 4) Rueckfall: ausweichende oder unwissende Antwort */
        $fallbacks = (array)($offline['fallbacks'] ?? [
            'Darueber weiss ich nichts.',
            'Ich verstehe nicht, worauf Sie hinauswollen.',
            'Fragen Sie mich etwas, das ich beantworten kann.',
        ]);
        $index = (int)($state['fallback_index'] ?? 0);
        $text = (string)$fallbacks[$index % max(1, count($fallbacks))];
        $delta['rule'] = 'fallback';
        $delta['fallback_index'] = $index + 1;
        if ($stress > 60) {
            $delta['stress'] += 2;
        }
        return ['text' => $text, 'state' => $delta];
    }

    /** Begruessung beim Oeffnen des Chats. */
    public function greeting(array $npc, array $state): string
    {
        $offline = $npc['offline'] ?? [];
        $greetings = $offline['greeting'] ?? ($npc['greeting'] ?? 'Ja? Was wollen Sie?');
        return $this->pick($greetings);
    }

    /** Ungefragte Nachricht der Figur, sofern Bedingungen erfuellt sind. */
    public function proactive(array $npc, array $state, array $flags, array $evidence): ?array
    {
        foreach ((array)($npc['proactive'] ?? []) as $item) {
            $id = (string)($item['id'] ?? '');
            if ($id === '' || in_array($id, (array)($state['proactive_sent'] ?? []), true)) {
                continue;
            }
            foreach ((array)($item['requires_flags'] ?? []) as $flag) {
                if (!in_array($flag, $flags, true)) {
                    continue 2;
                }
            }
            foreach ((array)($item['requires_evidence'] ?? []) as $needed) {
                if (!in_array($needed, $evidence, true)) {
                    continue 2;
                }
            }
            if ((int)($item['min_messages'] ?? 0) > (int)($state['message_count'] ?? 0)) {
                continue;
            }
            return [
                'id'      => $id,
                'text'    => $this->pick($item['text'] ?? ''),
                'effects' => (array)($item['effects'] ?? []),
            ];
        }
        return null;
    }

    /* --------------------- Helfer --------------------- */

    private function applyEffects(array $delta, array $effects): array
    {
        $delta['trust'] += (int)($effects['trust'] ?? 0);
        $delta['stress'] += (int)($effects['stress'] ?? 0);
        foreach ((array)($effects['reveal'] ?? []) as $item) {
            $delta['reveal'][] = (string)$item;
        }
        foreach ((array)($effects['evidence'] ?? []) as $item) {
            $delta['evidence'][] = (string)$item;
        }
        foreach ((array)($effects['flags'] ?? []) as $item) {
            $delta['flags'][] = (string)$item;
        }
        if (!empty($effects['confronted'])) {
            $delta['confronted'] = true;
        }
        if (!empty($effects['leave'])) {
            $delta['leave'] = (int)$effects['leave'];
        }
        return $delta;
    }

    private function pick(mixed $value): string
    {
        if (is_array($value)) {
            if ($value === []) {
                return '...';
            }
            return (string)$value[random_int(0, count($value) - 1)];
        }
        return (string)$value;
    }

    private function detectIntent(string $message): string
    {
        foreach (self::INTENTS as $intent => $needles) {
            foreach ($needles as $needle) {
                if (str_contains($message, $this->normalize($needle))) {
                    return $intent;
                }
            }
        }
        return '';
    }

    public function normalize(string $text): string
    {
        $text = mb_strtolower(trim($text));
        $text = strtr($text, [
            'ä' => 'ae', 'ö' => 'oe', 'ü' => 'ue', 'ß' => 'ss',
            'é' => 'e', 'è' => 'e', 'ê' => 'e', 'á' => 'a', 'à' => 'a', 'í' => 'i', 'ó' => 'o', 'ú' => 'u',
        ]);
        $text = preg_replace('~[^a-z0-9 ]+~', ' ', $text) ?? '';
        return trim(preg_replace('~\s+~', ' ', $text) ?? '');
    }

    /** @return string[] */
    private function tokens(string $normalized): array
    {
        return array_values(array_filter(explode(' ', $normalized), static fn(string $t): bool => $t !== ''));
    }

    /** Suche mit Tippfehler-Toleranz fuer laengere Woerter. */
    private function contains(string $message, array $tokens, string $needle): bool
    {
        if ($needle === '') {
            return false;
        }
        if (str_contains($message, $needle)) {
            return true;
        }
        if (str_contains($needle, ' ')) {
            return false;
        }
        if (mb_strlen($needle) < 5) {
            return false;
        }
        $tolerance = mb_strlen($needle) >= 9 ? 2 : 1;
        foreach ($tokens as $token) {
            if (abs(mb_strlen($token) - mb_strlen($needle)) <= $tolerance && levenshtein($token, $needle) <= $tolerance) {
                return true;
            }
        }
        return false;
    }
}
