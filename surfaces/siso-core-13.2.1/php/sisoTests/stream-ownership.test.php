<?php
/*
stream-ownership.test.php — samples and observer records are their
receiver's own copies (core-api OWNERSHIP, observer-seam RECORD data).
Port of lua/sisoTests/stream-ownership.test.lua, case by case. Runs the
core-api ownership, doors and read-only checks and the observer-seam
ownership and seam checks on the real core, then pins what they leave
open, then measures what the copies cost (printed, never asserted). The
gates below are fixtures for the stream, not project gates.
  php php/sisoTests/stream-ownership.test.php   (from the project root)

Turned into PHP's own attempt at the same violation (core-api SUBSTRATE:
"rawset on a copy" becomes a write through Reflection and a \WeakMap
stamp):
  - every `rawset` on a sample, a take, a record or the entering code's
    event becomes a Reflection write (refused by readonly) and a \WeakMap
    stamp on each Event reachable from it; "shares no table" becomes
    "shares no object" (arrays are values and have no identity, so the
    data carries Events at depth for there to be objects to share).
  - "marks and __readonly sentinels go through copies": sentinels are enum
    cases, kept by identity through a copy and a copy of a copy
    (Freeze::copyData directly, as Lua calls freeze.copy).
  - "sharing inside one sample is kept …; a cycle terminates": sharing is
    an Event reached twice, one copy in one sample; a cycle needs a PHP
    reference and Event refuses it.
  - "the copy reads what a reader reads: a raw field a gate wrote past the
    refusal (R3)": a gate's Reflection write is refused, so every copy
    reads the value the event was built with.
  - "no observer means no copying past the entry; one copy per record per
    observer, one per sampled event": Lua counts freeze.copy calls by
    replacing the module; PHP cannot (no module reload, and a counter
    would be static state). The case pins the same thing by identity:
    without observers the residue holds the very events the gates
    returned and a take hands those objects over; each observer's records
    and each sample hold fresh objects.
  - "cost of the copies (printed)": the real core only.
Inapplicable:
  - the "sharing" arm of the cost rows (the core with freeze.copy replaced
    by the identity through package.loaded): no second copy of the core's
    classes can be loaded, and a switch would be static state.
  - the data-mark half of "marks and __readonly sentinels go through
    copies" (a metatable on a data table): PHP arrays carry none.
*/
declare(strict_types=1);

namespace Siso\Tests\StreamOwnership;

require_once __DIR__ . '/../code/harness.php';
require_once __DIR__ . '/../code/contracts/core_api.php';
require_once __DIR__ . '/../code/contracts/observer_seam.php';
require_once __DIR__ . '/../code/core/event.php';
require_once __DIR__ . '/../code/core/gate.php';
require_once __DIR__ . '/../code/core/stream.php';
require_once __DIR__ . '/../code/core/freeze.php';

use Siso\Contracts\CoreApi as API;
use Siso\Contracts\ObserverSeam as SEAM;
use Siso\Core\Event;
use Siso\Core\Freeze;
use Siso\Core\Gate;
use Siso\Core\Stream;
use Siso\Harness\H;

const CORE = ['Event' => Event::class, 'Gate' => Gate::class, 'Stream' => Stream::class];

enum Null_
{
    case Value;
}

function noProblems(array $list, ?string $label = null): void
{
    H::assert($list === [], ($label !== null ? $label . ': ' : '') . implode('; ', $list));
}

function isRefusal(\Throwable $t): bool
{
    return $t instanceof \Error && (str_contains($t->getMessage(), 'read-only') || str_contains($t->getMessage(), 'readonly'));
}

function refuses(string $label, callable $fn): void
{
    try {
        $fn();
    } catch (\Throwable $t) {
        H::assert(isRefusal($t), $label . ': raised ' . get_class($t) . ': ' . $t->getMessage());
        return;
    }
    H::fail($label . ' was accepted');
}

/** every object (not an enum case) reachable from $v through arrays and public properties, by id */
function objectsOf(mixed $v, array &$into = []): array
{
    if (is_array($v)) {
        foreach ($v as $x) {
            objectsOf($x, $into);
        }
    } elseif (is_object($v) && !$v instanceof \UnitEnum && !isset($into[spl_object_id($v)])) {
        $into[spl_object_id($v)] = $v;
        foreach (get_object_vars($v) as $x) {
            objectsOf($x, $into);
        }
    }
    return $into;
}

/** the number of objects reachable from both $a and $b */
function shared(mixed $a, mixed $b): int
{
    return count(array_intersect_key(objectsOf($a), objectsOf($b)));
}

/** the substrate's back doors on everything reachable: a Reflection write and a stamp on each Event */
function tamper(mixed $v, \WeakMap $stamps, string $mark): void
{
    foreach (objectsOf($v) as $o) {
        if ($o instanceof Event) {
            API::reflectWrite($o, 'type', $mark);
            API::reflectWrite($o, 'data', ['v' => $mark]);
            $stamps[$o] = $mark;
        }
    }
}

function stamped(mixed $v, \WeakMap $stamps): int
{
    $n = 0;
    foreach (objectsOf($v) as $o) {
        if (isset($stamps[$o])) {
            $n++;
        }
    }
    return $n;
}

function emitCatch(Stream $s, mixed $e): ?\Throwable
{
    try {
        $s->emit($e);
        return null;
    } catch (\Throwable $t) {
        return $t;
    }
}

// ── the contracts on the real core ─────────────

H::test('core-api ownership holds on the core', function (): void {
    noProblems(API::checkOwnership(CORE));
});

H::test('core-api doors and read-only still hold on the core', function (): void {
    noProblems(API::checkDoors(CORE), 'doors');
    noProblems(API::checkReadonly(CORE), 'readonly');
});

H::test('observer-seam ownership and seam hold on the core', function (): void {
    noProblems(SEAM::checkOwnership(CORE), 'ownership');
    noProblems(SEAM::checkSeam(CORE), 'seam');
});

// ── samples ────────────────────────────────────

function deep(): array
{
    return ['v' => 1, 'a' => ['b' => ['c' => ['d' => ['e' => new Event('fx_leaf', ['leaf' => 1, 'list' => [1, 2, 3],
        'in' => new Event('fx_in', ['w' => 1])])]]]]];
}

H::test('deep nesting: a rawset at any depth of a sample reaches nothing; no proxy is shared', function (): void {
    $s = new Stream();
    $s->emit(new Event('fx_deep', deep()));
    $a = $s->sampleHere()['residue'][0];
    $stamps = new \WeakMap();
    tamper($a, $stamps, 'SAMPLER WROTE');
    H::assertEqual(stamped($a, $stamps), 3, 'the sample, its deep event and the event inside it are stamped');
    $b = $s->sampleHere()['residue'][0];
    $eb = $b->data['a']['b']['c']['d']['e'];
    H::assertEqual($eb->data['leaf'], 1, 'deep field');
    H::assertEqual($eb->data['list'][1], 2, 'deep list member');
    H::assertEqual($eb->data['added'] ?? null, null, 'deep added key');
    H::assertEqual(count($eb->data['list']), 3);
    H::assertEqual(stamped($b, $stamps), 0, 'a stamp on a sample reached a later sample');
    $t = $s->takeResidue()[0];
    H::assertEqual($t->data['a']['b']['c']['d']['e']->data['leaf'], 1, 'the taken residue is untouched');
    H::assertEqual(stamped($t, $stamps), 0, 'a stamp on a sample reached the residue');
    H::assertEqual(shared($b, $t), 0, 'a sample shares no object with the residue it copies');
    refuses('a deep write into a copy', static function () use ($eb): void {
        $eb->data['list'][0] = 0;
    });
});

H::test('copies read like the original: indexing, #, ipairs, pairs; the same field twice is the same value', function (): void {
    $s = new Stream();
    $s->emit(new Event('fx_read', ['list' => [3, 4, 5], 'm' => ['k' => 1], 's' => 'x']));
    $r = $s->sampleHere()['residue'][0];
    H::assert(Event::is($r), 'Event::is recognises a copied event');
    H::assert($r->data === $r->data && $r->data['m'] === $r->data['m'], 'two reads give the same value');
    H::assertEqual(count($r->data['list']), 3);
    $sum = 0;
    foreach ($r->data['list'] as $v) {
        $sum += $v;
    }
    H::assertEqual($sum, 12);
    $keys = array_keys($r->data);
    sort($keys, SORT_STRING);
    H::assertEqual(implode(',', $keys), 'list,m,s');
    refuses("a copied event's type", static function () use ($r): void {
        $r->type = 'z';
    });
    refuses("a copied event's field", static function () use ($r): void {
        $r->data['s'] = 'y';
    });
    refuses("a copied event's new key", static function () use ($r): void {
        $r->data['w'] = 1;
    });
});

H::test('marks and __readonly sentinels go through copies; no metatable stays none', function (): void {
    $null = Null_::Value;
    $s = new Stream();
    $s->register(new Gate('fx_mark', static fn () => [new Event('fx_out', ['list' => [1, $null], 'n' => $null, 'plain' => [],
        'e' => new Event('fx_in')])]));
    $s->emit(new Event('fx_mark'));
    $byType = [];
    foreach ($s->sampleHere()['residue'] as $e) {
        $byType[$e->type] = $e;
    }
    $d = $byType['fx_out']->data;
    H::assertEqual(get_class($byType['fx_out']), Event::class, 'a copied event is an Event');
    H::assert($d['n'] === $null, 'a nested sentinel keeps its identity');
    H::assert($d['list'][1] === $null, 'a sentinel in a list keeps its identity');
    // and through Freeze::copyData directly, a copy of a copy
    $memo = [];
    $again = Freeze::copyData($d, $memo);
    H::assert($again['n'] === $null && $again['list'][1] === $null, 'a copy of a copy keeps sentinels');
    H::assert($again['e'] !== $d['e'] && $again['e']->type === 'fx_in', 'a copy of a copy has its own events');
    H::assertEqual(shared($again, $d), 0, 'a copy of a copy shares nothing with it');
});

H::test('two samples are independent', function (): void {
    $s = new Stream();
    $s->emit(new Event('fx_x', ['v' => 1, 'n' => [1], 'e' => new Event('fx_in', ['k' => 'a'])]));
    $s->emit(new Event('fx_y', ['w' => ['k' => 'a']]));
    $a = $s->sampleHere()['residue'];
    $b = $s->sampleHere()['residue'];
    H::assertEqual(count($a), 2);
    for ($i = 0; $i < 2; $i++) {
        H::assert($a[$i] !== $b[$i], 'event ' . $i . ' is a separate copy in each sample');
        H::assertEqual(shared($a[$i], $b[$i]), 0, 'event ' . $i . ': the samples share no object');
    }
    $stamps = new \WeakMap();
    tamper($a, $stamps, 'changed');
    $types = array_map(static fn (Event $e): string => $e->type, $b);
    sort($types, SORT_STRING);
    H::assertEqual(implode(',', $types), 'fx_x,fx_y', "the other sample's events");
    H::assertEqual(stamped($b, $stamps), 0, "a stamp on one sample reached the other");
    foreach ($s->sampleHere()['residue'] as $e) {
        if ($e->type === 'fx_x') {
            H::assertEqual($e->data['n'][0], 1);
            H::assertEqual($e->data['e']->data['k'], 'a');
        } else {
            H::assertEqual($e->data['w']['k'], 'a');
        }
    }
});

H::test('sharing inside one sample is kept, never with the stream; a cycle terminates', function (): void {
    $s = new Stream();
    $s->register(new Gate('fx_fan', static fn (Event $e) => [new Event('fx_l', $e->data), new Event('fx_r', $e->data)]));
    $s->emit(new Event('fx_fan', ['list' => [1], 'e' => new Event('fx_in')]));
    $byType = [];
    foreach ($s->sampleHere()['residue'] as $e) {
        $byType[$e->type] = $e;
    }
    H::assert($byType['fx_l']->data['e'] === $byType['fx_r']->data['e'], 'an event shared in the residue is one copy in one sample');
    H::assert($byType['fx_l']->data === $byType['fx_r']->data, 'data shared in the residue is one value in one sample');
    $held = [];
    foreach ($s->takeResidue() as $e) {
        $held[$e->type] = $e;
    }
    H::assert($byType['fx_l']->data['e'] !== $held['fx_l']->data['e'], "the sample's event in data is not the stream's");
    H::assertEqual(shared($byType, $held), 0);
    // PHP: a cycle needs a reference, and Event refuses it (it terminates)
    $cyclic = ['name' => 'c'];
    $cyclic['self'] = &$cyclic;
    H::assertThrows(static fn () => new Event('fx_cycle', $cyclic), 'recursive array');
    unset($cyclic['self']);
});

H::test("take hands over the stream's own events; entering them again takes a fresh copy", function (): void {
    // 13.0.0 (core-api OWNERSHIP): entry copies, so the taker's handle
    // never reaches the residue, taken residue entered again included
    $s = new Stream();
    $s->emit(new Event('fx_kept', ['v' => 1, 'n' => [1], 'e' => new Event('fx_in', ['w' => 1])]));
    $before = $s->sampleHere()['residue'][0];
    $taken = $s->takeResidue()[0];
    H::assertEqual(count($s->sampleHere()['residue']), 0, 'the stream holds none of what it handed over');
    $s->emit($taken);
    $stamps = new \WeakMap();
    tamper($taken, $stamps, 'TAKER WROTE');
    $again = $s->takeResidue()[0];
    H::assert($again !== $taken, "re-entered residue is the stream's own copy");
    H::assertEqual(shared($again, $taken), 0, "the copy shares no object with the taker's event");
    H::assertEqual(stamped($again, $stamps), 0, "a stamp on the taker's event did not reach the residue");
    H::assertEqual($again->data['v'], 1, "a Reflection write on the taker's event did not reach the residue");
    H::assertEqual($again->data['n'][0], 1);
    H::assertEqual($again->data['e']->data['w'], 1);
    H::assertEqual($before->data['v'], 1);
});

H::test("the entering code's own event never reaches the residue, at any depth", function (): void {
    $held = new Event('fx_held', deep());
    $s = new Stream();
    $s->emit($held);
    $stamps = new \WeakMap();
    tamper($held, $stamps, 'z');
    $r = $s->takeResidue()[0];
    H::assertEqual($r->type, 'fx_held');
    H::assertEqual($r->data['a']['b']['c']['d']['e']->data['leaf'], 1);
    H::assertEqual($r->data['a']['b']['c']['d']['e']->data['list'][0], 1);
    H::assertEqual(stamped($r, $stamps), 0, "a stamp on the entering code's event reached the residue");
    H::assertEqual(shared($r, $held), 0, "the residue shares no object with the entering code's event");
});

// ── observers ──────────────────────────────────

H::test("each observer's records carry its own copies; within one observer data passed on is one copy", function (): void {
    $s = new Stream();
    $given = null;
    $s->register(new Gate('fx_root', static function (Event $e) use (&$given) {
        $given = $e->data;
        return new Event('fx_next', $e->data);
    }));
    $A = null;
    $B = null;
    $s->attach(static function (array $b) use (&$A): void {
        $A = $b;
    });
    $s->attach(static function (array $b) use (&$B): void {
        $B = $b;
    });
    $s->emit(new Event('fx_root', ['v' => 1, 'n' => [1], 'e' => new Event('fx_in', ['w' => 1])]));
    $held = $s->sampleHere()['residue'][0];
    $taken = $s->takeResidue()[0];
    H::assert($taken->data['e'] === $given['e'], 'inside the propagation the data is shared, and the residue holds it');
    H::assert($A['records'][0]['data']['e'] !== $given['e'], 'a record is not the data the gate received');
    H::assertEqual(shared($A['records'][0]['data'], $given), 0, "a record shares no object with the stream's data");
    H::assertEqual(shared($A['records'][0]['data'], $B['records'][0]['data']), 0, 'two observers share no object');
    H::assert($A['records'][0]['data']['e'] === $A['records'][1]['data']['e'], 'data passed on is one copy for one observer');
    H::assertEqual($A['records'][1]['data']['n'][0], 1);
    H::assertEqual($held->data['v'], 1);
    $e = $A['records'][0]['data']['e'];
    refuses("an Event in an observer's record data", static function () use ($e): void {
        $e->data['w'] = 2;
    });
});

H::test("a rejected batch's records are copies too", function (): void {
    foreach ([true, false] as $writerFirst) {
        $s = new Stream();
        $given = null;
        $s->register(new Gate('fx_root', static function (Event $e) use (&$given) {
            $given = $e->data;
            return new Event('fx_mid', $e->data);
        }));
        $s->register(new Gate('fx_mid', static function () {
            throw new \RuntimeException('boom');
        }));
        $seen = null;
        $stamps = new \WeakMap();
        $writer = static function (array &$b) use ($stamps): void {
            foreach ($b['records'] as $i => $_) {
                $b['records'][$i]['data']['v'] = 99;
                $b['records'][$i]['data']['n'][0] = 9;
            }
            tamper($b, $stamps, 'OBSERVER WROTE');
        };
        $reader = static function (array $b) use (&$seen): void {
            $seen = $b;
        };
        if ($writerFirst) {
            $s->attach($writer);
            $s->attach($reader);
        } else {
            $s->attach($reader);
            $s->attach($writer);
        }
        $r = emitCatch($s, new Event('fx_root', ['v' => 1, 'n' => [1], 'e' => new Event('fx_in', ['w' => 1])]));
        H::assert($r !== null && $r->code === 'SISO_GATE_THREW', 'the propagation is rejected');
        H::assertEqual($seen['outcome'], 'rejected');
        H::assertEqual(count($seen['records']), 2);
        foreach ($seen['records'] as $i => $rec) {
            H::assertEqual($rec['data']['v'], 1, 'record ' . $i . ": another observer's write did not reach it");
            H::assertEqual($rec['data']['n'][0], 1);
            H::assertEqual($rec['data']['e']->data['w'], 1);
            H::assertEqual(stamped($rec, $stamps), 0, 'record ' . $i . ": another observer's stamp did not reach it");
            H::assertEqual(shared($rec['data'], $given), 0, 'record ' . $i . " shares no object with the stream's data");
        }
        H::assertEqual(stamped($given, $stamps), 0, "the stream's data carries no stamp");
        H::assertEqual($given['v'], 1);
    }
});

H::test('the copy reads what a reader reads: a raw field a gate wrote past the refusal (R3)', function (): void {
    // PHP: a gate's write past the refusal (Reflection) is refused; every copy reads the built value
    $s = new Stream();
    $wrote = null;
    $s->register(new Gate('fx_raw', static function (Event $e) use (&$wrote) {
        $wrote = API::attempt(static function () use ($e): void {
            (new \ReflectionProperty($e, 'data'))->setValue($e, ['v' => 2]);
        });
        return new Event('fx_out', $e->data);
    }));
    $b = null;
    $s->attach(static function (array $batch) use (&$b): void {
        $b = $batch;
    });
    $s->emit(new Event('fx_raw', ['v' => 1]));
    H::assertEqual($wrote, false, "the gate's Reflection write is refused");
    H::assertEqual($b['records'][1]['data']['v'], 1, 'a record reads the value as built');
    $r = $s->sampleHere()['residue'][0];
    H::assertEqual($r->data['v'], 1, 'a sample reads the value as built');
    refuses('that field in a copy', static function () use ($r): void {
        $r->data['v'] = 3;
    });
});

// ── copying only when needed ───────────────────

H::test('no observer means no copying past the entry; one copy per record per observer, one per sampled event', function (): void {
    // PHP: pinned by identity instead of a call count (see the header)
    $returned = [];
    $chain = static function () use (&$returned): Stream {
        $s = new Stream();
        $s->register(new Gate('fx_a', static function () use (&$returned) {
            $out = [new Event('fx_b', ['k' => 1]), new Event('fx_c', ['e' => new Event('fx_in')])];
            foreach ($out as $o) {
                $returned[$o->type] = $o;
            }
            return $out;
        }));
        $s->register(new Gate('fx_b', static function () use (&$returned) {
            return $returned['fx_d'] = new Event('fx_d');
        }));
        return $s;
    };
    $v = $chain();
    $v->emit(new Event('fx_a', ['k' => 0]));
    $sample = $v->sampleHere()['residue'];
    H::assertEqual(count($sample), 2);
    foreach ($sample as $e) {
        H::assert($e !== $returned[$e->type], 'a sample copies each residue event: ' . $e->type);
    }
    $taken = $v->takeResidue();
    foreach ($taken as $e) {
        H::assert($e === $returned[$e->type], 'no observer, so nothing past the entry is copied, and a take copies nothing: ' . $e->type);
    }
    $u = $chain();
    $seen = [];
    $u->attach(static function (array $b) use (&$seen): void {
        $seen[] = $b;
    });
    $u->attach(static function (array $b) use (&$seen): void {
        $seen[] = $b;
    });
    $u->emit(new Event('fx_a'));
    H::assertEqual(count($seen), 2);
    H::assertEqual(count($seen[0]['records']), 4, 'four records each');
    H::assert($seen[0]['records'][2]['data']['e'] !== $returned['fx_c']->data['e'], "a record's event is a copy");
    H::assertEqual(shared($seen[0], $seen[1]), 0, 'each observer its own copies');
    foreach ($u->takeResidue() as $e) {
        H::assert($e === $returned[$e->type], 'observers attached: the residue is still the very event returned: ' . $e->type);
    }
});

// ── cost (printed, never asserted) ─────────────

function best(int $runs, callable $fn): float
{
    $min = INF;
    for ($i = 0; $i < $runs; $i++) {
        gc_collect_cycles();
        $t0 = hrtime(true);
        $fn();
        $dt = (hrtime(true) - $t0) / 1e9;
        if ($dt < $min) {
            $min = $dt;
        }
    }
    return $min;
}

H::test('cost of the copies (printed)', function (): void {
    $lines = [];
    $filled = static function (bool $withEvent): Stream {
        $s = new Stream();
        for ($i = 1; $i <= 2000; $i++) {
            $d = [];
            for ($j = 1; $j <= 10; $j++) {
                $d['k' . $j] = $i * 10 + $j;
            }
            if ($withEvent) {
                $d['e'] = new Event('fx_in', ['w' => $i]);
            }
            $s->emit(new Event('fx_r' . $i, $d));
        }
        return $s;
    };
    $sc = $filled(false);
    $se = $filled(true);
    $lines[] = sprintf('  %-60s %.4fs', 'sampleHere, 2,000 events x 10-entry data', best(3, static fn () => $sc->sampleHere()));
    $lines[] = sprintf('  %-60s %.4fs', 'sampleHere, 2,000 events x 10-entry data + an event in it', best(3, static fn () => $se->sampleHere()));
    $n = 20000;
    $chain = static function () use ($n): void {
        $s = new Stream();
        for ($i = 0; $i < $n; $i++) {
            $s->register(new Gate('step_' . $i, static fn () => $i < $n - 1 ? new Event('step_' . ($i + 1), []) : null));
        }
        $s->attach(static function (): void {
        });
        $s->emit(new Event('step_0', []));
    };
    $lines[] = sprintf('  %-60s %.4fs', '20,000-gate chain, one observer (register + emit)', best(3, $chain));
    echo "ownership cost (hrtime, best of 3; the real core only):\n";
    foreach ($lines as $l) {
        echo $l . "\n";
    }
    H::assert(true);
});

H::finish('stream-ownership');
