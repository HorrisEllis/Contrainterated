<?php
/*
fixtures runner — the PHP runner of the language-agnostic suite
(contract fixture-format, php/code/contracts/fixture_format.php). Port of
lua/code/fixtures/run.lua.

  php php/code/fixtures/run.php [dir]     from the project root
    runs every <dir>/*.json (default the project's fixtures/, resolved
    from this file, the suite every instance shares), sorted by name
    (byte-wise), decoding each with the kit's Siso\Kit\Data::decode, and
    prints one line per fixture, then the final line
      "fixtures: F files, P/T steps passed"
    exit 0 only when every step of every file passes.

  (load this file, php/code/fixtures/run.php, then)
  use Siso\Fixtures\Runner;
  Runner::run(array $fixture, ?array $core = null)
    → ['name' => …, 'passed' => P, 'total' => T, 'problems' => [ … ]]
    one decoded fixture (or one written inline as PHP arrays), run on a
    fresh stream with one observer attached. `$core` is
    ['Event' => class-string, 'Gate' => class-string, 'Stream' => class-string
    or a callable returning a stream]; a key left out is the real core's
    (php/code/core/). A test passes another Stream (a factory, Lua's
    `Stream` constructor argument) to run the same fixture against a
    stream it controls.

Matching never happens here: shape is FixtureFormat::problems, residue is
residueMatch, a trace is traceMatch, "at" is recordAt, every batch is
ObserverSeam::batchProblems, every rejection CoreApi::rejectionProblems,
and a batch's rejection is judged against the one thrown by
ObserverSeam::rejectionCopyProblem (the observer's own plain copy, core
13), never by identity.

SUBSTRATE (php-fixture-format): a key is present when array_key_exists
says so (JSON null is PHP null, a value: "emitRaw": null is an entry of
null). Before a fixture value is used as a raw return, an entry or event
data, every Json::EmptyArray in it becomes [] (Lua's copy through the
array mark: arrays are values here, so the copy is the language's and the
one mark is the enum case, recognised by name). A { throw } gate throws
new \RuntimeException($message), and "cause" is compared to
$rejection->cause->getMessage().

No static state: a final class of static functions; the observer's batch
list is a local of run(), captured by reference.
*/
declare(strict_types=1);

namespace Siso\Fixtures;

require_once __DIR__ . '/../core/event.php';
require_once __DIR__ . '/../core/gate.php';
require_once __DIR__ . '/../core/stream.php';
require_once __DIR__ . '/../contracts/fixture_format.php';
require_once __DIR__ . '/../contracts/observer_seam.php';
require_once __DIR__ . '/../contracts/core_api.php';
require_once __DIR__ . '/../kit/data.php';

use Siso\Contracts\CoreApi as API;
use Siso\Contracts\FixtureFormat as F;
use Siso\Contracts\ObserverSeam as SEAM;
use Siso\Kit\Data;

final class Runner
{
    public const CORE = ['Event' => \Siso\Core\Event::class, 'Gate' => \Siso\Core\Gate::class, 'Stream' => \Siso\Core\Stream::class];

    private function __construct()
    {
    }

    // ── gates built from test-only behaviours ───────────────────────

    /** fixture data as PHP data: every Json::EmptyArray becomes [] */
    public static function plain(mixed $v): mixed
    {
        if (F::isEmptyArray($v)) {
            return [];
        }
        if (!is_array($v)) {
            return $v;
        }
        $t = [];
        foreach ($v as $k => $x) {
            $t[$k] = self::plain($x);
        }
        return $t;
    }

    /** the items of a list the format expects (Json::EmptyArray → []) */
    private static function items(mixed $t): array
    {
        return is_array($t) ? $t : [];
    }

    private static function eventOf(string $Event, array $o): object
    {
        $data = array_key_exists('data', $o) ? self::plain($o['data']) : null;
        return new $Event($o['type'], $data);
    }

    private static function outOf(string $Event, mixed $o): mixed
    {
        if (is_array($o) && !array_key_exists('type', $o) && array_key_exists('raw', $o)) {
            return self::plain($o['raw']);
        }
        return self::eventOf($Event, $o);
    }

    private static function transformOf(string $Event, array $does): \Closure
    {
        if (array_key_exists('emit', $does)) {
            $emit = self::items($does['emit']);
            return static function () use ($Event, $emit): array {
                $list = [];
                foreach ($emit as $o) {
                    $list[] = self::outOf($Event, $o);
                }
                return $list;
            };
        }
        if (array_key_exists('emitOne', $does)) {
            $one = $does['emitOne'];
            return static fn (): mixed => self::outOf($Event, $one);
        }
        if (array_key_exists('forward', $does)) {
            $t = $does['forward'];
            return static fn (object $e): object => new $Event($t, $e->data);
        }
        if (array_key_exists('nothing', $does)) {
            return static fn (): mixed => null;
        }
        if (array_key_exists('throw', $does)) {
            $message = $does['throw'];
            return static function () use ($message): never {
                throw new \RuntimeException($message);
            };
        }
        $raw = $does['raw'] ?? null;
        return static fn (): mixed => self::plain($raw);
    }

    private static function gateOf(array $core, array $g): object
    {
        return new $core['Gate']($g['signature'], self::transformOf($core['Event'], $g['does']));
    }

    // ── steps ───────────────────────────────────────────────────────

    private static function addAll(array &$into, string $label, array $list): void
    {
        foreach ($list as $s) {
            $into[] = $label . ': ' . $s;
        }
    }

    private static function check(array &$into, string $label, bool $ok, ?string $why): void
    {
        if (!$ok) {
            $into[] = $label . ': ' . ($why ?? 'null');
        }
    }

    private static function show(mixed $err): string
    {
        $f = is_object($err) ? get_object_vars($err) : [];
        if (is_string($f['code'] ?? null)) {
            return $f['code'] . ' (' . self::str($f['message'] ?? null) . ')';
        }
        if ($err instanceof \Throwable) {
            return get_class($err) . ': ' . $err->getMessage();
        }
        return API::show($err);
    }

    /** a value as the Lua runner's tostring renders it, for messages */
    private static function str(mixed $v): string
    {
        if (is_string($v)) {
            return $v;
        }
        if ($v instanceof \Throwable) {
            return $v->getMessage();
        }
        return API::show($v);
    }

    /** an entry through the door, against its expectation */
    private static function entry(object $s, array &$batches, mixed $value, mixed $expect, bool $raw, string $Event, array &$p): void
    {
        $before = $s->sampleHere();
        $batches = [];
        $err = null;
        try {
            $s->emit($value);
        } catch (\Throwable $t) {
            $err = $t;
        }
        if (count($batches) !== 1) {
            $p[] = 'the observer was shown ' . count($batches) . ' batches, expected 1';
            return;
        }
        $b = $batches[0];
        self::addAll($p, 'batch', SEAM::batchProblems($b));
        $x = $expect;
        $outcome = is_array($b) ? ($b['outcome'] ?? null) : null;
        if ($x === 'settled' || (is_array($x) && array_key_exists('settled', $x))) {
            if ($err !== null) {
                $p[] = 'expected settled, got rejected ' . self::show($err);
                return;
            }
            self::check($p, 'batch', $outcome === 'settled', 'outcome ' . API::show($outcome));
        } else {
            $want = $x['rejected'];
            if ($err === null) {
                $p[] = 'expected rejected ' . self::str($want['code'] ?? null) . ', it settled';
                return;
            }
            self::addAll($p, 'rejection', API::rejectionProblems($err, $raw ? ['noDelivery' => true] : null));
            $f = get_object_vars($err);
            self::check($p, 'batch', $outcome === 'rejected', 'outcome ' . API::show($outcome));
            $why = SEAM::rejectionCopyProblem(is_array($b) ? ($b['rejection'] ?? null) : null, $err, $Event);
            self::check($p, 'batch', $why === null, $why);
            foreach (['code', 'eventType', 'depth', 'cause'] as $k) {
                if (!array_key_exists($k, $want)) {
                    continue;
                }
                $got = $f[$k] ?? null;
                if ($k === 'cause' && $got instanceof \Throwable) {
                    $got = $got->getMessage();
                }
                if ($got !== $want[$k]) {
                    $p[] = $k . ': expected ' . self::str($want[$k]) . ', got ' . self::str($got);
                }
            }
            if (array_key_exists('at', $want)) {
                $at = self::items($want['at']);
                $path = implode('>', array_map(self::str(...), $at));
                $records = is_array($b) && is_array($b['records'] ?? null) ? $b['records'] : [];
                $whyAt = null;
                $rec = F::recordAt($records, $at, $whyAt);
                if ($rec === null) {
                    $p[] = 'at ' . $path . ': ' . $whyAt;
                } elseif (($f['eventId'] ?? null) !== ($rec['eventId'] ?? null) || ($f['causeId'] ?? null) !== ($rec['causeId'] ?? null)) {
                    $p[] = 'at ' . $path . ': the rejection names delivery '
                        . self::str($f['eventId'] ?? null) . '<' . self::str($f['causeId'] ?? null) . ', the path reaches '
                        . self::str($rec['eventId'] ?? null) . '<' . self::str($rec['causeId'] ?? null);
                }
            }
            // a rejected propagation commits nothing (K5)
            $after = $s->sampleHere();
            $whyK5 = null;
            self::check($p, 'rejection committed', F::residueMatch($before['residue'], $after['residue'], $whyK5), $whyK5);
        }
        if (is_array($x) && array_key_exists('trace', $x)) {
            $whyT = null;
            $records = is_array($b) && is_array($b['records'] ?? null) ? $b['records'] : [];
            self::check($p, 'trace', F::traceMatch($x['trace'], $records, $whyT), $whyT);
        }
    }

    private static function step(array $core, object $s, array &$batches, array $st, array &$p): void
    {
        $why = null;
        if (array_key_exists('register', $st)) {
            $sig = self::str($st['register']['signature']);
            $err = null;
            try {
                $s->register(self::gateOf($core, $st['register']));
            } catch (\Throwable $t) {
                $err = $t;
            }
            if ($st['refused'] && $err === null) {
                $p[] = 'register ' . $sig . ': expected refused, it was accepted';
            } elseif (!$st['refused'] && $err !== null) {
                $p[] = 'register ' . $sig . ': refused (' . $err->getMessage() . ')';
            }
        } elseif (array_key_exists('emit', $st)) {
            self::entry($s, $batches, self::eventOf($core['Event'], $st['emit']), $st['expect'], false, $core['Event'], $p);
        } elseif (array_key_exists('emitRaw', $st)) {
            self::entry($s, $batches, self::plain($st['emitRaw']), $st['expect'], true, $core['Event'], $p);
        } elseif (array_key_exists('take', $st)) {
            self::check($p, 'take', F::residueMatch($st['take'], $s->takeResidue(), $why), $why);
        } elseif (array_key_exists('sample', $st)) {
            $smp = $s->sampleHere();
            self::check($p, 'sample', F::residueMatch($st['sample']['residue'], $smp['residue'], $why), $why);
            self::check($p, 'sample', ($smp['gateCount'] ?? null) === $st['sample']['gateCount'],
                'gateCount: expected ' . self::str($st['sample']['gateCount']) . ', got ' . self::str($smp['gateCount'] ?? null));
        }
    }

    // ── one fixture ─────────────────────────────────────────────────

    public static function run(array $fixture, ?array $core = null): array
    {
        $core = ($core ?? []) + self::CORE;
        $result = ['name' => is_string($fixture['name'] ?? null) ? $fixture['name'] : '?', 'passed' => 0, 'total' => 1, 'problems' => []];
        $shape = F::problems($fixture);
        if (is_array($fixture['steps'] ?? null) && count($fixture['steps']) > 0) {
            $result['total'] = count($fixture['steps']);
        }
        if ($shape !== []) {
            self::addAll($result['problems'], 'shape', $shape);
            return $result;
        }
        $S = $core['Stream'];
        $s = is_string($S) ? new $S() : $S();
        $batches = [];
        $s->attach(static function (mixed $b) use (&$batches): void {
            $batches[] = $b;
        });
        foreach (self::items($fixture['gates']) as $i => $g) {
            try {
                $s->register(self::gateOf($core, $g));
            } catch (\Throwable $err) {
                $result['problems'][] = 'gates[' . ($i + 1) . ']: refused (' . $err->getMessage() . ')';
            }
        }
        if ($result['problems'] !== []) {
            return $result;
        }
        foreach (self::items($fixture['steps']) as $i => $st) {
            $p = [];
            try {
                self::step($core, $s, $batches, $st, $p);
            } catch (\Throwable $err) {
                $p[] = 'raised ' . self::show($err);
            }
            if ($p === []) {
                $result['passed']++;
            }
            self::addAll($result['problems'], 'steps[' . ($i + 1) . ']', $p);
        }
        return $result;
    }

    // ── command line ────────────────────────────────────────────────

    public static function main(array $argv): int
    {
        $dir = $argv[1] ?? (__DIR__ . '/../../../fixtures');
        $files = [];
        $names = is_dir($dir) ? scandir($dir) : false;
        foreach ($names === false ? [] : $names as $name) {
            if (str_ends_with($name, '.json')) {
                $files[] = $name;
            }
        }
        sort($files, SORT_STRING);

        $passed = 0;
        $total = 0;
        $clean = $files !== [];
        foreach ($files as $name) {
            $text = @file_get_contents($dir . '/' . $name);
            $why = null;
            $fixture = Data::decode($text === false ? '' : $text, $why);
            if ($why !== null) {
                $r = ['name' => '?', 'passed' => 0, 'total' => 1, 'problems' => ['decode: ' . $why]];
            } elseif (!is_array($fixture)) {
                $r = ['name' => '?', 'passed' => 0, 'total' => 1, 'problems' => array_map(static fn (string $s): string => 'shape: ' . $s, F::problems($fixture))];
            } else {
                $r = self::run($fixture);
            }
            $passed += $r['passed'];
            $total += $r['total'];
            $good = $r['passed'] === $r['total'] && $r['problems'] === [];
            $clean = $clean && $good;
            echo ($good ? 'ok    ' : 'FAIL  ') . $name . '  ' . $r['passed'] . '/' . $r['total'] . '  ' . $r['name'] . "\n";
            foreach ($r['problems'] as $s) {
                echo '        ' . $s . "\n";
            }
        }
        echo 'fixtures: ' . count($files) . ' files, ' . $passed . '/' . $total . " steps passed\n";
        return $clean ? 0 : 1;
    }
}

if (PHP_SAPI === 'cli' && realpath($_SERVER['SCRIPT_FILENAME'] ?? '') === __FILE__) {
    exit(Runner::main($_SERVER['argv'] ?? []));
}
