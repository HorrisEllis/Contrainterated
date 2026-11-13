<?php
/**
 * Minimal test runner. No dependencies.
 * PHP port of the SISO Canon core's tests/runner.js.
 *
 * Reports pass/fail, stops on first failure in a test,
 * continues to next test. Final stdout line:
 *   {suite}: {passed}/{total} passed
 *   {suite}: {passed}/{total} passed, {failed} FAILED
 *
 * Assertions throw; PHP's assert() is not used (it is a
 * no-op under zend.assertions=-1).
 */

namespace SisoTest;

// Warnings and notices fail the test, as api.php and worker.php turn them
// into exceptions (a 500) in production.
set_error_handler(function (int $severity, string $message, string $file, int $line): bool {
    if (!(error_reporting() & $severity)) {
        return false;
    }
    throw new \ErrorException($message, 0, $severity, $file, $line);
});

final class Runner
{
    public static int $passed = 0;
    public static int $failed = 0;
    public static array $failures = [];
    public static string $current = '';
}

function test(string $name, callable $fn): void
{
    Runner::$current = $name;
    try {
        $fn();
        Runner::$passed++;
    } catch (\Throwable $e) {
        Runner::$failed++;
        Runner::$failures[] = ['name' => $name, 'message' => $e->getMessage()];
        echo "  ✗  {$name}\n";
        echo "     {$e->getMessage()}\n";
    }
}

function assertTrue(mixed $condition, string $message = 'assertion failed'): void
{
    if (!$condition) {
        throw new \RuntimeException($message);
    }
}

function show(mixed $v): string
{
    return json_encode($v, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION)
        ?: var_export($v, true);
}

function assertEqual(mixed $actual, mixed $expected, string $label = ''): void
{
    if ($actual !== $expected) {
        $prefix = $label !== '' ? "{$label}: " : '';
        throw new \RuntimeException("{$prefix}expected " . show($expected) . ', got ' . show($actual));
    }
}

function assertContains(string $needle, string $haystack, string $label = ''): void
{
    if (!str_contains($haystack, $needle)) {
        $prefix = $label !== '' ? "{$label}: " : '';
        throw new \RuntimeException("{$prefix}expected to find " . show($needle) . ' in ' . show(mb_substr($haystack, 0, 300)));
    }
}

function assertNotContains(string $needle, string $haystack, string $label = ''): void
{
    if (str_contains($haystack, $needle)) {
        $prefix = $label !== '' ? "{$label}: " : '';
        throw new \RuntimeException("{$prefix}did not expect " . show($needle));
    }
}

function assertThrows(callable $fn, string $substring = '', string $label = ''): void
{
    $prefix = $label !== '' ? "{$label}: " : '';
    try {
        $fn();
    } catch (\Throwable $e) {
        if ($substring !== '' && !str_contains($e->getMessage(), $substring)) {
            throw new \RuntimeException("{$prefix}expected error containing '{$substring}', got '{$e->getMessage()}'");
        }
        return;
    }
    throw new \RuntimeException("{$prefix}expected error, none thrown");
}

/** Print the summary line and return the number of failures. */
function report(string $suite): int
{
    $total = Runner::$passed + Runner::$failed;
    echo "\n";
    foreach (Runner::$failures as $f) {
        echo "  FAIL: {$f['name']} — {$f['message']}\n";
    }
    if (Runner::$failed === 0) {
        echo "{$suite}: " . Runner::$passed . "/{$total} passed\n";
    } else {
        echo "{$suite}: " . Runner::$passed . "/{$total} passed, " . Runner::$failed . " FAILED\n";
    }
    return Runner::$failed;
}
