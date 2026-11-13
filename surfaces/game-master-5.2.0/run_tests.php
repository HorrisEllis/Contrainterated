<?php
/**
 * GameMaster test suite — runs every suite in its own process.
 *
 *   siso/ core suites               SISO core 13.1.0 (vendored): its own PHP suites,
 *                                   the shared fixture suite, the JS suites, and the
 *                                   pin of the vendored files (tests/siso-vendor.test.php)
 *   sisoTests/client/census.mjs     client gate census + one per-gate test per gate (Node)
 *   sisoTests/census.php            gate census + one per-gate test per gate
 *   tests/client-scenarios.test.mjs the client stream through its driver (scripted server)
 *   tests/templates.test.php        world template data
 *   tests/scenarios.test.php        end-to-end through the HTTP handlers (mock AI)
 *
 * Usage: php run_tests.php [-v]   (-v prints every suite's full output)
 * No PHPUnit, no configuration; PHP's assert() is not used.
 */

// [label, command, working directory]
$php = escapeshellarg(PHP_BINARY);
$suites = [
    ['siso/php (core 13.1.0)', "{$php} php/code/kit/run.php", 'siso'],
    ['siso/fixtures (php)', "{$php} php/code/fixtures/run.php", 'siso'],
    ['siso/js (core 13.1.0)', 'node js/code/kit/run.mjs', 'siso'],
    ['siso/fixtures (js)', 'node js/code/fixtures/run.mjs', 'siso'],
    ['tests/siso-vendor.test.php', "{$php} tests/siso-vendor.test.php", '.'],
    ['sisoTests/census.php', "{$php} sisoTests/census.php", '.'],
    ['sisoTests/client/census.mjs', 'node sisoTests/client/census.mjs', '.'],
    ['tests/client-scenarios.test.mjs', 'node tests/client-scenarios.test.mjs', '.'],
    ['tests/templates.test.php', "{$php} tests/templates.test.php", '.'],
    ['tests/scenarios.test.php', "{$php} tests/scenarios.test.php", '.'],
];
$verbose = in_array('-v', $argv, true);

echo "GameMaster — test suites\n", str_repeat('=', 50), "\n";
$failed = 0;
foreach ($suites as [$suite, $command, $dir]) {
    $out = [];
    exec('cd ' . escapeshellarg(__DIR__ . "/{$dir}") . " && {$command} 2>&1", $out, $code);
    if ($verbose || $code !== 0) {
        echo implode("\n", $out), "\n";
    }
    $last = trim((string) end($out));
    printf("  %s %-34s %s\n", $code === 0 ? '✓' : '✗', $suite, $last);
    $failed += $code === 0 ? 0 : 1;
}
echo str_repeat('=', 50), "\n";
echo $failed === 0 ? "all suites passed\n" : "{$failed} suite(s) FAILED\n";
exit($failed === 0 ? 0 : 1);
