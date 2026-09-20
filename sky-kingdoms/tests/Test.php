<?php

declare(strict_types=1);

/**
 * Winziges Testgerüst ohne externe Abhängigkeiten.
 * Aufruf:  php tests/run.php  [name-der-suite]
 */
final class Test
{
    public static int $passed = 0;
    public static int $failed = 0;
    public static string $suite = '';
    /** @var array<int,string> */
    public static array $failures = [];
    private static float $started = 0.0;

    public static function suite(string $name): void
    {
        self::$suite = $name;
        self::$started = microtime(true);
        echo "\n\033[1m» " . $name . "\033[0m\n";
    }

    public static function ok(bool $condition, string $message): void
    {
        if ($condition) {
            self::$passed++;
            echo "  \033[32m✓\033[0m " . $message . "\n";

            return;
        }

        self::$failed++;
        self::$failures[] = self::$suite . ' – ' . $message;
        echo "  \033[31m✗ " . $message . "\033[0m\n";
    }

    public static function eq(mixed $actual, mixed $expected, string $message): void
    {
        $equal = $actual === $expected;
        self::ok($equal, $message . ($equal ? '' : ' (erwartet: ' . self::show($expected) . ', erhalten: ' . self::show($actual) . ')'));
    }

    public static function near(float $actual, float $expected, float $epsilon, string $message): void
    {
        $equal = abs($actual - $expected) <= $epsilon;
        self::ok($equal, $message . ($equal ? '' : ' (erwartet ~' . $expected . ', erhalten ' . $actual . ')'));
    }

    public static function greater(float $actual, float $threshold, string $message): void
    {
        self::ok($actual > $threshold, $message . ($actual > $threshold ? '' : ' (' . $actual . ' ist nicht > ' . $threshold . ')'));
    }

    public static function throws(callable $fn, string $message, ?string $class = null): void
    {
        try {
            $fn();
            self::ok(false, $message . ' (keine Ausnahme geworfen)');
        } catch (\Throwable $e) {
            if ($class !== null && !($e instanceof $class)) {
                self::ok(false, $message . ' (falsche Ausnahme: ' . $e::class . ')');

                return;
            }
            self::ok(true, $message);
        }
    }

    public static function summary(): int
    {
        $total = self::$passed + self::$failed;
        echo "\n" . str_repeat('─', 60) . "\n";
        if (self::$failed === 0) {
            echo "\033[32m" . self::$passed . " von " . $total . " Prüfungen bestanden.\033[0m\n";

            return 0;
        }

        echo "\033[31m" . self::$failed . " von " . $total . " Prüfungen fehlgeschlagen:\033[0m\n";
        foreach (self::$failures as $failure) {
            echo "  • " . $failure . "\n";
        }

        return 1;
    }

    private static function show(mixed $value): string
    {
        if (is_bool($value)) {
            return $value ? 'true' : 'false';
        }
        if (is_array($value)) {
            return json_encode($value, JSON_UNESCAPED_UNICODE) ?: 'array';
        }

        return (string) $value;
    }
}
