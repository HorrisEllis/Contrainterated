<?php
/*
fixtures.test.php — the language-agnostic suite (fixtures/*.json) passes
through the PHP runner (php/code/fixtures/run.php), every file is a
well-formed, portable fixture, and the runner fails a wrong expectation.
Port of lua/sisoTests/fixtures.test.lua, case by case.
  php php/sisoTests/fixtures.test.php   (from the project root)

Turned into PHP's own counterpart (php-fixture-format SUBSTRATE):
  - "every file is portable": Lua flags data.null and an empty marked
    array; here JSON null is PHP null and Data::decode gives `[]` as
    Json::EmptyArray, so the walk flags PHP null and Json::EmptyArray
    (floats as in Lua).
  - "the command line runs the suite": `cd lua && lua5.4
    code/fixtures/run.lua` becomes `php php/code/fixtures/run.php` from
    the project root, with this process's PHP binary.
  - The inline fixture is written as PHP arrays; step labels stay
    1-based ("steps[2]"), as the contract's and the runner's are.
No case is inapplicable.
*/
declare(strict_types=1);

namespace Siso\Tests\Fixtures;

require_once __DIR__ . '/../code/harness.php';
require_once __DIR__ . '/../code/contracts/fixture_format.php';
require_once __DIR__ . '/../code/kit/data.php';
require_once __DIR__ . '/../code/fixtures/run.php';

use Siso\Contracts\FixtureFormat as F;
use Siso\Fixtures\Runner;
use Siso\Harness\H;
use Siso\Kit\Data;
use Siso\Kit\Json;

const ROOT = __DIR__ . '/../..';
const DIR = ROOT . '/fixtures';

function joined(array $list): string
{
    return implode('; ', $list);
}

function has(array $list, string $needle): bool
{
    foreach ($list as $s) {
        if (str_contains($s, $needle)) {
            return true;
        }
    }
    return false;
}

// ── the suite on disk ───────────────────────────────────────────

function files(): array
{
    $files = [];
    foreach (is_dir(DIR) ? (scandir(DIR) ?: []) : [] as $name) {
        if (str_ends_with($name, '.json')) {
            $files[] = $name;
        }
    }
    sort($files, SORT_STRING);
    return $files;
}

/** a file decoded by the kit, or ['decodeError' => why] */
function load(string $name): mixed
{
    $text = file_get_contents(DIR . '/' . $name);
    $why = null;
    $v = Data::decode($text === false ? '' : $text, $why);
    return $why !== null ? ['decodeError' => $why] : $v;
}

$files = files();
$decoded = [];
foreach ($files as $name) {
    $decoded[$name] = load($name);
}

H::test('the suite exists', function () use ($files): void {
    H::assert(count($files) >= 15, 'fixtures/ holds ' . count($files) . ' files');
});

H::test('every file decodes and is a well-formed fixture', function () use ($decoded): void {
    $bad = [];
    foreach ($decoded as $name => $f) {
        if (is_array($f) && isset($f['decodeError'])) {
            $bad[] = $name . ': ' . $f['decodeError'];
        } else {
            foreach (F::problems($f) as $s) {
                $bad[] = $name . ': ' . $s;
            }
        }
    }
    H::assert($bad === [], joined($bad));
});

// nothing in a file may depend on PHP: integers only, no null, no empty arrays
H::test('every file is portable', function () use ($decoded): void {
    $bad = [];
    $walk = static function (mixed $v, string $where) use (&$walk, &$bad): void {
        if ($v === null) {
            $bad[] = $where . ': null';
        } elseif (is_float($v)) {
            $bad[] = $where . ': float';
        } elseif ($v === Json::EmptyArray) {
            $bad[] = $where . ': empty array';
        } elseif (is_array($v)) {
            foreach ($v as $k => $x) {
                $walk($x, $where . '.' . $k);
            }
        }
    };
    foreach ($decoded as $name => $f) {
        $walk($f, $name);
    }
    H::assert($bad === [], joined($bad));
});

H::test('the suite covers each rule and concession the core pins', function () use ($decoded): void {
    $covered = [];
    foreach ($decoded as $f) {
        $covers = is_array($f) && is_array($f['covers'] ?? null) ? $f['covers'] : [];
        foreach ($covers as $c) {
            $covered[$c] = true;
        }
    }
    $missing = [];
    foreach (['Rule 1', 'Rule 3', 'Rule 4', 'K2', 'K3', 'K4', 'K5', 'K6', 'The outside'] as $item) {
        if (!isset($covered[$item])) {
            $missing[] = $item;
        }
    }
    H::assert($missing === [], 'not covered: ' . joined($missing));
});

H::test('every fixture passes through the runner', function () use ($decoded): void {
    $bad = [];
    foreach ($decoded as $name => $f) {
        $r = Runner::run($f);
        if ($r['passed'] !== $r['total'] || $r['problems'] !== []) {
            $bad[] = $name . ' ' . $r['passed'] . '/' . $r['total'] . ': ' . joined($r['problems']);
        }
    }
    H::assert($bad === [], joined($bad));
});

H::test('the command line runs the suite and reports it', function () use ($files): void {
    $cmd = 'cd ' . escapeshellarg(ROOT) . ' && ' . escapeshellarg(PHP_BINARY) . ' php/code/fixtures/run.php 2>&1';
    $out = [];
    $code = 1;
    exec($cmd, $out, $code);
    $last = $out === [] ? null : $out[count($out) - 1];
    H::assert($code === 0, 'the runner exited non-zero: ' . H::show($last));
    $m = [];
    H::assert(is_string($last) && preg_match('/^fixtures: (\d+) files, (\d+)\/(\d+) steps passed$/', $last, $m) === 1, 'final line: ' . H::show($last));
    H::assertEqual((int)$m[1], count($files), 'files');
    H::assertEqual($m[2], $m[3], 'steps passed');
});

// ── the runner fails a wrong expectation ────────────────────────

// a passing fixture, and copies of it with one expectation wrong
function base(): array
{
    return [
        'format' => 1,
        'name' => 'inline',
        'covers' => ['K1'],
        'gates' => [
            ['signature' => 'a', 'does' => ['emitOne' => ['type' => 'b', 'data' => ['v' => 1]]]],
            ['signature' => 'b', 'does' => ['emit' => [['type' => 'c'], ['type' => 'd']]]],
            ['signature' => 'd', 'does' => ['throw' => 'd failed']],
            ['signature' => 'loop', 'does' => ['emitOne' => ['type' => 'loop']]],
            ['signature' => 'x', 'does' => ['forward' => 'y']],
        ],
        'steps' => [
            ['emit' => ['type' => 'x', 'data' => ['n' => 2]], 'expect' => ['settled' => true,
                'trace' => [['type' => 'x', 'claimed' => true, 'then' => [['type' => 'y', 'claimed' => false]]]]]],
            ['emit' => ['type' => 'a'], 'expect' => ['rejected' => ['code' => 'SISO_GATE_THREW', 'eventType' => 'd', 'cause' => 'd failed',
                'at' => ['a', 'b', 'd']]]],
            ['emit' => ['type' => 'loop'], 'expect' => ['rejected' => ['code' => 'SISO_SELF_LOOP', 'eventType' => 'loop', 'at' => ['loop']]]],
            ['register' => ['signature' => 'x', 'does' => ['nothing' => true]], 'refused' => true],
            ['sample' => ['residue' => [['type' => 'y', 'data' => ['n' => 2]]], 'gateCount' => 5]],
            ['take' => [['type' => 'y', 'data' => ['n' => 2]]]],
        ],
    ];
}

/** $stepIndex counts from 1, as the runner's labels do */
function failsAt(string $label, callable $mutate, int $stepIndex, string $needle): void
{
    H::test('the runner fails ' . $label, function () use ($label, $mutate, $stepIndex, $needle): void {
        $f = base();
        $mutate($f);
        $r = Runner::run($f);
        H::assert($r['passed'] === $r['total'] - 1, $label . ': ' . $r['passed'] . '/' . $r['total'] . ' passed, expected one failure: ' . joined($r['problems']));
        H::assert(has($r['problems'], 'steps[' . $stepIndex . ']'), $label . ': the failure is not at step ' . $stepIndex . ': ' . joined($r['problems']));
        H::assert(has($r['problems'], $needle), $label . ": no '" . $needle . "' in " . joined($r['problems']));
    });
}

H::test('the inline fixture passes as written', function (): void {
    $r = Runner::run(base());
    H::assert($r['passed'] === $r['total'] && $r['problems'] === [], $r['passed'] . '/' . $r['total'] . ': ' . joined($r['problems']));
});

failsAt('a wrong residue in a take', static function (array &$f): void {
    $f['steps'][5]['take'][0]['data']['n'] = 3;
}, 6, 'residue');
failsAt('an extra copy in a sample', static function (array &$f): void {
    $f['steps'][4]['sample']['residue'][1] = ['type' => 'y', 'data' => ['n' => 2]];
}, 5, 'residue');
failsAt('a wrong gate count', static function (array &$f): void {
    $f['steps'][4]['sample']['gateCount'] = 4;
}, 5, 'gateCount');
failsAt('a wrong trace', static function (array &$f): void {
    $f['steps'][0]['expect']['trace'][0]['then'][0]['claimed'] = true;
}, 1, 'trace');
failsAt('a wrong code', static function (array &$f): void {
    $f['steps'][2]['expect']['rejected']['code'] = 'SISO_CYCLE';
}, 3, 'code');
failsAt('a wrong "at"', static function (array &$f): void {
    $f['steps'][1]['expect']['rejected']['at'] = ['a', 'b'];
}, 2, 'at a>b');
failsAt('an "at" that reaches no delivery', static function (array &$f): void {
    $f['steps'][1]['expect']['rejected']['at'] = ['a', 'c', 'd'];
}, 2, '0 deliveries');
failsAt('a wrong cause', static function (array &$f): void {
    $f['steps'][1]['expect']['rejected']['cause'] = 'other';
}, 2, 'cause');
failsAt('a wrong event type', static function (array &$f): void {
    $f['steps'][2]['expect']['rejected']['eventType'] = 'a';
}, 3, 'eventType');
failsAt('a settled expectation on a rejection', static function (array &$f): void {
    $f['steps'][1]['expect'] = 'settled';
}, 2, 'expected settled');
failsAt('a rejection expected of a settled entry', static function (array &$f): void {
    $f['steps'][0]['expect'] = ['rejected' => ['code' => 'SISO_NOT_EVENT']];
}, 1, 'it settled');
failsAt('a refused registration expected to succeed', static function (array &$f): void {
    $f['steps'][3]['refused'] = false;
}, 4, 'refused');
failsAt('an accepted registration expected to be refused', static function (array &$f): void {
    $f['steps'][3]['register']['signature'] = 'fresh';
    $f['steps'][4]['sample']['gateCount'] = 6;
}, 4, 'expected refused');

H::test('the runner fails a malformed fixture', function (): void {
    $f = base();
    $f['steps'][1]['expect']['rejected']['code'] = 'NOPE';
    $r = Runner::run($f);
    H::assertEqual($r['passed'], 0, 'passed');
    H::assert(has($r['problems'], 'shape'), joined($r['problems']));
});

H::test('a gate built from { throw } raises exactly its message', function (): void {
    $f = base();
    $f['steps'] = [['emit' => ['type' => 'd'], 'expect' => ['rejected' => ['code' => 'SISO_GATE_THREW', 'cause' => 'd failed', 'at' => ['d']]]]];
    $r = Runner::run($f);
    H::assert($r['passed'] === 1, joined($r['problems']));
});

H::finish('fixtures');
