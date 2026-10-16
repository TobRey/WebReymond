<?php
declare(strict_types=1);

namespace SwipeGroove\Tests;

/**
 * Minimaler Test-Rahmen (kein Composer nötig). Methoden mit Präfix "test" werden ausgeführt.
 */
abstract class TestCase
{
    protected function setUp(): void
    {
    }

    protected function tearDown(): void
    {
    }

    public static function assertTrue(mixed $v, string $msg = ''): void
    {
        if ($v !== true) {
            throw new AssertionFailed($msg ?: 'Erwartet true, erhalten ' . var_export($v, true));
        }
    }

    public static function assertFalse(mixed $v, string $msg = ''): void
    {
        if ($v !== false) {
            throw new AssertionFailed($msg ?: 'Erwartet false, erhalten ' . var_export($v, true));
        }
    }

    public static function assertSame(mixed $expected, mixed $actual, string $msg = ''): void
    {
        if ($expected !== $actual) {
            throw new AssertionFailed(($msg ? $msg . ': ' : '') . 'Erwartet ' . var_export($expected, true) . ', erhalten ' . var_export($actual, true));
        }
    }

    public static function assertEquals(mixed $expected, mixed $actual, string $msg = ''): void
    {
        if ($expected != $actual) {
            throw new AssertionFailed(($msg ? $msg . ': ' : '') . 'Erwartet ' . var_export($expected, true) . ', erhalten ' . var_export($actual, true));
        }
    }

    public static function assertCount(int $n, array|\Countable $a, string $msg = ''): void
    {
        if (count($a) !== $n) {
            throw new AssertionFailed(($msg ? $msg . ': ' : '') . 'Erwartet ' . $n . ' Elemente, erhalten ' . count($a));
        }
    }

    public static function assertNull(mixed $v, string $msg = ''): void
    {
        if ($v !== null) {
            throw new AssertionFailed($msg ?: 'Erwartet null, erhalten ' . var_export($v, true));
        }
    }

    public static function assertNotNull(mixed $v, string $msg = ''): void
    {
        if ($v === null) {
            throw new AssertionFailed($msg ?: 'Erwartet nicht null');
        }
    }

    public static function assertContains(mixed $needle, array $haystack, string $msg = ''): void
    {
        if (!in_array($needle, $haystack, true)) {
            throw new AssertionFailed($msg ?: var_export($needle, true) . ' nicht enthalten');
        }
    }

    public static function assertStringContains(string $needle, string $haystack, string $msg = ''): void
    {
        if (!str_contains($haystack, $needle)) {
            throw new AssertionFailed($msg ?: '"' . $needle . '" nicht in "' . mb_substr($haystack, 0, 200) . '"');
        }
    }

    /** @param class-string<\Throwable> $class */
    public static function assertThrows(string $class, callable $fn, ?string $contains = null): \Throwable
    {
        try {
            $fn();
        } catch (\Throwable $e) {
            if (!$e instanceof $class) {
                throw new AssertionFailed('Erwartete Exception ' . $class . ', erhalten ' . get_class($e) . ': ' . $e->getMessage());
            }
            if ($contains !== null && !str_contains($e->getMessage(), $contains) && !(property_exists($e, 'errorCode') && str_contains((string) $e->errorCode, $contains))) {
                throw new AssertionFailed('Exception-Meldung enthält nicht "' . $contains . '": ' . $e->getMessage());
            }
            return $e;
        }
        throw new AssertionFailed('Erwartete Exception ' . $class . ' wurde nicht geworfen');
    }

    public function run(): array
    {
        $results = ['passed' => 0, 'failed' => [], 'names' => []];
        foreach (get_class_methods($this) as $m) {
            if (!str_starts_with($m, 'test')) {
                continue;
            }
            $this->setUp();
            try {
                $this->$m();
                $results['passed']++;
                $results['names'][] = $m;
            } catch (\Throwable $e) {
                $results['failed'][] = [$m, $e];
            } finally {
                $this->tearDown();
            }
        }
        return $results;
    }
}

final class AssertionFailed extends \RuntimeException
{
}
