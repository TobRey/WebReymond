<?php

/**
 * Das Gerüst für den Testlauf.
 *
 * Absichtlich klein: ein paar Funktionen, keine Abhängigkeit, kein
 * Composer. Wer die Tests laufen lässt, soll nichts installieren müssen.
 */

declare(strict_types=1);

$GLOBALS['wa_tests'] = ['bestanden' => 0, 'gescheitert' => 0, 'fehler' => [], 'gruppe' => ''];

function test(string $name, callable $body): void
{
    $GLOBALS['wa_tests']['gruppe'] = $name;

    echo "\n\033[1m" . $name . "\033[0m\n";

    try {
        $body();
    } catch (\Throwable $e) {
        fehler('Ausnahme: ' . $e->getMessage() . ' (' . basename($e->getFile()) . ':' . $e->getLine() . ')');
    }
}

function ok(bool $bedingung, string $was): void
{
    $bedingung ? bestanden($was) : fehler($was);
}

function is(mixed $erwartet, mixed $tatsächlich, string $was): void
{
    if ($erwartet === $tatsächlich) {
        bestanden($was);
        return;
    }

    fehler(sprintf('%s – erwartet %s, bekommen %s', $was, kurz($erwartet), kurz($tatsächlich)));
}

function isnt(mixed $unerwünscht, mixed $tatsächlich, string $was): void
{
    $unerwünscht !== $tatsächlich
        ? bestanden($was)
        : fehler($was . ' – hätte nicht ' . kurz($unerwünscht) . ' sein dürfen');
}

function bestanden(string $was): void
{
    $GLOBALS['wa_tests']['bestanden']++;
    echo "  \033[32m✓\033[0m " . $was . "\n";
}

function fehler(string $was): void
{
    $GLOBALS['wa_tests']['gescheitert']++;
    $GLOBALS['wa_tests']['fehler'][] = $GLOBALS['wa_tests']['gruppe'] . ' → ' . $was;
    echo "  \033[31m✗ " . $was . "\033[0m\n";
}

function kurz(mixed $wert): string
{
    if (is_string($wert)) {
        return '"' . (mb_strlen($wert) > 60 ? mb_substr($wert, 0, 57) . '…' : $wert) . '"';
    }
    if (is_bool($wert)) {
        return $wert ? 'true' : 'false';
    }
    if ($wert === null) {
        return 'null';
    }
    if (is_array($wert)) {
        return 'Array(' . count($wert) . ')';
    }
    return (string) $wert;
}

/**
 * Welches Ziel bekommt diese Adresse? null, wenn keines.
 *
 * dispatch() würde die Zwischenschichten durchlaufen und den Controller
 * aufrufen; hier interessiert nur, welche Regel greift. Deshalb wird die
 * fertig übersetzte Liste gelesen und selbst verglichen – genau so, wie
 * dispatch() es auch tut.
 */
function route(\WebAtze\Core\Router $router, string $method, string $path): ?string
{
    $property = (new \ReflectionClass($router))->getProperty('routes');
    $property->setAccessible(true);

    foreach ($property->getValue($router) as $route) {
        if ($route['method'] === $method && preg_match($route['regex'], $path)) {
            return (string) $route['handler'];
        }
    }

    return null;
}

/**
 * Die Wächter einer Adresse – dieselbe Suche, nur mit der anderen
 * Antwort. Damit lässt sich prüfen, ob eine Adresse hinter der
 * Anmeldung liegt oder davor.
 *
 * @return list<string>|null
 */
function route_middleware(\WebAtze\Core\Router $router, string $method, string $path): ?array
{
    $property = (new \ReflectionClass($router))->getProperty('routes');
    $property->setAccessible(true);

    foreach ($property->getValue($router) as $route) {
        if ($route['method'] === $method && preg_match($route['regex'], $path)) {
            return $route['middleware'] ?? [];
        }
    }

    return null;
}

/** Kontrastverhältnis zweier Farben nach WCAG. */
function contrast(string $a, string $b): float
{
    $l = static function (string $hex): float {
        $hex = ltrim($hex, '#');
        if (strlen($hex) === 3) {
            $hex = $hex[0] . $hex[0] . $hex[1] . $hex[1] . $hex[2] . $hex[2];
        }

        $kanal = static function (int $wert): float {
            $s = $wert / 255;
            return $s <= 0.03928 ? $s / 12.92 : (($s + 0.055) / 1.055) ** 2.4;
        };

        return 0.2126 * $kanal((int) hexdec(substr($hex, 0, 2)))
            + 0.7152 * $kanal((int) hexdec(substr($hex, 2, 2)))
            + 0.0722 * $kanal((int) hexdec(substr($hex, 4, 2)));
    };

    $la = $l($a);
    $lb = $l($b);

    return (max($la, $lb) + 0.05) / (min($la, $lb) + 0.05);
}

function summary(): never
{
    $t = $GLOBALS['wa_tests'];

    echo "\n" . str_repeat('─', 60) . "\n";

    if ($t['gescheitert'] === 0) {
        echo "\033[32m" . $t['bestanden'] . " Prüfungen bestanden.\033[0m\n";
        exit(0);
    }

    echo "\033[31m" . $t['gescheitert'] . " von " . ($t['bestanden'] + $t['gescheitert'])
        . " Prüfungen gescheitert:\033[0m\n\n";

    foreach ($t['fehler'] as $fehler) {
        echo "  • " . $fehler . "\n";
    }

    echo "\n";
    exit(1);
}
