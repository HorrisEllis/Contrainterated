<?php
/**
 * Gate census — every registered gate has exactly one test.
 *
 *   1. Lists the gates the project registers, from the code
 *      (GameStreamFactory::gateFactories), and checks the list
 *      against the gate classes in src/Gates.
 *   2. For each signature, finds sisoTests/<signature>.test.php and
 *      checks it obtains the gate through the helper, gate('<signature>').
 *   3. Runs every per-gate test file.
 *   4. Checks each file's accepted cases share one output shape: the
 *      event types emitted, the reply type each boundary request names,
 *      the resources each io_write writes, and the HTTP status of an
 *      http_response (see shapeOf in helper.php). Cases named "rejected …" are
 *      rejections and are excluded; "accepted (class X) …" cases belong
 *      to a classifier and are compared within their class; an
 *      "accepted (empty fan-out) …" case may emit a subset (an io_write
 *      with fewer resources counts as a subset of one with more).
 *
 * Exit code 0 only when the census is clean and every test passes.
 * Final line: census: {passed}/{total} passed[, {failed} FAILED]
 *
 * Run: php sisoTests/census.php
 */

require_once __DIR__ . '/../autoload.php';

use GameMaster\GameStreamFactory;

$factory = new GameStreamFactory();
$signatures = array_keys($factory->gateFactories());
$problems = [];

// 1. Registered gates vs gate classes on disk
$registeredClasses = [];
foreach ($signatures as $sig) {
    $registeredClasses[] = (new ReflectionClass($factory->definition($sig)))->getShortName();
}
$onDisk = array_map(fn($f) => basename($f, '.php'), glob(__DIR__ . '/../src/Gates/*.php'));
foreach (array_diff($onDisk, $registeredClasses) as $cls) {
    $problems[] = "gate class {$cls} is not registered";
}

// 2–4. One test per gate, run, shapes
$shapesFile = tempnam(sys_get_temp_dir(), 'siso-shapes');
$passed = 0;
$rows = [];

foreach ($signatures as $i => $sig) {
    $file = __DIR__ . "/{$sig}.test.php";
    $class = $registeredClasses[$i];
    if (!is_file($file)) {
        $problems[] = "{$sig} ({$class}): no test file";
        $rows[] = sprintf("  ✗ %-28s %-28s missing", $sig, $class);
        continue;
    }
    if (!str_contains(file_get_contents($file), "gate('{$sig}'")) {
        $problems[] = "{$sig}: test does not obtain the gate through gate('{$sig}')";
    }

    file_put_contents($shapesFile, '');
    $output = [];
    exec('SISO_SHAPES=' . escapeshellarg($shapesFile) . ' ' . escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg($file) . ' 2>&1', $output, $code);
    $last = trim((string) end($output));

    // Output shapes
    $accepted = [];
    $classes = [];
    $subsets = [];
    $cases = 0;
    $rejections = 0;
    foreach (file($shapesFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $line) {
        $rec = json_decode($line, true);
        $types = $rec['types'];
        sort($types);
        $cases++;
        if (str_starts_with($rec['case'], 'rejected')) {
            $rejections++;
        } elseif (preg_match('/^accepted \(class ([^)]+)\)/', $rec['case'], $m)) {
            $classes[$m[1]][json_encode($types)] = true;
        } elseif (str_starts_with($rec['case'], 'accepted (empty fan-out)')) {
            $subsets[] = $types;
        } else {
            $accepted[json_encode($types)] = true;
        }
    }
    if (count($accepted) > 1) {
        $problems[] = "{$sig}: accepted cases have different output shapes: " . implode(' | ', array_keys($accepted));
    }
    foreach ($classes as $name => $shapes) {
        if (count($shapes) > 1) {
            $problems[] = "{$sig}: class {$name} has different output shapes";
        }
    }
    $shape = $accepted ? json_decode(array_key_first($accepted), true) : [];
    foreach ($subsets as $types) {
        foreach ($types as $t) {
            if (in_array($t, $shape, true)) {
                continue;
            }
            // an io_write naming fewer resources than an accepted one
            $fits = false;
            if (preg_match('/^(io_write→[^{]+)\{(.*)\}$/', $t, $m)) {
                foreach ($shape as $a) {
                    if (preg_match('/^' . preg_quote($m[1], '/') . '\{(.*)\}$/', $a, $n)
                        && !array_diff(array_filter(explode(',', $m[2])), explode(',', $n[1]))) {
                        $fits = true;
                    }
                }
            }
            if (!$fits) {
                $problems[] = "{$sig}: empty fan-out case emits {$t}, outside the accepted shape";
            }
        }
    }

    $ok = $code === 0;
    $passed += $ok ? 1 : 0;
    $shapeText = $classes
        ? implode(' / ', array_map(fn($k, $v) => "{$k}: " . implode(',', json_decode(array_key_first($v), true)), array_keys($classes), $classes))
        : implode(',', $shape);
    $rows[] = sprintf("  %s %-28s %-28s %-40s %s", $ok ? '✓' : '✗', $sig, $class, $last, "{$rejections} rejection case(s); shape [{$shapeText}]");
    if (!$ok) {
        $problems[] = "{$sig}: test failed — {$last}";
    }
}
unlink($shapesFile);

echo "Gate census — " . count($signatures) . " registered gates\n";
echo implode("\n", $rows), "\n\n";
if ($problems) {
    echo "Problems:\n  - " . implode("\n  - ", $problems) . "\n\n";
}
$total = count($signatures);
$failed = $total - $passed;
$untested = array_values(array_filter($signatures, fn($s) => !is_file(__DIR__ . "/{$s}.test.php")));
echo "gates: {$total}, tested: " . ($total - count($untested)) . ", untested: " . ($untested ? implode(', ', $untested) : 'none') . "\n";
echo $failed === 0 && !$problems ? "census: {$passed}/{$total} passed\n" : "census: {$passed}/{$total} passed, " . max($failed, count($problems)) . " FAILED\n";
exit($failed === 0 && !$problems ? 0 : 1);
