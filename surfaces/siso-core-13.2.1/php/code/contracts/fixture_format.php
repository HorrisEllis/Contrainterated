<?php
/*
contract: fixture-format — the language-agnostic suite every instance
must pass (core 12, PHP instance). Port of
lua/code/contracts/fixture_format.lua.

Between: fixtures -> stream, observer (and every instance: the Lua
reference, the JS and PHP ports are checked against the same files). Only
a contracts phase changes this file. The fixture files themselves
(fixtures/*.json) are the fixtures boundary's; this is the format they
are written in, and the matching rules every runner applies.

A FIXTURE is one JSON file:
  {
    "format": 1,
    "name":   "a sentence",
    "covers": ["Rule 3", "K5", …],      principle items it pins
    "gates":  [ gate, … ],              registered before the first step
    "steps":  [ step, … ]               run in order
  }
A GATE is { "signature": "a", "does": behaviour }. Behaviours are
test-only (dev notes §2):
  { "emit": [ out, … ] }   returns a list of these (empty: consumed)
  { "emitOne": out }       returns one Event
  { "forward": "t" }       returns new Event("t", <the received event's data>)
  { "nothing": true }      returns null
  { "throw": "message" }   throws (PHP: new \RuntimeException("message"))
  { "raw": value }         returns `value` itself, which is not an Event
  where `out` is an EVENT, or { "raw": value } for a list element that
  is not an Event.
An EVENT is { "type": "t", "data": { … } }; "data" may be omitted ({}).
Event data is plain JSON with integers only (no floats), and no empty
arrays inside it (Lua cannot tell [] from {}). The restriction is on event
data only: a list the format itself expects (gates, steps, emit, take,
sample's residue, trace, then, at) may be empty, so "take": [] pins that
the stream holds no residue.

A STEP is one of:
  { "register": gate, "refused": true|false }
  { "emit": EVENT, "expect": expectation }
  { "emitRaw": value, "expect": expectation }   the entry is not an Event
  { "take": [ EVENT, … ] }        the residue, compared as a multiset
  { "sample": { "residue": [ EVENT, … ], "gateCount": n } }
                                  the residue compared as a multiset
An EXPECTATION is:
  "settled"
  { "settled": true, "trace": [ node, … ] }
  { "rejected": { "code": "SISO_…", "eventType"?: "t", "depth"?: n,
                  "cause"?: "message", "at"?: [ "t1", "t2", … ] },
    "trace"?: [ node, … ] }
A NODE is one delivery of the propagation, as the observer's batch shows
it: { "type": "t", "claimed": true|false, "then"?: [ node, … ] }, where
"then" lists the deliveries its gate's return caused.

MATCHING (order-free: K1 is not semantics)
  - Residue is a multiset: two lists match when, rendered canonically,
    they hold the same events the same number of times.
  - A trace is a forest built from the batch's records by causeId. Two
    forests match when their roots match as a multiset and each node's
    "then" matches as a multiset, recursively.
  - "at" names the delivery a rejection names, by the path of types
    from the entry delivery (["a", "b"]: the b caused by the entry a).
    The rejection's eventId must be that record's id, and its causeId
    that record's cause. The fixture's author keeps the path unambiguous.
  - "eventType", "depth" and "cause" (the thrown message) compare exactly
    when present. An expectation never names a raw id: ids follow
    delivery order, which an instance may choose.
  - A runner always attaches one observer, and fails a step whose batch
    breaks observer-seam (batchProblems).

SUBSTRATE (PHP)
  - Decoding. The PHP runner (php/code/fixtures/run.php) decodes each
    file with kit Data::decode, where a JSON object is an array, a
    non-empty JSON array is a list, `[]` is the enum case
    Siso\Kit\Json::EmptyArray and `{}` is `[]`. So every list this
    format expects may be Json::EmptyArray: problems() and the matching
    functions accept it as the empty list. They recognise it by name
    (an enum case whose class is Siso\Kit\Json and whose name is
    EmptyArray), so this contract does not require the kit to be loaded;
    a fixture decoded with json_decode($text, true) (where both [] and {}
    are []) is accepted the same way.
  - A key is present when array_key_exists says so: JSON null is PHP
    null, a value (as Lua's data.null is), so "emitRaw": null is an entry
    of null.
  - The runner's mapping of the test-only behaviours: emit returns a PHP
    list of Events ([] when empty: consumed); emitOne an Event; forward
    `new Event($t, $e->data)`; nothing null; throw `throw new
    \RuntimeException($message)`, and the runner compares the
    expectation's "cause" to `$rejection->cause->getMessage()`; raw the
    decoded value itself. Before using any fixture value as a raw return,
    an entry or event data, the runner replaces every Json::EmptyArray in
    it with [] (an empty PHP array), so raw [] and raw {} are both an
    empty list (consumed), as in the reference, where an empty table is
    the empty list.
  - Events in the residue are Event objects (`->type`, `->data`);
    expected events are decoded arrays: renderings read either.
  - canon renders arrays as Lua's canon renders tables: keys sorted by
    their string form (strcmp), int keys as digits, string keys quoted
    (a PHP list [x, y] renders with keys 0 and 1 where Lua's renders 1
    and 2: canon compares within one instance, never across). Strings are
    quoted with addcslashes (an injective rendering, as Lua's %q is).
    null renders as null (Lua's data.null renders as {}: within one
    instance either is consistent).
  - Results that Lua returns as `ok, why` are a bool with a `?string &$why`
    out-parameter (null when it matches); recordAt returns the record or
    null with `$why`.

FixtureFormat::problems($fixture) checks a decoded fixture's shape.
canon($v), residueMatch($expected, $actual, &$why),
traceMatch($expected, $records, &$why), forest($records) and
recordAt($records, $path, &$why) are the matching rules, for PHP runners.
*/
declare(strict_types=1);

namespace Siso\Contracts;

final class FixtureFormat
{
    public const BEHAVIOURS = ['emit', 'emitOne', 'forward', 'nothing', 'throw', 'raw'];
    public const CODES = ['SISO_SELF_LOOP' => true, 'SISO_CYCLE' => true, 'SISO_GATE_THREW' => true, 'SISO_NOT_EVENT' => true];

    /** the kit's empty JSON array, named so the kit need not be loaded */
    public const EMPTY_ARRAY_ENUM = 'Siso\Kit\Json';
    public const EMPTY_ARRAY_CASE = 'EmptyArray';

    private function __construct()
    {
    }

    /** true for Siso\Kit\Json::EmptyArray (by name: no autoload, no kit file) */
    public static function isEmptyArray(mixed $v): bool
    {
        return $v instanceof \UnitEnum && get_class($v) === self::EMPTY_ARRAY_ENUM && $v->name === self::EMPTY_ARRAY_CASE;
    }

    /** a list the format expects: an array whose keys are 0..n-1, or Json::EmptyArray */
    public static function isList(mixed $t): bool
    {
        return self::isEmptyArray($t) || (is_array($t) && array_is_list($t));
    }

    /** the members of a list the format expects (Json::EmptyArray → []) */
    private static function items(mixed $t): array
    {
        if (self::isEmptyArray($t) || !is_array($t)) {
            return [];
        }
        return $t;
    }

    private static function sortedKeys(array $t): array
    {
        $ks = array_keys($t);
        usort($ks, static fn ($a, $b): int => strcmp((string)$a, (string)$b));
        return $ks;
    }

    // A canonical rendering of plain data: keys sorted, integers only.
    public static function canon(mixed $v): string
    {
        if (is_string($v)) {
            return '"' . addcslashes($v, "\"\\\0..\37\177") . '"';
        }
        if (is_int($v)) {
            return (string)$v;
        }
        if (is_float($v)) {
            throw new \UnexpectedValueException('fixture data holds a float: ' . var_export($v, true));
        }
        if (is_bool($v)) {
            return $v ? 'true' : 'false';
        }
        if ($v === null) {
            return 'null';
        }
        if (is_array($v)) {
            $parts = [];
            foreach (self::sortedKeys($v) as $k) {
                $parts[] = self::canon($k) . ':' . self::canon($v[$k]);
            }
            return '{' . implode(',', $parts) . '}';
        }
        if (self::isEmptyArray($v)) {
            throw new \UnexpectedValueException('fixture data holds an empty array (the format keeps them out of event data)');
        }
        throw new \UnexpectedValueException('not plain data: ' . get_debug_type($v));
    }

    /** an event as an Event object (->type, ->data) or a decoded array (['type'], ['data']) */
    private static function renderEvent(mixed $e): string
    {
        if (is_object($e)) {
            $type = $e->type ?? null;
            $data = $e->data ?? [];
        } else {
            $type = is_array($e) ? ($e['type'] ?? null) : null;
            $data = is_array($e) ? ($e['data'] ?? []) : [];
        }
        return self::canon(['data' => $data, 'type' => $type]);
    }

    private static function multiset(array $list, callable $render): string
    {
        $r = array_map($render, $list);
        sort($r, SORT_STRING);
        return implode('|', $r);
    }

    public static function residueMatch(mixed $expected, mixed $actual, ?string &$why = null): bool
    {
        $a = self::multiset(self::items($expected), self::renderEvent(...));
        $b = self::multiset(self::items($actual), self::renderEvent(...));
        if ($a === $b) {
            $why = null;
            return true;
        }
        $why = 'residue: expected ' . $a . ', got ' . $b;
        return false;
    }

    private static function renderNode(array $n): string
    {
        return '(' . self::canon($n['type'] ?? null) . (($n['claimed'] ?? false) === true ? '+' : '-')
            . '[' . self::multiset(self::items($n['then'] ?? []), self::renderNode(...)) . '])';
    }

    /**
     * the forest of a batch's records, as nodes ['type', 'claimed', 'then'];
     * throws \UnexpectedValueException on a record whose cause is not an
     * earlier record (batchProblems names such a batch first)
     */
    public static function forest(array $records): array
    {
        $kids = [];
        $roots = [];
        foreach (array_values($records) as $i => $r) {
            $id = $i + 1;
            $kids[$id] = [];
            $cause = $r['causeId'] ?? null;
            if ($cause === null) {
                $roots[] = $id;
            } elseif (!isset($kids[$cause]) || $cause >= $id) {
                $shown = $cause === null ? 'null' : (is_scalar($cause) ? var_export($cause, true) : get_debug_type($cause));
                throw new \UnexpectedValueException('record ' . $id . ': cause ' . $shown . ' is not an earlier record');
            } else {
                $kids[$cause][] = $id;
            }
        }
        $recs = array_values($records);
        // children always follow their cause, so build from the last record back
        $built = [];
        for ($id = count($recs); $id >= 1; $id--) {
            $then = [];
            foreach ($kids[$id] as $k) {
                $then[] = $built[$k];
                unset($built[$k]);
            }
            $built[$id] = ['type' => $recs[$id - 1]['eventType'] ?? null, 'claimed' => $recs[$id - 1]['claimed'] ?? null, 'then' => $then];
        }
        return array_map(static fn (int $id): array => $built[$id], $roots);
    }

    public static function traceMatch(mixed $expected, array $records, ?string &$why = null): bool
    {
        $a = self::multiset(self::items($expected), static fn ($n): string => self::renderNode(is_array($n) ? $n : []));
        $b = self::multiset(self::forest($records), self::renderNode(...));
        if ($a === $b) {
            $why = null;
            return true;
        }
        $why = 'trace: expected ' . $a . ', got ' . $b;
        return false;
    }

    // the record reached by a path of types from the entry; null and $why
    // when the path matches none or more than one
    public static function recordAt(array $records, mixed $path, ?string &$why = null): ?array
    {
        $records = array_values($records);
        $path = self::items($path);
        $frontier = [];
        foreach ($records as $i => $r) {
            if (($r['causeId'] ?? null) === null && ($r['eventType'] ?? null) === ($path[0] ?? null)) {
                $frontier[] = $i + 1;
            }
        }
        for ($depth = 1; $depth < count($path); $depth++) {
            $next = [];
            foreach ($records as $i => $r) {
                foreach ($frontier as $f) {
                    if (($r['causeId'] ?? null) === $f && ($r['eventType'] ?? null) === $path[$depth]) {
                        $next[] = $i + 1;
                    }
                }
            }
            $frontier = $next;
        }
        if (count($frontier) !== 1) {
            $why = count($frontier) . ' deliveries match the path';
            return null;
        }
        $why = null;
        return $records[$frontier[0] - 1];
    }

    // ── shape ───────────────────────────────────────────────────────

    private static function eventProblems(mixed $e, string $where, array &$p): void
    {
        if (!is_array($e) || !is_string($e['type'] ?? null)) {
            $p[] = $where . ': an event needs a string type';
            return;
        }
        foreach ($e as $k => $_) {
            if ($k !== 'type' && $k !== 'data') {
                $p[] = $where . ': unexpected field ' . $k;
            }
        }
        if (array_key_exists('data', $e)) {
            if (!is_array($e['data'])) {
                $p[] = $where . ': data is not an object';
            } else {
                try {
                    self::canon($e['data']);
                } catch (\UnexpectedValueException $err) {
                    $p[] = $where . ': ' . $err->getMessage();
                }
            }
        }
    }

    private static function outProblems(mixed $o, string $where, array &$p): void
    {
        if (is_array($o) && array_key_exists('raw', $o) && !array_key_exists('type', $o)) {
            return;
        }
        self::eventProblems($o, $where, $p);
    }

    private static function gateProblems(mixed $g, string $where, array &$p): void
    {
        if (!is_array($g) || !is_string($g['signature'] ?? null)) {
            $p[] = $where . ': a gate needs a string signature';
            return;
        }
        $d = $g['does'] ?? null;
        if (!is_array($d)) {
            $p[] = $where . ': no behaviour';
            return;
        }
        $keys = array_keys($d);
        if (count($keys) !== 1 || !in_array($keys[0], self::BEHAVIOURS, true)) {
            $p[] = $where . ': a behaviour is exactly one of emit, emitOne, forward, nothing, throw, raw';
            return;
        }
        if (array_key_exists('emit', $d)) {
            if (!self::isList($d['emit'])) {
                $p[] = $where . ': emit takes a list';
            } else {
                foreach (self::items($d['emit']) as $i => $o) {
                    self::outProblems($o, $where . '.emit[' . ($i + 1) . ']', $p);
                }
            }
        }
        if (array_key_exists('emitOne', $d)) {
            self::eventProblems($d['emitOne'], $where . '.emitOne', $p);
        }
        if (array_key_exists('forward', $d) && !is_string($d['forward'])) {
            $p[] = $where . ': forward names a type';
        }
        if (array_key_exists('throw', $d) && !is_string($d['throw'])) {
            $p[] = $where . ': throw takes a string';
        }
    }

    private static function nodeProblems(mixed $n, string $where, array &$p): void
    {
        if (!is_array($n) || !is_string($n['type'] ?? null) || !is_bool($n['claimed'] ?? null)) {
            $p[] = $where . ': a node is { type, claimed, then? }';
            return;
        }
        if (array_key_exists('then', $n)) {
            if (!self::isList($n['then'])) {
                $p[] = $where . ': then is a list';
            } else {
                foreach (self::items($n['then']) as $i => $c) {
                    self::nodeProblems($c, $where . '.then[' . ($i + 1) . ']', $p);
                }
            }
        }
    }

    private static function expectProblems(mixed $x, string $where, array &$p): void
    {
        if ($x === 'settled') {
            return;
        }
        if (!is_array($x)) {
            $p[] = $where . ': expect is "settled" or an object';
            return;
        }
        if (array_key_exists('settled', $x) === array_key_exists('rejected', $x)) {
            $p[] = $where . ': expect exactly one of settled, rejected';
        }
        if (array_key_exists('rejected', $x)) {
            $r = $x['rejected'];
            if (!is_array($r) || !is_string($r['code'] ?? null) || !isset(self::CODES[$r['code']])) {
                $p[] = $where . ': rejected needs a known code';
            } else {
                if (array_key_exists('at', $r) && !self::isList($r['at'])) {
                    $p[] = $where . ': at is a list of types';
                }
                if (array_key_exists('depth', $r) && !is_int($r['depth'])) {
                    $p[] = $where . ': depth is an integer';
                }
            }
        }
        if (array_key_exists('trace', $x)) {
            if (!self::isList($x['trace'])) {
                $p[] = $where . ': trace is a list';
            } else {
                foreach (self::items($x['trace']) as $i => $n) {
                    self::nodeProblems($n, $where . '.trace[' . ($i + 1) . ']', $p);
                }
            }
        }
    }

    public static function problems(mixed $f): array
    {
        $p = [];
        if (!is_array($f)) {
            return ['a fixture is an object'];
        }
        if (($f['format'] ?? null) !== 1) {
            $p[] = 'format is not 1';
        }
        if (!is_string($f['name'] ?? null)) {
            $p[] = 'no name';
        }
        if (!self::isList($f['covers'] ?? null) || self::items($f['covers']) === []) {
            $p[] = 'covers lists the items it pins';
        }
        if (!self::isList($f['gates'] ?? null)) {
            $p[] = 'gates is a list';
        } else {
            foreach (self::items($f['gates']) as $i => $g) {
                self::gateProblems($g, 'gates[' . ($i + 1) . ']', $p);
            }
        }
        if (!self::isList($f['steps'] ?? null) || self::items($f['steps']) === []) {
            $p[] = 'steps is a non-empty list';
            return $p;
        }
        foreach (self::items($f['steps']) as $i => $s) {
            $where = 'steps[' . ($i + 1) . ']';
            if (!is_array($s)) {
                $p[] = $where . ': not an object';
            } elseif (array_key_exists('register', $s)) {
                self::gateProblems($s['register'], $where . '.register', $p);
                if (!is_bool($s['refused'] ?? null)) {
                    $p[] = $where . ': register says whether it is refused';
                }
            } elseif (array_key_exists('emit', $s)) {
                self::eventProblems($s['emit'], $where . '.emit', $p);
                self::expectProblems($s['expect'] ?? null, $where, $p);
            } elseif (array_key_exists('emitRaw', $s)) {
                self::expectProblems($s['expect'] ?? null, $where, $p);
            } elseif (array_key_exists('take', $s)) {
                if (!self::isList($s['take'])) {
                    $p[] = $where . ': take is a list';
                } else {
                    foreach (self::items($s['take']) as $j => $e) {
                        self::eventProblems($e, $where . '.take[' . ($j + 1) . ']', $p);
                    }
                }
            } elseif (array_key_exists('sample', $s)) {
                $smp = $s['sample'];
                if (!is_array($smp) || !self::isList($smp['residue'] ?? null) || !is_int($smp['gateCount'] ?? null)) {
                    $p[] = $where . ': sample is { residue = [...], gateCount = n }';
                }
            } else {
                $p[] = $where . ': a step is one of register, emit, emitRaw, take, sample';
            }
        }
        return $p;
    }
}
