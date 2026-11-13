<?php
/*
stream-freeze.test.php — read-only events (core-api READ-ONLY). Port of
lua/sisoTests/stream-freeze.test.lua, case by case. Runs the core-api
read-only and doors checks and the observer-seam check on the real core,
then pins what they leave open, then measures what the stream's copies
cost (printed, never asserted). The gates below are fixtures for the
stream, not project gates.
  php php/sisoTests/stream-freeze.test.php      (from the project root)

PHP holds read-only by the language, so there is no freezing: every
write to an event raises \Error (PHP's "Cannot modify readonly property"
or the event's "read-only: …"; refuses() accepts both).

Turned into PHP's own attempt at the same violation (core-api and
observer-seam SUBSTRATE):
  - "reads: … pairs hands out the frozen table as its state": PHP's
    iteration over an event hands out its properties; iterating by
    reference (the writable form of that state) is refused.
  - "an observer's records' data refuse writes": record data is an array,
    the observer's own value (observer-seam SUBSTRATE: Lua's "accepts
    assignment" probes vanish); the test writes to it and shows that
    nothing else changed, and that the Events in it refuse writes.
  - "kit-style marks survive": the event half is kept (a copied event is
    an Event of the same class); a __readonly sentinel is an enum case.
  - "a __readonly sentinel … as the data itself": refused by the type
    (data is an array).
  - "an event passed on unchanged is not double-wrapped": nothing wraps
    in PHP; the copy is an Event of the class itself, and data and a
    carried event passed on keep their value and identity.
  - "a table reached twice …; a cycle terminates": an array reached twice
    is one value; a cycle needs a PHP reference, and Event refuses it.
  - "R3 concession: rawset …": a Reflection write on a sampled event is
    refused (R3's rawset clause vanishes).
  - "cost of freezing (printed)": PHP freezes nothing, so the rows print
    the real core's cost of the copies it makes (the entry copy) with no
    baseline arm.
Inapplicable:
  - "the baseline arm freezes nothing": the baseline is the Lua core with
    core.freeze replaced through package.loaded. PHP cannot load a second
    copy of the core's classes, and a switch inside them would be static
    state; there is no freezing to remove.
  - the data-mark half of "kit-style marks survive" (a metatable on a data
    table, `getmetatable(r.data) == false`): PHP arrays carry none.
*/
declare(strict_types=1);

namespace Siso\Tests\StreamFreeze;

require_once __DIR__ . '/../code/harness.php';
require_once __DIR__ . '/../code/contracts/core_api.php';
require_once __DIR__ . '/../code/contracts/observer_seam.php';
require_once __DIR__ . '/../code/core/event.php';
require_once __DIR__ . '/../code/core/gate.php';
require_once __DIR__ . '/../code/core/stream.php';

use Siso\Contracts\CoreApi as API;
use Siso\Contracts\ObserverSeam as SEAM;
use Siso\Core\Event;
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

function keysOf(object|array $t): string
{
    $ks = array_map('strval', array_keys(is_object($t) ? get_object_vars($t) : $t));
    sort($ks, SORT_STRING);
    return implode(',', $ks);
}

// ── the contracts on the real core ─────────────

H::test('core-api read-only holds on the core', function (): void {
    noProblems(API::checkReadonly(CORE));
});

H::test('core-api doors still hold on the core', function (): void {
    noProblems(API::checkDoors(CORE));
});

H::test('observer-seam still holds on the core', function (): void {
    noProblems(SEAM::checkSeam(CORE));
});

// ── what a gate receives ───────────────────────

H::test('a gate cannot write its input, at any depth; the attempt rejects as a throw', function (): void {
    $s = new Stream();
    $seen = [];
    $s->register(new Gate('fx_probe', static function (Event $e) use (&$seen): void {
        $seen['type'] = API::attempt(static function () use ($e): void {
            $e->type = 'z';
        });
        $seen['data'] = API::attempt(static function () use ($e): void {
            $e->data = [];
        });
        $seen['field'] = API::attempt(static function () use ($e): void {
            $e->data['v'] = 2;
        });
        $seen['new'] = API::attempt(static function () use ($e): void {
            $e->data['added'] = 1;
        });
        $seen['nested'] = API::attempt(static function () use ($e): void {
            $e->data['list'][0] = 0;
        });
        $seen['deep'] = API::attempt(static function () use ($e): void {
            $e->data['m']['inner']['x'] = 0;
        });
        $seen['after'] = $e->type . ':' . $e->data['v'] . ':' . $e->data['list'][0] . ':' . $e->data['m']['inner']['x'];
        $seen['added'] = $e->data['added'] ?? null;
    }));
    $s->emit(new Event('fx_probe', ['v' => 1, 'list' => [1, 2], 'm' => ['inner' => ['x' => 5]]]));
    foreach (['type', 'data', 'field', 'new', 'nested', 'deep'] as $k) {
        H::assertEqual($seen[$k], false, 'write to ' . $k . ' refused');
    }
    H::assertEqual($seen['after'], 'fx_probe:1:1:5', 'the input reads back unchanged');
    H::assertEqual($seen['added'], null);

    $t = new Stream();
    $t->register(new Gate('fx_writer', static function (Event $e): void {
        $e->data['v'] = 2;
    }));
    $r = null;
    try {
        $t->emit(new Event('fx_writer', ['v' => 1]));
    } catch (\Throwable $x) {
        $r = $x;
    }
    H::assert($r !== null, 'a writing gate settled');
    noProblems(API::rejectionProblems($r, ['code' => 'SISO_GATE_THREW', 'eventId' => 1, 'eventType' => 'fx_writer']));
    H::assert(isRefusal($r->cause), 'the cause is the refusal: ' . $r->cause->getMessage());
});

H::test('reads: indexing, #, ipairs and pairs; the same field twice is the same value', function (): void {
    $s = new Stream();
    $got = null;
    $s->register(new Gate('fx_read', static function (Event $e) use (&$got): void {
        $d = $e->data;
        $sum = 0;
        foreach ($d['list'] as $v) {
            $sum += $v;
        }
        $got = [
            'len' => count($d['list']), 'second' => $d['list'][1], 'sum' => $sum, 'keys' => keysOf($d),
            'eventKeys' => keysOf($e), 'same' => $e->data['m'] === $e->data['m'] && $e->data === $e->data,
            'missing' => $d['nope'] ?? null,
            // PHP: iterating the event by reference (its writable state) is refused
            'stateWritable' => API::attempt(static function () use ($e): void {
                foreach ($e as &$v) {
                    $v = 'z';
                }
            }),
        ];
    }));
    $s->emit(new Event('fx_read', ['list' => [3, 4, 5], 'm' => ['k' => 1], 's' => 'x']));
    H::assertEqual($got['len'], 3);
    H::assertEqual($got['second'], 4);
    H::assertEqual($got['sum'], 12);
    H::assertEqual($got['keys'], 'list,m,s');
    H::assertEqual($got['eventKeys'], 'data,type');
    H::assert($got['same'], 'two reads of one field are ===');
    H::assertEqual($got['missing'], null);
    H::assertEqual($got['stateWritable'], false, 'iteration hands out nothing writable');
});

// ── what leaves through the doors ──────────────

H::test("a sample's and a take's events refuse writes; the stream is unchanged", function (): void {
    $s = new Stream();
    $s->register(new Gate('fx_a', static fn (Event $e) => new Event('fx_out', ['v' => $e->data['v'], 'list' => [1]])));
    $s->emit(new Event('fx_a', ['v' => 1]));
    $r = $s->sampleHere()['residue'][0];
    refuses("a sampled event's type", static function () use ($r): void {
        $r->type = 'z';
    });
    refuses("a sampled event's data", static function () use ($r): void {
        $r->data = [];
    });
    refuses("a sampled event's field", static function () use ($r): void {
        $r->data['v'] = 9;
    });
    refuses("a sampled event's nested list", static function () use ($r): void {
        $r->data['list'][0] = 9;
    });
    H::assertEqual($s->sampleHere()['residue'][0]->data['v'], 1);
    $t = $s->takeResidue()[0];
    // OWNERSHIP — a sample is the receiver's own copy, so compare values
    H::assert($t->type === $r->type && $t->data === $r->data, 'the take hands out the event the sample showed');
    refuses("a taken event's field", static function () use ($t): void {
        $t->data['v'] = 5;
    });
    refuses("a taken event's new key", static function () use ($t): void {
        $t->data['w'] = 5;
    });
    H::assertEqual($t->data['v'], 1);
});

H::test("an observer's records' data refuse writes, settled and rejected", function (): void {
    // PHP: record data is the observer's own array; writing it reaches
    // nothing else, and the Events in it refuse writes
    $s = new Stream();
    $s->register(new Gate('fx_root', static fn () => new Event('fx_mid', ['k' => [2], 'e' => new Event('in', ['w' => 1])])));
    $s->register(new Gate('fx_mid', static fn () => new Event('fx_mid')));
    $batches = [];
    $writes = [];
    $s->attach(static function (array &$b) use (&$writes): void {
        foreach ($b['records'] as $i => $rec) {
            $b['records'][$i]['data']['k'] = 0;
            if (isset($rec['data']['e'])) {
                $e = $rec['data']['e'];
                $writes[] = API::attempt(static function () use ($e): void {
                    $e->data['w'] = 9;
                });
            }
        }
    });
    $s->attach(static function (array $b) use (&$batches): void {
        $batches[] = $b;
    });
    API::attempt(static fn () => $s->emit(new Event('fx_root', ['k' => [1]])));
    $s->emit(new Event('fx_other', ['k' => [3]]));
    H::assertEqual(count($batches), 2);
    H::assertEqual($batches[0]['outcome'], 'rejected');
    H::assertEqual($batches[1]['outcome'], 'settled');
    H::assertEqual($batches[0]['records'][0]['data']['k'], [1], "another observer's write did not reach this record");
    H::assertEqual($batches[0]['records'][1]['data']['k'], [2]);
    H::assertEqual($batches[0]['records'][1]['data']['e']->data['w'], 1);
    H::assertEqual($batches[1]['records'][0]['data']['k'], [3]);
    H::assertEqual($writes, [false], "a write to an Event in a record's data is refused");
    H::assertEqual($s->takeResidue()[0]->data['k'], [3], "an observer's write did not reach the residue");
    // the batch and record arrays are the observer's own and stay writable
    $batches[1]['records'][0]['eventType'] = 'relabelled';
    H::assertEqual($batches[1]['records'][0]['eventType'], 'relabelled');
});

// ── marks and sentinels ────────────────────────

H::test("kit-style marks survive: a frozen table answers its original's metatable", function (): void {
    // PHP: no data marks; a copied event is an Event of the class itself
    $s = new Stream();
    $s->register(new Gate('fx_mark', static fn () => new Event('fx_out', ['list' => [1, 2], 'plain' => []])));
    $s->emit(new Event('fx_mark'));
    $r = $s->sampleHere()['residue'][0];
    H::assert(Event::is($r), 'Event::is recognises a copied event');
    H::assertEqual(get_class($r), get_class(new Event('x')), 'a copied event is of the event class');
    H::assertEqual(get_debug_type($r->data['plain']), 'array');
    H::assertEqual($r->data['list'], [1, 2]);
});

H::test('a __readonly sentinel keeps its identity, nested or as the data itself', function (): void {
    $null = Null_::Value;
    $s = new Stream();
    $inGate = null;
    $s->register(new Gate('fx_null', static function (Event $e) use (&$inGate, $null) {
        $inGate = $e->data['n'];
        return [new Event('fx_out', ['n' => $null, 'list' => [$null]])];
    }));
    $s->emit(new Event('fx_null', ['n' => $null]));
    H::assert($inGate === $null, 'a gate reads the sentinel itself');
    $byType = [];
    foreach ($s->takeResidue() as $e) {
        $byType[$e->type] = $e;
    }
    H::assert($byType['fx_out']->data['n'] === $null, 'a nested sentinel keeps its identity');
    H::assert($byType['fx_out']->data['list'][0] === $null, 'a sentinel in a list keeps its identity');
    H::assertThrows(static fn () => new Event('fx_bare', $null), 'must be of type ?array', 'a sentinel as data is refused by the type');
});

// ── never wrapped twice ────────────────────────

H::test('an event passed on unchanged is not double-wrapped', function (): void {
    // residue taken and entered again: 13.0.1 (core-api OWNERSHIP) entry
    // takes the stream's own copy, an Event, not a wrapper of it
    $s = new Stream();
    $s->emit(new Event('fx_kept', ['v' => 1]));
    $taken = $s->takeResidue()[0];
    H::assert(Event::is($taken), 'taken residue is an event');
    $s->emit($taken);
    $again = $s->takeResidue()[0];
    H::assert($again !== $taken, "re-entered residue is the stream's own copy");
    H::assert(Event::is($again) && get_class($again) === Event::class, 'the copy is an event of the class itself');
    H::assertEqual($again->type . ':' . $again->data['v'], 'fx_kept:1');

    // data passed on by a gate, and an event carried in data and returned
    $t = new Stream();
    $given = null;
    $carried = null;
    $t->register(new Gate('fx_pass', static function (Event $e) use (&$given) {
        $given = $e->data;
        return new Event('fx_next', $e->data);
    }));
    $t->register(new Gate('fx_unwrap', static function (Event $e) use (&$carried) {
        $carried = $e->data['inner'];
        return $e->data['inner'];
    }));
    $t->emit(new Event('fx_pass', ['v' => 1, 'list' => [1]]));
    $out = $t->takeResidue()[0];
    H::assert($out->data === $given, 'data passed on is the same value');
    $t->emit(new Event('fx_unwrap', ['inner' => new Event('fx_inner', ['w' => 2])]));
    $inner = $t->takeResidue()[0];
    H::assert($inner === $carried, 'an event returned from data is the same event');
    H::assertEqual($inner->data['w'], 2);
});

// ── what read-only pins beyond the checks ──────

H::test('a frozen list a gate returns is judged as a list: carried, or rejected', function (): void {
    $s = new Stream();
    $s->register(new Gate('fx_fan', static fn (Event $e) => $e->data['out']));
    $s->emit(new Event('fx_fan', ['out' => [new Event('fx_x'), new Event('fx_y')]]));
    $types = array_map(static fn (Event $e): string => $e->type, $s->takeResidue());
    sort($types, SORT_STRING);
    H::assertEqual(implode(',', $types), 'fx_x,fx_y', 'both members carried, nothing dropped');
    $catch = static function (Event $entry) use ($s): ?\Throwable {
        try {
            $s->emit($entry);
            return null;
        } catch (\Throwable $t) {
            return $t;
        }
    };
    $r = $catch(new Event('fx_fan', ['out' => [new Event('fx_x'), ['type' => 'fake']]]));
    H::assert($r !== null, 'a list read from data with a non-Event member settled');
    noProblems(API::rejectionProblems($r, ['code' => 'SISO_NOT_EVENT', 'eventId' => 1, 'eventType' => 'fx_fan']));
    $r = $catch(new Event('fx_fan', ['out' => [new Event('fx_fan')]]));
    H::assert($r !== null, "a list read from data with the gate's own type settled");
    H::assertEqual($r->code, 'SISO_SELF_LOOP');
    $r = $catch(new Event('fx_fan', ['out' => [new Event('fx_x'), 'named' => 1]]));
    H::assert($r !== null, 'an array read from data with a named key settled');
    H::assertEqual($r->code, 'SISO_NOT_EVENT');
});

H::test("freezing copies: the emitter's original stays its own and is never annotated", function (): void {
    // core 13: the event itself refuses writes before it enters; the array
    // passed to Event stays the caller's.
    $s = new Stream();
    $original = ['v' => 1, 'list' => [1]];
    $entered = new Event('fx_kept', $original);
    refuses("writing a built event's type", static function () use ($entered): void {
        $entered->type = 'changed';
    });
    $s->emit($entered);
    $original['v'] = 2;
    $original['list'][0] = 9;
    $r = $s->sampleHere()['residue'][0];
    H::assertEqual($r->type, 'fx_kept');
    H::assertEqual($r->data['v'], 1);
    H::assertEqual($r->data['list'][0], 1);
    H::assertEqual(keysOf($original), 'list,v', 'the original carries nothing new');
    H::assertEqual($original['v'], 2, "the caller's array is writable");
    H::assertEqual(keysOf($entered), 'data,type', 'the event is never annotated');
});

H::test('a table reached twice is one frozen table; a cycle terminates (not plain data)', function (): void {
    $shared = [1];
    $s = new Stream();
    $s->emit(new Event('fx_shared', ['a' => $shared, 'b' => $shared]));
    $byType = [];
    foreach ($s->takeResidue() as $e) {
        $byType[$e->type] = $e;
    }
    H::assert($byType['fx_shared']->data['a'] === $byType['fx_shared']->data['b'], 'a shared array is one value');
    // PHP: a cycle needs a reference, and Event refuses the recursive array
    $cyclic = ['name' => 'c'];
    $cyclic['self'] = &$cyclic;
    H::assertThrows(static fn () => new Event('fx_cycle', $cyclic), 'is not plain data (recursive array)');
    unset($cyclic['self']);
});

H::test('R3 concession: rawset is not refused (pinned so a change to it is seen)', function (): void {
    // PHP: the substrate's back door, a Reflection write, is refused
    $s = new Stream();
    $s->emit(new Event('fx_kept', ['v' => 1]));
    $r = $s->sampleHere()['residue'][0];
    refuses('a Reflection write on a sampled event', static function () use ($r): void {
        (new \ReflectionProperty($r, 'data'))->setValue($r, ['v' => 2]);
    });
    H::assertEqual($r->data['v'], 1, 'R3 vanishes in PHP');
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

H::test('cost of freezing (printed)', function (): void {
    $lines = [];
    $n = 20000;
    $chain = static function () use ($n): void {
        $s = new Stream();
        for ($i = 0; $i < $n; $i++) {
            $s->register(new Gate('step_' . $i, static fn () => $i < $n - 1 ? new Event('step_' . ($i + 1), []) : null));
        }
        $s->emit(new Event('step_0', []));
    };
    $wide = [];
    for ($i = 1; $i <= 10000; $i++) {
        $wide['k' . $i] = $i;
    }
    $wideChain = static function (bool $rebuild) use ($wide): \Closure {
        return static function () use ($rebuild, $wide): void {
            $s = new Stream();
            for ($i = 1; $i <= 10; $i++) {
                $s->register(new Gate('w_' . $i, static function (Event $e) use ($i, $rebuild) {
                    $d = $e->data;
                    if ($rebuild) {
                        $copy = [];
                        foreach ($d as $k => $v) {
                            $copy[$k] = $v;
                        }
                        $d = $copy;
                    }
                    return new Event('w_' . ($i + 1), $d);
                }));
            }
            $s->emit(new Event('w_1', $wide));
        };
    };
    $row = static function (string $label, float $t) use (&$lines): void {
        $lines[] = sprintf('  %-58s %.4fs', $label, $t);
    };
    $row('20,000-gate chain (register + emit)', best(3, $chain));
    $row('10,000-entry data, 10-gate chain, data passed on', best(3, $wideChain(false)));
    $row('10,000-entry data, 10-gate chain, each gate rebuilds data', best(3, $wideChain(true)));
    echo "read-only cost (hrtime, best of 3; PHP freezes nothing, so there is no baseline arm):\n";
    foreach ($lines as $l) {
        echo $l . "\n";
    }
    H::assert(true);
});

H::finish('stream-freeze');
