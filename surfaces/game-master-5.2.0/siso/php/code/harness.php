<?php
/*
harness — the test harness every PHP suite reports through.

Port of lua/code/harness.lua (itself the port of core 11.1
`tests/runner.js`, doc/log/legacy-js/tests/runner.js), unchanged in
behaviour. Shared platform: no phase writes it.

  require_once __DIR__ . '/../code/harness.php';
  use Siso\Harness\H;
  H::test($name, $fn)  H::assert($c, $msg)  H::assertEqual($a, $e, $label)
  H::assertThrows($fn, $substring, $label)  H::fail($msg)  H::finish($suite)

Final line: "{suite}: {passed}/{total} passed"
        or  "{suite}: {passed}/{total} passed, {failed} FAILED"
Assertion failures are thrown as Siso\Harness\AssertionFailed, so the
report shows the message without a file:line prefix; any other
\Throwable is reported with its own getMessage() (a rejection shows its
message).

SUBSTRATE: Lua's harness keeps its counters in module locals; here they
are H's private static properties (test platform, not core: the core's
no-static-state rule does not bind the harness). assertEqual is ===
(same type and value: 1 and 1.0 differ, as Lua's math.type check makes
them differ). PHP throws only objects, so "any other error" is any
\Throwable. finish() exits with code 0 when every test passed, else 1.
*/
declare(strict_types=1);

namespace Siso\Harness;

final class AssertionFailed extends \Exception
{
}

final class H
{
    private static int $passed = 0;
    private static int $failed = 0;
    /** @var list<array{name: string, message: string}> */
    private static array $failures = [];

    private function __construct()
    {
    }

    private static function raise(string $message): never
    {
        throw new AssertionFailed($message);
    }

    // JSON-ish rendering for failure messages (the JS runner uses
    // JSON.stringify). Only for messages; never for comparison.
    public static function show(mixed $v): string
    {
        if ($v === null) {
            return 'null';
        }
        if (is_string($v)) {
            return '"' . addcslashes($v, "\"\\\0..\37") . '"';
        }
        if (is_float($v)) {
            return sprintf('%.17g', $v) . '(float)';
        }
        if (is_int($v)) {
            return (string)$v;
        }
        if (is_bool($v)) {
            return $v ? 'true' : 'false';
        }
        if (is_array($v)) {
            $s = json_encode($v, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PARTIAL_OUTPUT_ON_ERROR);
            return $s === false ? 'array(' . count($v) . ')' : $s;
        }
        if ($v instanceof \UnitEnum) {
            return get_class($v) . '::' . $v->name;
        }
        return get_debug_type($v);
    }

    public static function test(string $name, callable $fn): void
    {
        try {
            $fn();
            self::$passed++;
        } catch (\Throwable $err) {
            self::$failed++;
            $message = $err->getMessage();
            self::$failures[] = ['name' => $name, 'message' => $message];
            echo '  ✗  ' . $name . "\n";
            echo '     ' . $message . "\n";
        }
    }

    public static function assert(mixed $condition, ?string $message = null): void
    {
        if (!$condition) {
            self::raise($message ?? 'assertion failed');
        }
    }

    // Strict equality: same type and value (1 and 1.0 differ).
    public static function assertEqual(mixed $actual, mixed $expected, ?string $label = null): void
    {
        if ($actual !== $expected) {
            $prefix = $label !== null ? $label . ': ' : '';
            self::raise($prefix . 'expected ' . self::show($expected) . ', got ' . self::show($actual));
        }
    }

    public static function assertClose(float|int $actual, float|int $expected, float $epsilon = 1e-10, ?string $label = null): void
    {
        if (abs($actual - $expected) > $epsilon) {
            $prefix = $label !== null ? $label . ': ' : '';
            self::raise($prefix . 'expected ≈' . self::show($expected) . ', got ' . self::show($actual));
        }
    }

    public static function assertThrows(callable $fn, ?string $substring = null, ?string $label = null): void
    {
        $prefix = $label !== null ? $label . ': ' : '';
        try {
            $fn();
        } catch (\Throwable $err) {
            $message = $err->getMessage();
            if ($substring !== null && $substring !== '' && !str_contains($message, $substring)) {
                self::raise($prefix . "expected error containing '" . $substring . "', got '" . $message . "'");
            }
            return;
        }
        self::raise($prefix . 'expected error, none thrown');
    }

    public static function fail(string $message): never
    {
        self::raise($message);
    }

    /** @return array{0: int, 1: int} passed, failed */
    public static function counts(): array
    {
        return [self::$passed, self::$failed];
    }

    public static function report(string $suite): int
    {
        $total = self::$passed + self::$failed;
        echo "\n";
        if (self::$failed === 0) {
            echo $suite . ': ' . self::$passed . '/' . $total . " passed\n";
        } else {
            // the failures first, so the summary is always the last line
            foreach (self::$failures as $f) {
                echo '  FAIL: ' . $f['name'] . ' — ' . $f['message'] . "\n";
            }
            echo $suite . ': ' . self::$passed . '/' . $total . ' passed, ' . self::$failed . " FAILED\n";
        }
        return self::$failed;
    }

    // report() and exit with the JS runner's code (0 on pass).
    public static function finish(string $suite): never
    {
        $n = self::report($suite);
        exit($n === 0 ? 0 : 1);
    }

    public static function reset(): void
    {
        self::$passed = 0;
        self::$failed = 0;
        self::$failures = [];
    }
}
