<?php
/*
core.test.php — port of lua/sisoTests/core.test.lua (itself the port of
core 11.1 legacy/js/tests/core.test.js). One PHP test per Lua test, same
names, same expectations. The gates below are fixtures for the stream,
not project gates.
  php php/sisoTests/core.test.php      (from the project root)

Turned into PHP's own attempt at the same violation (core-api SUBSTRATE):
  - "register and lookup", "different signatures coexist": Lua reads the
    stream's gate table (s.gates). The stream's state is private in PHP
    (Lua R5 vanishes), so the test shows that reading it from outside
    raises, and pins the lookup through the doors (a claimed entry,
    gateCount).
  - "a gate that throws a non-Error is wrapped the same way": PHP throws
    only objects; a gate's attempt to throw a string raises \Error("Can
    only throw objects"), which is the cause the rejection carries.
  - "no-arg constructor still works", "constructor accepts log": Lua
    reads s.log / s.streamId / s.parentStreamId; here the stream has no
    public property at all (get_object_vars from outside is empty), and
    an argument given to the constructor is dropped by PHP.
  - "longer indirect cycle": the depth is read from sampleHere's
    gateCount, not from the private gate table.
No case is inapplicable.
*/
declare(strict_types=1);

namespace Siso\Tests\Core;

require_once __DIR__ . '/../code/harness.php';
require_once __DIR__ . '/../code/core/event.php';
require_once __DIR__ . '/../code/core/gate.php';
require_once __DIR__ . '/../code/core/stream.php';

use Siso\Core\Event;
use Siso\Core\Gate;
use Siso\Core\Stream;
use Siso\Harness\H;

/** the public fields of an object, or an array's keys, sorted and joined */
function keysOf(object|array $t): string
{
    $ks = array_map('strval', array_keys(is_object($t) ? get_object_vars($t) : $t));
    sort($ks, SORT_STRING);
    return implode(',', $ks);
}

/** try { $s->emit($e) } catch ($err) → the throwable, or null when it settled */
function emitCatch(Stream $s, mixed $e): ?\Throwable
{
    try {
        $s->emit($e);
        return null;
    } catch (\Throwable $t) {
        return $t;
    }
}

/** a list of events of the given types, with empty data */
function events(array $types): array
{
    return array_map(static fn (string $t): Event => new Event($t, []), $types);
}

// ── Event ──────────────────────────────────────

H::test('Event: type and data', function (): void {
    $e = new Event('foo', ['x' => 1]);
    H::assertEqual($e->type, 'foo');
    H::assertEqual($e->data['x'], 1);
});

H::test('Event: data defaults to empty object', function (): void {
    $e = new Event('bar');
    H::assertEqual($e->type, 'bar');
    H::assert(is_array($e->data));
    H::assertEqual(count($e->data), 0);
});

// ── Gate ───────────────────────────────────────

H::test('Gate: signature stored', function (): void {
    $g = new Gate('test_sig');
    H::assertEqual($g->signature, 'test_sig');
});

H::test('Gate: transform returns undefined by default', function (): void {
    $g = new Gate('noop');
    $result = ($g->transform)(new Event('noop'));
    H::assertEqual($result, null);
});

// ── Stream: registration ───────────────────────

H::test('Stream: register and lookup', function (): void {
    $s = new Stream();
    $g = new Gate('x');
    $s->register($g);
    // PHP: the gate table is private; reading it from outside raises
    H::assertThrows(static fn () => $s->gates, 'private', 'the gate table is not reachable from outside');
    // the lookup, through the doors: an entry of type x is claimed
    $s->emit(new Event('x'));
    H::assertEqual(count($s->sampleHere()['residue']), 0, 'the registered gate claimed x');
    H::assertEqual($s->sampleHere()['gateCount'], 1);
});

H::test('Stream: signature collision throws', function (): void {
    $s = new Stream();
    $s->register(new Gate('x'));
    H::assertThrows(static fn () => $s->register(new Gate('x')), 'Signature collision');
});

H::test('Stream: different signatures coexist', function (): void {
    $s = new Stream();
    $s->register(new Gate('a'));
    $s->register(new Gate('b'));
    H::assertEqual($s->sampleHere()['gateCount'], 2);
    $s->emit(new Event('a'));
    $s->emit(new Event('b'));
    H::assertEqual(count($s->sampleHere()['residue']), 0, 'both gates claim');
});

// ── Stream: emit and residue ───────────────────

H::test('Stream: unclaimed event goes to residue', function (): void {
    $s = new Stream();
    $s->emit(new Event('unknown', ['v' => 42]));
    $sample = $s->sampleHere();
    H::assertEqual(count($sample['residue']), 1);
    H::assertEqual($sample['residue'][0]->type, 'unknown');
    H::assertEqual($sample['residue'][0]->data['v'], 42);
});

H::test('Stream: claimed event does not go to residue', function (): void {
    $s = new Stream();
    $s->register(new Gate('sink', static fn () => null));
    $s->emit(new Event('sink', []));
    H::assertEqual(count($s->sampleHere()['residue']), 0);
});

H::test('Stream: gate receives event only', function (): void {
    $transformArgs = null;
    $s = new Stream();
    $s->register(new Gate('spy', static function (...$args) use (&$transformArgs) {
        $event = $args[0];
        $transformArgs = [
            'argCount' => count($args),
            'type' => $event->type,
            'data' => $event->data,
        ];
        return null;
    }));
    $s->emit(new Event('spy', ['val' => 99]));
    H::assertEqual($transformArgs['argCount'], 1);
    H::assertEqual($transformArgs['type'], 'spy');
    H::assertEqual($transformArgs['data']['val'], 99);
});

H::test('Stream: gate returns single event → re-emitted', function (): void {
    $s = new Stream();
    $s->register(new Gate('double', static fn (Event $event) => new Event('result', ['value' => $event->data['x'] * 2])));
    $s->emit(new Event('double', ['x' => 5]));
    $sample = $s->sampleHere();
    H::assertEqual(count($sample['residue']), 1);
    H::assertEqual($sample['residue'][0]->data['value'], 10);
});

H::test('Stream: gate returns array of events → all re-emitted', function (): void {
    $s = new Stream();
    $s->register(new Gate('split', static fn (Event $event) => [
        new Event('part_a', ['half' => intdiv($event->data['x'], 2)]),
        new Event('part_b', ['doubled' => $event->data['x'] * 2]),
    ]));
    $s->emit(new Event('split', ['x' => 10]));
    $sample = $s->sampleHere();
    H::assertEqual(count($sample['residue']), 2);
    H::assertEqual($sample['residue'][0]->data['half'], 5);
    H::assertEqual($sample['residue'][1]->data['doubled'], 20);
});

H::test('Stream: gate returns null → consumed, no follow-up', function (): void {
    $s = new Stream();
    $s->register(new Gate('consume', static fn () => null));
    // 12.0: no eventCount; the one delivery is seen in an observer's batch
    $batches = [];
    $s->attach(static function (array $b) use (&$batches): void {
        $batches[] = $b;
    });
    $s->emit(new Event('consume', []));
    H::assertEqual(count($s->sampleHere()['residue']), 0);
    H::assertEqual(count($batches), 1, 'one batch');
    H::assertEqual(count($batches[0]['records']), 1, 'one delivery');
    H::assertEqual($batches[0]['records'][0]['claimed'], true, 'claimed by the gate');
});

H::test('Stream: gate returns undefined → same as null', function (): void {
    $s = new Stream();
    $s->register(new Gate('silent', static function (): void {
        // no return
    }));
    $s->emit(new Event('silent', []));
    H::assertEqual(count($s->sampleHere()['residue']), 0);
});

H::test('Stream: chain processes in causal order', function (): void {
    $order = [];
    $s = new Stream();
    $s->register(new Gate('a', static function () use (&$order) {
        $order[] = 'a';
        return new Event('b', []);
    }));
    $s->register(new Gate('b', static function () use (&$order) {
        $order[] = 'b';
        return new Event('c', []);
    }));
    $s->register(new Gate('c', static function () use (&$order) {
        $order[] = 'c';
        return null;
    }));
    $s->emit(new Event('a', []));
    H::assertEqual(implode(',', $order), 'a,b,c');
});

H::test('Stream: fan-out delivers all branches', function (): void {
    $order = [];
    $s = new Stream();
    $s->register(new Gate('root', static fn () => [new Event('left', []), new Event('right', [])]));
    $s->register(new Gate('left', static function () use (&$order) {
        $order[] = 'left';
        return null;
    }));
    $s->register(new Gate('right', static function () use (&$order) {
        $order[] = 'right';
        return null;
    }));
    $s->emit(new Event('root', []));
    H::assertEqual(count($order), 2);
    H::assert(in_array('left', $order, true), 'left branch fired');
    H::assert(in_array('right', $order, true), 'right branch fired');
});

// ── Stream: delivery discipline ────────────────

H::test('Stream: delivery is breadth-first — causal generations', function (): void {
    $order = [];
    $mk = static function (string $sig, ?array $out) use (&$order): Gate {
        return new Gate($sig, static function () use ($sig, $out, &$order) {
            $order[] = $sig;
            return $out !== null ? events($out) : null;
        });
    };
    $s = new Stream();
    $s->register($mk('root', ['a', 'b']));
    $s->register($mk('a', ['a1']));
    $s->register($mk('b', ['b1']));
    $s->register($mk('a1', null));
    $s->register($mk('b1', null));
    $s->emit(new Event('root', []));
    // Generation by generation: root, then both children, then both
    // grandchildren. Depth-first would give root,a,a1,b,b1. Order is not
    // semantic (Rule 3) — this pins the implementation choice, not a
    // contract.
    H::assertEqual(implode(',', $order), 'root,a,b,a1,b1');
});

H::test('Stream: chain length unbounded by call stack', function (): void {
    // A legal chain of N distinct gates: step_0 → step_1 → … No type
    // repeats, so acyclicity holds — chain length is bounded by gate
    // count, exactly as Rule 3 permits. Queue delivery spends no call
    // stack.
    $n = 20000;
    $s = new Stream();
    for ($i = 0; $i < $n; $i++) {
        $s->register(new Gate('step_' . $i, static fn () => $i < $n - 1 ? new Event('step_' . ($i + 1), []) : null));
    }
    // 12.0: no eventCount; an observer's batch shows the N deliveries
    $deliveries = null;
    $s->attach(static function (array $b) use (&$deliveries): void {
        $deliveries = count($b['records']);
    });
    $s->emit(new Event('step_0', []));
    H::assertEqual($deliveries, $n);
    H::assertEqual(count($s->sampleHere()['residue']), 0);
});

H::test('Stream: events are never annotated by delivery', function (): void {
    $s = new Stream();
    $s->register(new Gate('pass', static fn () => new Event('out', ['v' => 1])));
    $rootEvent = new Event('pass', []);
    $s->emit($rootEvent);
    $out = $s->sampleHere()['residue'][0];
    H::assertEqual(keysOf($rootEvent), 'data,type', 'emitted event untouched');
    H::assertEqual(keysOf($out), 'data,type', 'delivered event untouched');
});

// ── Event: shape ───────────────────────────────

H::test('Event: carries only type and data', function (): void {
    // Core 11 removed the retries field. A stray third argument must not
    // attach anything to the event.
    $e = new Event('foo', ['x' => 1], ['attempt' => 1, 'max' => 3]);
    H::assertEqual(keysOf($e), 'data,type');
});

// ── Stream: acyclicity (enforced) ──────────────

H::test('Stream: self-loop throws SISO_SELF_LOOP', function (): void {
    $s = new Stream();
    $s->register(new Gate('echo', static fn (Event $event) => new Event('echo', ['v' => $event->data['v'] + 1])));
    $err = emitCatch($s, new Event('echo', ['v' => 0]));
    H::assert($err !== null, 'expected a throw');
    H::assertEqual($err->code, 'SISO_SELF_LOOP');
    H::assertEqual($err->eventType, 'echo');
    H::assertEqual(count($s->sampleHere()['residue']), 0, 'nothing committed');
});

H::test('Stream: self-loop inside a fan-out array throws', function (): void {
    $s = new Stream();
    $s->register(new Gate('mixed', static fn () => [new Event('other', []), new Event('mixed', [])]));
    H::assertThrows(static fn () => $s->emit(new Event('mixed', [])), 'Self-loop');
});

H::test('Stream: indirect cycle a → b → a throws SISO_CYCLE', function (): void {
    $s = new Stream();
    $s->register(new Gate('a', static fn () => new Event('b', [])));
    $s->register(new Gate('b', static fn () => new Event('a', [])));
    $err = emitCatch($s, new Event('a', []));
    H::assert($err !== null, 'expected a throw — core 10 looped forever here');
    H::assertEqual($err->code, 'SISO_CYCLE');
    // Caught at the first claimed event deeper than the gate count.
    H::assertEqual($err->depth, $s->sampleHere()['gateCount'] + 1);
});

H::test('Stream: longer indirect cycle a → b → c → a throws', function (): void {
    $mk = static fn (string $sig, string $out): Gate => new Gate($sig, static fn () => new Event($out, []));
    $s = new Stream();
    $s->register($mk('a', 'b'));
    $s->register($mk('b', 'c'));
    $s->register($mk('c', 'a'));
    H::assertThrows(static fn () => $s->emit(new Event('a', [])), 'Cycle');
});

H::test('Stream: same type in sibling branches is legal', function (): void {
    // Acyclicity is per causal path, not across the tree.
    $mk = static fn (string $sig, ?array $out): Gate => new Gate($sig, static fn () => $out !== null ? events($out) : null);
    $s = new Stream();
    $s->register($mk('root', ['x', 'y']));
    $s->register($mk('x', ['z']));
    $s->register($mk('y', ['z']));
    $s->register($mk('z', ['done']));
    $s->emit(new Event('root', []));
    $residue = $s->sampleHere()['residue'];
    H::assertEqual(count($residue), 2, 'z ran on both branches');
    $every = true;
    foreach ($residue as $e) {
        if ($e->type !== 'done') {
            $every = false;
        }
    }
    H::assert($every);
});

H::test('Stream: longest legal chain ends unclaimed at depth gates + 1', function (): void {
    // Every gate claimed once, then a final unclaimed event. That is the
    // deepest a legal chain can go — no throw.
    $mk = static fn (string $sig, string $out): Gate => new Gate($sig, static fn () => new Event($out, []));
    $s = new Stream();
    $s->register($mk('a', 'b'));
    $s->register($mk('b', 'c'));
    $s->register($mk('c', 'answer'));
    $s->emit(new Event('a', []));
    H::assertEqual($s->sampleHere()['residue'][0]->type, 'answer');
});

H::test('Stream: a propagation that throws commits no residue', function (): void {
    $mk = static fn (string $sig, array $out): Gate => new Gate($sig, static fn () => events($out));
    $s = new Stream();
    $s->register($mk('root', ['leftover', 'a']));
    $s->register($mk('a', ['b']));
    $s->register($mk('b', ['a']));
    $s->emit(new Event('earlier', []));            // settles normally
    H::assertThrows(static fn () => $s->emit(new Event('root', [])), 'Cycle');
    $residue = $s->sampleHere()['residue'];
    H::assertEqual(count($residue), 1, 'only the earlier residue remains');
    H::assertEqual($residue[0]->type, 'earlier');
});

H::test('Stream: stream remains usable after a throw', function (): void {
    $s = new Stream();
    $s->register(new Gate('echo', static fn () => new Event('echo', [])));
    H::assertThrows(static fn () => $s->emit(new Event('echo', [])), 'Self-loop');
    $s->emit(new Event('fine', []));
    H::assertEqual(count($s->sampleHere()['residue']), 1);
});

H::test('Stream: a gate that throws rejects with SISO_GATE_THREW, ids and cause', function (): void {
    $original = new \TypeError('bad input');
    $s = new Stream();
    $s->register(new Gate('a', static fn () => new Event('b', [])));
    $s->register(new Gate('b', static function () use ($original) {
        throw $original;
    }));
    $err = emitCatch($s, new Event('a', []));
    H::assert($err !== null, 'emit threw');
    H::assertEqual($err->code, 'SISO_GATE_THREW');
    H::assertEqual($err->eventType, 'b', 'the event the gate was given');
    H::assertEqual($err->eventId, 2, 'the delivery that threw');
    H::assertEqual($err->causeId, 1, 'the delivery that caused it');
    H::assert($err->cause === $original, "the gate's own error is kept as cause");
    H::assert(str_contains($err->message, 'bad input'), 'message carries the original');
});

H::test('Stream: a gate that throws commits no residue; stream stays usable', function (): void {
    $s = new Stream();
    $s->register(new Gate('root', static fn () => [new Event('leftover', []), new Event('b', [])]));
    $s->register(new Gate('b', static function () {
        throw new \Exception('boom');
    }));
    H::assertThrows(static fn () => $s->emit(new Event('root', [])), 'boom');
    H::assertEqual(count($s->sampleHere()['residue']), 0, 'the queued sibling is not committed');
    $s->emit(new Event('fine', []));
    H::assertEqual(count($s->sampleHere()['residue']), 1);
});

H::test('Stream: a gate that throws a non-Error is wrapped the same way', function (): void {
    // PHP: a gate's attempt to throw a string raises \Error("Can only throw objects")
    $s = new Stream();
    $s->register(new Gate('x', static function () {
        $plain = 'plain string';
        throw $plain;
    }));
    $err = emitCatch($s, new Event('x', []));
    H::assert($err instanceof \Throwable && (string)$err === $err->getMessage(), 'a real Throwable leaves emit');
    H::assertEqual($err->code, 'SISO_GATE_THREW');
    H::assert($err->cause instanceof \Error && $err->cause->getMessage() === 'Can only throw objects',
        "the cause is PHP's refusal of the non-object throw");
    H::assertEqual($err->causeId, null, 'an external entry has no cause');
});

// ── Stream: take residue ───────────────────────

H::test('Stream: takeResidue returns the residue and removes it', function (): void {
    $s = new Stream();
    $s->emit(new Event('x', ['v' => 1]));
    $s->emit(new Event('y', ['v' => 2]));
    $taken = $s->takeResidue();
    H::assertEqual(count($taken), 2);
    H::assertEqual($taken[0]->type, 'x');
    H::assertEqual($taken[1]->data['v'], 2);
    H::assertEqual(count($s->sampleHere()['residue']), 0, 'stream emptied');
});

H::test('Stream: residue after a take holds only new arrivals', function (): void {
    $s = new Stream();
    $s->emit(new Event('old', []));
    $s->takeResidue();
    $s->emit(new Event('new', []));
    $residue = $s->sampleHere()['residue'];
    H::assertEqual(count($residue), 1);
    H::assertEqual($residue[0]->type, 'new');
});

H::test('Stream: takeResidue on an empty stream returns []', function (): void {
    $s = new Stream();
    $taken = $s->takeResidue();
    H::assert($taken === []);
});

H::test('Stream: sampling does not take', function (): void {
    $s = new Stream();
    $s->emit(new Event('x', []));
    $s->sampleHere();
    $s->sampleHere();
    H::assertEqual(count($s->takeResidue()), 1);
});

// ── Stream: sampleHere ─────────────────────────

H::test('Stream: sampleHere returns copy of residue', function (): void {
    $s = new Stream();
    $s->emit(new Event('x', []));
    $sample = $s->sampleHere();
    array_pop($sample['residue']);
    H::assertEqual(count($s->sampleHere()['residue']), 1, 'original not affected');
});

H::test('Stream: eventCount tracks all emits including re-emits', function (): void {
    // 12.0: eventCount is gone (ledger R1 retired). Each propagation
    // numbers its own deliveries 1, 2, … including re-emits, shown to an
    // observer; nothing is counted across propagations and the sample has
    // no counter.
    $s = new Stream();
    $s->register(new Gate('echo', static fn () => new Event('out', [])));
    $ids = [];
    $s->attach(static function (array $b) use (&$ids): void {
        $ids[] = implode(',', array_map(static fn (array $r): string => (string)$r['eventId'], $b['records']));
    });
    $s->emit(new Event('echo', []));
    $s->emit(new Event('echo', []));
    H::assertEqual($ids[0], '1,2', 'the entry and its re-emit');
    H::assertEqual($ids[1], '1,2', 'the next propagation numbers its own');
    H::assertEqual(keysOf($s->sampleHere()), 'gateCount,residue', 'no counter in the sample');
});

H::test('Stream: gateCount in sampleHere', function (): void {
    $s = new Stream();
    $s->register(new Gate('a'));
    $s->register(new Gate('b'));
    $s->register(new Gate('c'));
    H::assertEqual($s->sampleHere()['gateCount'], 3);
});

H::test('Stream: residue accumulates across emits', function (): void {
    $s = new Stream();
    $s->emit(new Event('x', []));
    $s->emit(new Event('y', []));
    H::assertEqual(count($s->sampleHere()['residue']), 2);
});

// ── Stream: constructor options ────────────────

H::test('Stream: no-arg constructor still works', function (): void {
    // 12.0: new Stream() is the only constructor: no gates, no residue, no
    // observers, and no log, stream id or lineage on the stream (PHP: it
    // has no public property at all).
    $s = new Stream();
    H::assertEqual(get_object_vars($s), [], 'no public property (no log, streamId, parentStreamId)');
    $sample = $s->sampleHere();
    H::assertEqual(count($sample['residue']), 0);
    H::assertEqual($sample['gateCount'], 0);
    $seen = 0;
    $s->attach(static function () use (&$seen): void {
        $seen++;
    });
    $s->emit(new Event('x', []));
    H::assertEqual($seen, 1, 'the only observer is the one attached');
});

H::test('Stream: constructor accepts log', function (): void {
    // 12.0: Stream takes no options. A log is an observer, attached through
    // the observe door; an argument is dropped (PHP), so nothing is called
    // on it and the stream holds no log or stream id.
    $calls = 0;
    $fakeLog = [
        'nextStreamId' => static function () use (&$calls): int {
            $calls++;
            return 42;
        },
        'record' => static function () use (&$calls): void {
            $calls++;
        },
    ];
    $s = new Stream(['log' => $fakeLog, 'parentStreamId' => 7]);
    $s->emit(new Event('x', []));
    H::assertEqual($calls, 0, 'the log option is not read');
    H::assertEqual(get_object_vars($s), [], 'no log, streamId or parentStreamId on the stream');
    $batches = 0;
    $s->attach(static function () use (&$batches): void {
        $batches++;
    });
    $s->emit(new Event('y', []));
    H::assertEqual($batches, 1, 'an observer attaches through the door');
});

H::finish('core');
