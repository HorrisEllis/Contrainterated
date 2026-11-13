<?php
/*
kit-run.test.php — php/code/kit/run.php, the suite runner (kit-api,
"Command line"). Port of the run-related cases of
lua/sisoTests/kit-helper.test.lua ("run: …"), against the fixture suites
under php/sisoTests/kit-fixtures/run/ (fixtures for the runner, not project
suites; the runner never enters kit-fixtures/ when run on php/sisoTests).
  php php/sisoTests/kit-run.test.php      (from the project root)
Expected values: the fixture suites' own result lines, written by hand,
and the totals summed from them by hand.

run.php is a command, not a module (kit-api names only its command line),
so Lua's unit calls (R.underFixtures, R.suiteFiles, R.result) become runs
of the command on fixture trees that hold each case:
  - "fixtures directories are never entered": underFixtures' paths become
    directories named fixtures, kit-fixtures, sub_fixtures and
    nested/fixtures, each holding a suite that fails if run; a file named
    fixtures.test.php is a suite; suiteFiles' listing becomes the suite
    lines of the report.
  - "totals every suite, … exits 0": as Lua's, without the gate group.
  - "a failing suite, or one without a result line, fails the run": as
    Lua's; R.result's two probes become fixture suites whose last result
    line fails or passes after an earlier one that does the opposite.
Not applicable (kit-api SUBSTRATE: kit.helper and the digest are not
ported, so run.php has no gate group and no "digests:" line):
  - "run: a gate test is a file that requires kit.helper and calls its
    done()" (no per-gate test API to recognise);
  - "run: a gate test whose digests disagree fails the run" (no digest);
  - the "digests: 1 gate tests, 1 agree" line and "ok   gate" lines of
    "run: totals every suite, runs gate tests twice, …".
Added, PHP's own: byte-wise order, one process per suite, stderr joined,
the default tests directory, extra arguments ignored, an empty tree.
*/
declare(strict_types=1);

namespace Siso\Tests\KitRun;

require_once __DIR__ . '/../code/harness.php';

use Siso\Harness\H;

const ROOT = __DIR__ . '/../..';
const RUN = 'php/code/kit/run.php';
const FX = 'php/sisoTests/kit-fixtures/run/';

/** runs a command with no shell → [output (stderr joined), exit code] */
function sh(array $cmd, string $cwd = ROOT): array
{
    $h = proc_open($cmd, [0 => ['file', '/dev/null', 'r'], 1 => ['pipe', 'w'], 2 => ['redirect', 1]], $pipes, $cwd);
    if (!is_resource($h)) {
        H::fail('could not start ' . implode(' ', $cmd));
    }
    $out = (string)stream_get_contents($pipes[1]);
    fclose($pipes[1]);
    return [$out, proc_close($h)];
}

/** run.php on a fixture tree (or any arguments), from the project root */
function run(string ...$args): array
{
    return sh([PHP_BINARY, RUN, ...$args]);
}

function lastLine(string $out): ?string
{
    $last = null;
    foreach (explode("\n", $out) as $line) {
        if ($line !== '') {
            $last = $line;
        }
    }
    return $last;
}

/** the report's suite lines: [status, file, result line] in printed order */
function suiteLines(string $out): array
{
    $rows = [];
    foreach (explode("\n", $out) as $line) {
        if (preg_match('/^  (ok|FAIL) +(\S+) +(.*)$/', $line, $m) === 1) {
            $rows[] = [$m[1], $m[2], $m[3]];
        }
    }
    return $rows;
}

H::test('run: fixtures directories are never entered', function (): void {
    [$out] = run(FX . 'tests');
    H::assert(!str_contains($out, 'never'), 'a fixtures directory was entered: ' . $out);
    $files = array_map(static fn (array $r): string => $r[1], suiteLines($out));
    H::assertEqual(implode(',', $files), 'B-upper.test.php,a-lower.test.php,fixtures.test.php,nested/deep/inner.test.php',
        'fixtures/, kit-fixtures/, sub_fixtures/ and nested/fixtures/ skipped; fixtures.test.php and a non-suite file handled');
});

H::test('run: totals every suite, skips fixtures, exits 0', function (): void {
    [$out, $code] = run(FX . 'tests');
    H::assertEqual($code, 0, 'exit code; output: ' . $out);
    H::assert(str_contains($out, "  ok   a-lower.test.php                              a-lower: 2/2 passed\n"), $out);
    H::assert(str_contains($out, "  ok   nested/deep/inner.test.php                    inner: 1/1 passed\n"), $out);
    H::assertEqual(lastLine($out), 'run: 4 suites, 5/5 passed');
});

H::test('run: suites run in byte-wise path order', function (): void {
    [$out] = run(FX . 'tests');
    $files = array_map(static fn (array $r): string => $r[1], suiteLines($out));
    H::assertEqual($files[0] ?? null, 'B-upper.test.php', "'B' (0x42) sorts before 'a' (0x61)");
    $sorted = $files;
    usort($sorted, 'strcmp');
    H::assertEqual($files, $sorted, 'printed order is strcmp order');
});

H::test('run: each suite runs in its own process', function (): void {
    [$out] = run(FX . 'tests');
    foreach (suiteLines($out) as [, $file, $last]) {
        H::assert(!str_contains($last, '3/3') && !str_contains($last, '5/5'), $file . ' reported a running total: ' . $last);
    }
    [$edges] = run(FX . 'edges');
    H::assert(str_contains($edges, 'argv: 1/1 passed'), 'a suite saw another suite or extra arguments: ' . $edges);
});

H::test('run: a failing suite, or one without a result line, fails the run', function (): void {
    [$out, $code] = run(FX . 'failing');
    H::assert($code !== 0, 'a failing suite fails the run: ' . $out);
    $status = [];
    foreach (suiteLines($out) as [$s, $file]) {
        $status[$file] = $s;
    }
    foreach (['assert-failed', 'crash', 'liar', 'silent', 'uneven', 'z-late-failure'] as $name) {
        H::assertEqual($status[$name . '.test.php'] ?? null, 'FAIL', $name);
    }
    H::assert(str_contains($out, "(no result line)\nsilent output, no result line\n"), 'the output of a suite with no result line is shown: ' . $out);
    H::assert(str_contains($out, 'about to crash'), 'a crashed suite\'s output is shown');
    H::assert(str_contains($out, 'liar: 1/1 passed'), 'a non-zero exit fails a passing line');
    H::assertEqual(lastLine($out), 'run: 6 suites, 3/6 passed, 6 FAILED (assert-failed.test.php, crash.test.php, liar.test.php, '
        . 'silent.test.php, uneven.test.php, z-late-failure.test.php)');
});

H::test('run: a fixtures directory given as the tests directory is run (only directories below it are skipped)', function (): void {
    [$out, $code] = run(FX . 'tests/fixtures');
    H::assert($code !== 0, 'the never suite fails: ' . $out);
    H::assertEqual(lastLine($out), 'run: 1 suites, 0/1 passed, 1 FAILED (never.test.php)');
});

H::test('run: the last result line decides, and stderr is read with stdout', function (): void {
    [$out, $code] = run(FX . 'edges');
    H::assertEqual($code, 0, 'exit code; output: ' . $out);
    H::assert(str_contains($out, 'last-line: 3/3 passed'), $out);
    H::assert(str_contains($out, 'stderr: 2/2 passed'), $out);
    H::assertEqual(lastLine($out), 'run: 3 suites, 6/6 passed');
});

H::test('run: a missing tests directory fails', function (): void {
    [$out, $code] = run(FX . 'no-such-dir');
    H::assert($code !== 0, 'exit code');
    H::assertEqual(lastLine($out), 'run: no tests directory ' . FX . 'no-such-dir');
});

H::test('run: a tree with no suites is 0 suites and exits 0', function (): void {
    [$out, $code] = run(FX . 'nosuites');
    H::assertEqual($code, 0, 'exit code; output: ' . $out);
    H::assertEqual(lastLine($out), 'run: 0 suites, 0/0 passed');
});

H::test('run: the tests directory defaults to php/sisoTests under the current directory', function (): void {
    [$out, $code] = sh([PHP_BINARY, realpath(ROOT . '/' . RUN)], ROOT . '/' . FX . 'root');
    H::assertEqual($code, 0, 'exit code; output: ' . $out);
    H::assert(str_contains($out, 'only.test.php'), $out);
    H::assertEqual(lastLine($out), 'run: 1 suites, 1/1 passed');
});

H::test('run: further arguments are ignored, and a trailing slash is the same directory', function (): void {
    [$a] = run(FX . 'tests');
    [$b, $code] = run(FX . 'tests/', 'ignored', FX . 'failing');
    H::assertEqual($code, 0, 'exit code; output: ' . $b);
    H::assertEqual($b, $a);
});

H::test('run.php defines nothing: it is a command', function (): void {
    $src = (string)file_get_contents(ROOT . '/' . RUN);
    $tokens = \PhpToken::tokenize($src);
    foreach ($tokens as $i => $t) {
        if ($t->is([T_CLASS, T_INTERFACE, T_TRAIT, T_ENUM, T_NAMESPACE])) {
            H::fail('run.php declares a ' . $t->text);
        }
        if ($t->is(T_FUNCTION)) {
            // only closures: `function` followed by `(` or `use`
            $j = $i + 1;
            while (isset($tokens[$j]) && $tokens[$j]->isIgnorable()) {
                $j++;
            }
            H::assert(($tokens[$j]->text ?? '') === '(', 'run.php declares a named function');
        }
    }
});

H::finish('kit-run');
