<?php
/*
kit/run.php — every suite in the tests directory, with totals (kit-api,
"Command line"). Port of the suite half of lua/code/kit/run.lua.

  php php/code/kit/run.php [tests-dir]    (run from the project root;
                                           tests-dir defaults to php/sisoTests;
                                           further arguments are ignored)

A suite is any `*.test.php` regular file under the tests directory, at any
depth, except under a fixtures directory: a directory named `fixtures` or
ending in `-fixtures` / `_fixtures` (`kit-fixtures/`, `substrate/fixtures/`)
below the tests directory is never entered. Symbolic links are not
followed (as `find -type f`). Suites run in byte-wise path order (strcmp
on the path relative to the tests directory); their order carries no
meaning.

Each suite runs in its own process: this PHP binary (PHP_BINARY) given the
suite's path, from the current directory, stderr joined to stdout.

A suite passes when it exits 0 and its last "<suite>: P/T passed" line has
P = T and no FAILED. One line per suite:
  "  ok   <path>  <its result line>"   or   "  FAIL <path>  …"
(a failing suite with no result line also shows its whole output). Final
line:
  run: S suites, P/T passed            (", F FAILED (files…)" otherwise)
Exit 0 only when every suite passes. A tests directory that does not exist
prints "run: no tests directory <dir>" and exits 1.

Not ported (kit-api SUBSTRATE: kit.helper, the digest and the census are
not ported): the gate group, the two-process digest run and its
"digests:" line, the group totals, and Lua's "shape:" line exclusion
(printed only by the helper). The file defines no class or function: it
is a command, and requiring it runs it.
*/
declare(strict_types=1);

(static function (array $argv): never {
    // A directory name that names a fixtures directory.
    $isFixtureDir = static fn (string $name): bool => $name === 'fixtures'
        || str_ends_with($name, '-fixtures') || str_ends_with($name, '_fixtures');

    // Every suite file under $dir, relative to it, sorted byte-wise.
    // Iterative: a stack of directories still to list.
    $suiteFiles = static function (string $dir) use ($isFixtureDir): array {
        $files = [];
        $todo = [''];
        while ($todo !== []) {
            $rel = array_pop($todo);
            $names = @scandir($dir . $rel);
            if ($names === false) {
                continue;
            }
            foreach ($names as $name) {
                if ($name === '.' || $name === '..') {
                    continue;
                }
                $path = $dir . $rel . $name;
                if (is_link($path)) {
                    continue;
                }
                if (is_dir($path)) {
                    if (!$isFixtureDir($name)) {
                        $todo[] = $rel . $name . '/';
                    }
                } elseif (is_file($path) && str_ends_with($name, '.test.php')) {
                    $files[] = $rel . $name;
                }
            }
        }
        usort($files, 'strcmp');
        return $files;
    };

    // The last result line of a suite's output, and its counts.
    $result = static function (string $out): array {
        $last = null;
        foreach (preg_split('/\n/', $out) as $line) {
            if (preg_match('/^.*?: \d+\/\d+ passed/', $line) === 1) {
                $last = $line;
            }
        }
        if ($last === null) {
            return [null, 0, 0];
        }
        preg_match('/: (\d+)\/(\d+) passed/', $last, $m);
        return [$last, (int)$m[1], (int)$m[2]];
    };

    // Runs one suite in its own process → [output, exit code].
    $runSuite = static function (string $path): array {
        $h = proc_open([PHP_BINARY, $path], [0 => ['file', '/dev/null', 'r'], 1 => ['pipe', 'w'], 2 => ['redirect', 1]], $pipes);
        if (!is_resource($h)) {
            return ['run: could not start ' . $path, 1];
        }
        $out = stream_get_contents($pipes[1]);
        fclose($pipes[1]);
        $code = proc_close($h);
        return [$out === false ? '' : $out, $code];
    };

    $dir = $argv[1] ?? 'php/sisoTests';
    if (!is_dir($dir)) {
        echo 'run: no tests directory ' . $dir . "\n";
        exit(1);
    }
    $base = rtrim($dir, '/') . '/';

    $suites = 0;
    $passed = 0;
    $total = 0;
    $failed = [];
    foreach ($suiteFiles($base) as $file) {
        [$out, $code] = $runSuite($base . $file);
        [$last, $p, $t] = $result($out);
        $good = $code === 0 && $last !== null && $p === $t && !str_contains($last, 'FAILED');
        $suites++;
        $passed += $p;
        $total += $t;
        printf("  %-4s %-45s %s\n", $good ? 'ok' : 'FAIL', $file, $last ?? '(no result line)');
        if (!$good) {
            $failed[] = $file;
            if ($last === null) {
                echo $out, str_ends_with($out, "\n") || $out === '' ? '' : "\n";
            }
        }
    }

    echo "\n";
    if ($failed === []) {
        echo 'run: ' . $suites . ' suites, ' . $passed . '/' . $total . " passed\n";
    } else {
        echo 'run: ' . $suites . ' suites, ' . $passed . '/' . $total . ' passed, '
            . count($failed) . ' FAILED (' . implode(', ', $failed) . ")\n";
    }
    exit($failed === [] ? 0 : 1);
})($argv ?? []);
