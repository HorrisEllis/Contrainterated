<?php
/*
stream-doors.test.php — the core's doors against core-api and
observer-seam. Port of lua/sisoTests/stream-doors.test.lua, case by case.
Runs both contracts' checks on the real core, then pins what the checks
leave open. The gates below are fixtures for the stream, not project
gates.
  php php/sisoTests/stream-doors.test.php      (from the project root)

Turned into PHP's own attempt at the same violation (core-api SUBSTRATE):
  - "each builder carries exactly its code's fields": a PHP rejection has
    all seven public fields, each null when its code does not carry it, so
    the test pins the set of non-null fields; a gate-threw cause is a
    \Throwable (PHP throws only objects).
  - "a gate's raised value is kept exactly, even nil": PHP cannot throw
    null; the gate's attempt raises \Error("Can only throw objects"), and
    that \Error is the cause, kept exactly.
  - "ids restart at 1 … no counter on the stream": Lua reads
    s.eventCount; here the stream has no public property at all.
  - "a list starting at 2" is a list starting at 1 (PHP lists start at 0).
  - "attach takes a function; misuse raises a string": PHP raises the
    contract's \LogicException, not a string.
  - "a refused entry's batch …" and the NOT_EVENT entry cases add PHP's
    own non-events (null, a float, a stdClass that looks like an event).
Added in PHP (core-api SUBSTRATE: Release; independent review, 13.1.0):
  - a gate's return whose release throws (an object's __destruct, a
    started generator's finally): the judged SISO_NOT_EVENT stands and is
    the only thing emit throws, with observers attached or not.
No case is inapplicable.
*/
declare(strict_types=1);

namespace Siso\Tests\StreamDoors;

require_once __DIR__ . '/../code/harness.php';
require_once __DIR__ . '/../code/contracts/core_api.php';
require_once __DIR__ . '/../code/contracts/observer_seam.php';
require_once __DIR__ . '/../code/core/event.php';
require_once __DIR__ . '/../code/core/gate.php';
require_once __DIR__ . '/../code/core/stream.php';
require_once __DIR__ . '/../code/core/rejection.php';

use Siso\Contracts\CoreApi as API;
use Siso\Contracts\ObserverSeam as SEAM;
use Siso\Core\Event;
use Siso\Core\Gate;
use Siso\Core\Rejection;
use Siso\Core\Stream;
use Siso\Harness\H;

const CORE = ['Event' => Event::class, 'Gate' => Gate::class, 'Stream' => Stream::class];

function joined(array $list): string
{
    return implode('; ', $list);
}

function keysOf(object|array $t): string
{
    $ks = array_map('strval', array_keys(is_object($t) ? get_object_vars($t) : $t));
    sort($ks, SORT_STRING);
    return implode(',', $ks);
}

/** the public fields of an object that are not null, sorted and joined */
function carried(object $o): string
{
    return keysOf(array_filter(get_object_vars($o), static fn ($v): bool => $v !== null));
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

function noProblems(array $list, ?string $label = null): void
{
    H::assert($list === [], ($label !== null ? $label . ': ' : '') . joined($list));
}

/** a stream with one fixture gate `sig` returning `ret` */
function oneGate(string $sig, mixed $ret): Stream
{
    $s = new Stream();
    $s->register(new Gate($sig, static fn () => $ret));
    return $s;
}

// ── the contracts' own checks ──────────────────

H::test('core-api: check.doors holds on the real core', function (): void {
    noProblems(API::checkDoors(CORE));
});

H::test('observer-seam: check.seam holds on the real core', function (): void {
    noProblems(SEAM::checkSeam(CORE));
});

// ── rejection objects (core/rejection.php) ─────

H::test("rejection: each builder carries exactly its code's fields", function (): void {
    $d = ['id' => 3, 'causeId' => 2, 'type' => 't', 'depth' => 3];
    $cases = [
        [Rejection::selfLoop($d, 't'), 'causeId,code,eventId,eventType,message'],
        [Rejection::cycle($d, 2), 'causeId,code,depth,eventId,eventType,message'],
        [Rejection::gateThrew($d, 't', new \RuntimeException('x')), 'cause,causeId,code,eventId,eventType,message'],
        [Rejection::notEvent($d, 't', 'a string'), 'causeId,code,eventId,eventType,message'],
        [Rejection::notEvent(null, null, 'null'), 'code,message'],
    ];
    foreach ($cases as $i => [$r, $fields]) {
        $n = $i + 1;
        H::assertEqual(carried($r), $fields, 'case ' . $n);
        H::assertEqual(keysOf($r), 'cause,causeId,code,depth,eventId,eventType,message', 'case ' . $n . ': every field present');
        noProblems(API::rejectionProblems($r), 'case ' . $n);
        H::assertEqual((string)$r, $r->message, 'case ' . $n . ' (string)');
        H::assertEqual($r->getCode(), $r->code, 'case ' . $n . ' getCode()');
        H::assert(Rejection::is($r), 'case ' . $n . ' Rejection::is');
    }
    H::assert(!Rejection::is(['code' => 'SISO_CYCLE', 'message' => 'm']), 'an array is not a rejection');
    H::assert(!Rejection::is((object)['code' => 'SISO_CYCLE', 'message' => 'm']), 'an object that looks like one is not a rejection');
});

H::test("rejection: a gate's raised value is kept exactly, even nil", function (): void {
    // PHP: a gate's attempt to throw null raises \Error; that is the cause
    $caught = null;
    $s = new Stream();
    $s->register(new Gate('raises_nil', static function () use (&$caught) {
        try {
            $nothing = null;
            throw $nothing;
        } catch (\Error $e) {
            $caught = $e;
            throw $e;
        }
    }));
    $r = emitCatch($s, new Event('raises_nil'));
    H::assertEqual($r->code, 'SISO_GATE_THREW');
    H::assert($r->cause === $caught, 'the cause is exactly what the gate raised');
    H::assertEqual($r->cause->getMessage(), 'Can only throw objects');
    noProblems(API::rejectionProblems($r));
});

// ── SISO_SELF_LOOP carries causeId ─────────────

H::test('SISO_SELF_LOOP names the returning delivery and its cause', function (): void {
    $s = new Stream();
    $s->register(new Gate('fx_head', static fn () => [new Event('fx_side'), new Event('fx_loop')]));
    $s->register(new Gate('fx_loop', static fn () => new Event('fx_loop')));
    $r = emitCatch($s, new Event('fx_head'));
    noProblems(API::rejectionProblems($r, [
        'code' => 'SISO_SELF_LOOP', 'eventId' => 3, 'causeId' => 1, 'eventType' => 'fx_loop',
    ]));
});

// ── SISO_NOT_EVENT: the entry door ─────────────

H::test('SISO_NOT_EVENT at the entry: nothing is delivered', function (): void {
    $delivered = false;
    $s = new Stream();
    $s->register(new Gate('x', static function () use (&$delivered): void {
        $delivered = true;
    }));
    foreach (['x', 42, 1.5, false, true, null, [], ['type' => 'x', 'data' => []], (object)['type' => 'x', 'data' => []], new Gate('x')] as $v) {
        $r = emitCatch($s, $v);
        noProblems(API::rejectionProblems($r, ['code' => 'SISO_NOT_EVENT', 'noDelivery' => true]), get_debug_type($v));
    }
    H::assert(!$delivered, 'a refused entry reached a gate');
    H::assertEqual(count($s->sampleHere()['residue']), 0);
});

// ── SISO_NOT_EVENT: gate returns ───────────────

H::test('SISO_NOT_EVENT for every return that is not nil, an Event or a list', function (): void {
    $cases = [
        ['false', false],
        ['true', true],
        ['a number', 0],
        ['a float', 0.5],
        ['a string', 'fx_out'],
        ['a function', static function (): void {
        }],
        ['a plain array shaped like an event', ['type' => 'fx_out', 'data' => []]],
        ['an object shaped like an event', (object)['type' => 'fx_out', 'data' => []]],
        ['a list with a named key', [new Event('fx_out'), 'extra' => new Event('fx_out')]],
        ['a list with a hole', [0 => new Event('fx_out'), 2 => new Event('fx_out')]],
        ['a list starting at 1', [1 => new Event('fx_out')]],
        ['a list with a plain-array member', [new Event('fx_out'), ['type' => 'fx_out', 'data' => []]]],
        ['a list with a string member', [new Event('fx_out'), 'fx_out']],
        ['a list with a false member', [false]],
        ['a list of lists', [[new Event('fx_out')]]],
    ];
    foreach ($cases as [$label, $ret]) {
        $s = new Stream();
        $s->emit(new Event('before'));
        $before = $s->sampleHere()['residue'][0];
        $s->register(new Gate('fx_feed', static fn () => new Event('fx_bad')));
        $s->register(new Gate('fx_bad', static fn () => $ret));
        $r = emitCatch($s, new Event('fx_feed'));
        noProblems(API::rejectionProblems($r, [
            'code' => 'SISO_NOT_EVENT', 'eventId' => 2, 'causeId' => 1, 'eventType' => 'fx_bad',
        ]), $label);
        $residue = $s->sampleHere()['residue'];
        // OWNERSHIP — a sample is the receiver's own copy, so compare values
        H::assert(count($residue) === 1 && $residue[0]->type === $before->type && $residue[0]->data === $before->data,
            $label . ': the residue changed');
    }
});

/**
 * Runs $ret's gate on a fresh stream holding earlier residue, with an
 * observer when asked; → [what emit threw, the observer's batch, the
 * residue count after, whether the stream settled a later entry].
 */
function releaseRun(\Closure $ret, bool $observed): array
{
    $s = new Stream();
    $s->emit(new Event('kept'));
    $s->register(new Gate('fx_feed', static fn () => new Event('fx_bad')));
    $s->register(new Gate('fx_bad', $ret));
    $b = null;
    if ($observed) {
        $s->attach(static function (array $batch) use (&$b): void {
            $b ??= $batch;
        });
    }
    $r = emitCatch($s, new Event('fx_feed'));
    $after = count($s->sampleHere()['residue']);
    $s->emit(new Event('fine'));
    return [$r, $b, $after, count($s->takeResidue()) === 2];
}

H::test("a gate's return whose __destruct throws on release: the judged SISO_NOT_EVENT is all emit throws", function (): void {
    $released = 0;
    $dtor = static function () use (&$released): object {
        return new class ($released) {
            public function __construct(private int &$count)
            {
            }

            public function __destruct()
            {
                $this->count++;
                throw new \RuntimeException('released');
            }
        };
    };
    $cases = [
        'the object itself' => static fn () => $dtor(),
        'the object as a list member' => static fn () => [new Event('fx_ok'), $dtor()],
    ];
    foreach ($cases as $label => $ret) {
        foreach ([false, true] as $observed) {
            $where = $label . ($observed ? ', observed' : '');
            $before = $released;
            [$r, $b, $after, $usable] = releaseRun($ret, $observed);
            H::assert($r instanceof Rejection, $where . ': emit threw ' . ($r === null ? 'nothing' : get_class($r) . ': ' . $r->getMessage()));
            noProblems(API::rejectionProblems($r, [
                'code' => 'SISO_NOT_EVENT', 'eventId' => 2, 'causeId' => 1, 'eventType' => 'fx_bad',
            ]), $where);
            H::assertEqual($released, $before + 1, $where . ': the return was released once, by the stream');
            H::assertEqual($after, 1, $where . ': nothing committed');
            H::assert($usable, $where . ': the stream is usable afterwards');
            if ($observed) {
                noProblems(SEAM::batchProblems($b), $where);
                H::assert(SEAM::rejectionCopyProblem($b['rejection'], $r, Event::class) === null, $where . ': the batch shows the rejection');
            }
        }
    }
});

H::test("a gate's return that is a started generator whose finally throws: the judged SISO_NOT_EVENT is all emit throws", function (): void {
    $finished = 0;
    $ret = static function () use (&$finished) {
        $g = (static function () use (&$finished) {
            try {
                yield 1;
            } finally {
                $finished++;
                throw new \RuntimeException('released');
            }
        })();
        $g->current();
        return $g;
    };
    foreach ([false, true] as $observed) {
        $where = $observed ? 'observed' : 'unobserved';
        $before = $finished;
        [$r, $b, $after, $usable] = releaseRun($ret, $observed);
        H::assert($r instanceof Rejection, $where . ': emit threw ' . ($r === null ? 'nothing' : get_class($r) . ': ' . $r->getMessage()));
        noProblems(API::rejectionProblems($r, [
            'code' => 'SISO_NOT_EVENT', 'eventId' => 2, 'causeId' => 1, 'eventType' => 'fx_bad',
        ]), $where);
        H::assert(str_contains($r->message, 'Generator'), $where . ': the rejection names what was returned: ' . $r->message);
        H::assertEqual($finished, $before + 1, $where . ": the generator's finally ran once, on release");
        H::assertEqual($after, 1, $where . ': nothing committed');
        H::assert($usable, $where . ': the stream is usable afterwards');
        if ($observed) {
            H::assertEqual($b['outcome'] ?? null, 'rejected', $where . ': the observer saw the rejection');
        }
    }
});

H::test('accepted returns: nil, nothing, an Event, an empty list, a list', function (): void {
    $cases = [
        ['nil', null, ''],
        ['an Event', new Event('fx_a'), 'fx_a'],
        ['an empty list', [], ''],
        ['a list', [new Event('fx_a'), new Event('fx_b')], 'fx_a,fx_b'],
    ];
    foreach ($cases as [$label, $ret, $want]) {
        $s = oneGate('fx_g', $ret);
        $s->emit(new Event('fx_g'));
        $t = array_map(static fn (Event $e): string => $e->type, $s->takeResidue());
        sort($t, SORT_STRING);
        H::assertEqual(implode(',', $t), $want, $label);
    }
    $s = new Stream();
    $s->register(new Gate('fx_silent', static function (): void {
    }));
    $s->emit(new Event('fx_silent'));
    H::assertEqual(count($s->takeResidue()), 0, 'nothing returned');
});

H::test('a list is judged in list order: the first bad member decides', function (): void {
    $r1 = emitCatch(oneGate('fx_g', [new Event('fx_ok'), 42, new Event('fx_g')]), new Event('fx_g'));
    H::assertEqual($r1->code, 'SISO_NOT_EVENT', 'non-Event before the self-loop');
    $r2 = emitCatch(oneGate('fx_g', [new Event('fx_ok'), new Event('fx_g'), 42]), new Event('fx_g'));
    H::assertEqual($r2->code, 'SISO_SELF_LOOP', 'self-loop before the non-Event');
    $r3 = emitCatch(oneGate('fx_g', [new Event('fx_g'), 'extra' => 1]), new Event('fx_g'));
    H::assertEqual($r3->code, 'SISO_SELF_LOOP', "a member's self-loop before a named key");
});

// ── ids are per propagation ────────────────────

H::test('ids restart at 1 in each propagation, rejected ones included', function (): void {
    $s = new Stream();
    $s->register(new Gate('fx_a', static fn () => new Event('fx_b')));
    $s->register(new Gate('fx_b', static function () {
        throw new \RuntimeException('boom');
    }));
    $first = emitCatch($s, new Event('fx_a'));
    $s->emit(new Event('other'));
    $s->emit(new Event('other'));
    $again = emitCatch($s, new Event('fx_a'));
    H::assertEqual($first->eventId, 2);
    H::assertEqual($again->eventId, 2, 'nothing counted across propagations');
    H::assertEqual(get_object_vars($s), [], 'no counter on the stream (no public property)');
});

// ── a rejected propagation leaves the stream as it was ──

H::test('a rejection leaves residue, gates and observers exactly as before', function (): void {
    $s = new Stream();
    $s->emit(new Event('kept', ['v' => 1]));
    $before = $s->sampleHere()['residue'];
    $s->register(new Gate('fx_root', static fn () => [new Event('fx_leftover'), new Event('fx_x')]));
    $s->register(new Gate('fx_x', static fn () => new Event('fx_y')));
    $s->register(new Gate('fx_y', static fn () => new Event('fx_x')));
    $batches = [];
    $s->attach(static function (array $b) use (&$batches): void {
        $batches[] = $b;
    });
    $r = emitCatch($s, new Event('fx_root'));
    H::assertEqual($r->code, 'SISO_CYCLE');
    $after = $s->sampleHere();
    H::assertEqual(count($after['residue']), 1);
    // OWNERSHIP — a sample is the receiver's own copy, so compare values
    H::assert($after['residue'][0]->type === $before[0]->type && $after['residue'][0]->data === $before[0]->data,
        'the same residue event');
    H::assertEqual($after['gateCount'], 3);
    $s->emit(new Event('next'));
    H::assertEqual(count($batches), 2, 'the observer stays attached');
    H::assertEqual($batches[1]['records'][0]['eventId'], 1);
});

// ── the observe door ───────────────────────────

H::test('observer: records carry ids, causes, types, claims and the data received', function (): void {
    $s = new Stream();
    $given = null;
    $s->register(new Gate('fx_root', static function (Event $e) use (&$given) {
        $given = $e->data;
        return [new Event('fx_mid'), new Event('fx_out', ['k' => 2])];
    }));
    $s->register(new Gate('fx_mid', static fn () => new Event('fx_end')));
    $b = null;
    $s->attach(static function (array $batch) use (&$b): void {
        $b = $batch;
    });
    $s->emit(new Event('fx_root', ['k' => 1]));
    noProblems(SEAM::batchProblems($b));
    H::assertEqual(keysOf($b), 'outcome,records,rejection');
    H::assertEqual($b['rejection'], null, 'a settled batch carries no rejection');
    $shown = array_map(static fn (array $r): string => $r['eventId'] . ':' . API::show($r['causeId']) . ':' . $r['eventType'] . ':'
        . API::show($r['claimed']), $b['records']);
    H::assertEqual(implode(' ', $shown),
        '1:null:fx_root:true 2:1:fx_mid:true 3:1:fx_out:false 4:2:fx_end:false');
    // OWNERSHIP — an observer record is the receiver's own copy, so compare values
    H::assert($b['records'][0]['data'] === $given, 'data is what the gate received');
    H::assertEqual($b['records'][2]['data']['k'], 2);
});

H::test('observer: a rejected batch ends with the named delivery and runs before the raise', function (): void {
    $cases = [
        ['self-loop', static fn () => new Event('fx_bad'), 'SISO_SELF_LOOP'],
        ['not an Event', static fn () => 7, 'SISO_NOT_EVENT'],
        ['threw', static function () {
            throw new \RuntimeException('x');
        }, 'SISO_GATE_THREW'],
    ];
    foreach ($cases as [$label, $fn, $code]) {
        $s = new Stream();
        $s->emit(new Event('kept'));
        $s->register(new Gate('fx_feed', static fn () => [new Event('fx_side'), new Event('fx_bad')]));
        $s->register(new Gate('fx_bad', $fn));
        $b = null;
        $residueSeen = null;
        $s->attach(static function (array $batch) use (&$b, &$residueSeen, $s): void {
            $b = $batch;
            $residueSeen = count($s->sampleHere()['residue']);
        });
        $r = emitCatch($s, new Event('fx_feed'));
        H::assertEqual($r->code, $code, $label);
        noProblems(SEAM::batchProblems($b), $label);
        // core 13 (observer-seam THE REJECTION): the observer's own copy
        $why = SEAM::rejectionCopyProblem($b['rejection'], $r, Event::class);
        H::assert($why === null, $label . ': ' . (string)$why);
        H::assertEqual(count($b['records']), 3, $label . ': records');
        H::assertEqual($b['records'][2]['eventType'], 'fx_bad', $label);
        H::assertEqual($residueSeen, 1, $label . ': nothing committed when the observer ran');
    }
});

H::test("observer: a refused entry's batch carries the rejection and no records", function (): void {
    $s = new Stream();
    $b = null;
    $s->attach(static function (array $batch) use (&$b): void {
        $b = $batch;
    });
    $r = emitCatch($s, ['type' => 'x', 'data' => []]);
    H::assertEqual($b['outcome'], 'rejected');
    $why = SEAM::rejectionCopyProblem($b['rejection'], $r, Event::class);
    H::assert($why === null, 'refused entry: ' . (string)$why);
    H::assertEqual(count($b['records']), 0);
});

H::test("observer: one observer's writes to its records reach no other", function (): void {
    $s = new Stream();
    $seen = null;
    $s->attach(static function (array &$b): void {
        $b['records'][0]['eventType'] = 'changed';
        $b['records'][0]['claimed'] = 'x';
    });
    $s->attach(static function (array $b) use (&$seen): void {
        $seen = $b;
    });
    $s->emit(new Event('q'));
    // the order observers are called in carries no meaning, so run both orders
    $t = new Stream();
    $seen2 = null;
    $t->attach(static function (array $b) use (&$seen2): void {
        $seen2 = $b;
    });
    $t->attach(static function (array &$b): void {
        $b['records'][0]['eventType'] = 'changed';
    });
    $t->emit(new Event('q'));
    H::assertEqual($seen['records'][0]['eventType'], 'q');
    H::assertEqual($seen['records'][0]['claimed'], false);
    H::assertEqual($seen2['records'][0]['eventType'], 'q');
});

H::test('observer: a raising observer leaves a rejection exactly as raised', function (): void {
    $s = new Stream();
    $s->register(new Gate('fx_t', static function () {
        throw new \RuntimeException('gate failure');
    }));
    $s->attach(static function (): void {
        throw new \RuntimeException('observer failure');
    });
    $r = emitCatch($s, new Event('fx_t'));
    H::assertEqual($r->code, 'SISO_GATE_THREW');
    H::assertEqual(str_contains($r->cause->getMessage(), 'gate failure'), true);
});

H::test('observer: an observer attached by another sees only later propagations', function (): void {
    $s = new Stream();
    $late = [];
    $lateObserver = static function (array $b) use (&$late): void {
        $late[] = $b['records'][0]['eventType'];
    };
    $added = false;
    $s->attach(static function () use (&$added, $s, $lateObserver): void {
        if (!$added) {
            $added = true;
            $s->attach($lateObserver);
        }
    });
    $s->emit(new Event('first'));
    $s->emit(new Event('second'));
    H::assertEqual(implode(',', $late), 'second');
});

H::test('observer: attach takes a function; misuse raises a string', function (): void {
    // PHP: misuse throws the contract's \LogicException
    $raises = static function (callable $fn): ?\Throwable {
        try {
            $fn();
            return null;
        } catch (\Throwable $t) {
            return $t;
        }
    };
    $s = new Stream();
    $err = $raises(static fn () => $s->attach([]));
    H::assert($err instanceof \LogicException, 'attaching an array');
    $f = static function (): void {
    };
    $s->attach($f);
    $err = $raises(static fn () => $s->attach($f));
    H::assert($err instanceof \LogicException, 'attaching twice');
    $s->detach($f);
    $err = $raises(static fn () => $s->detach($f));
    H::assert($err instanceof \LogicException, 'detaching twice');
});

H::finish('stream-doors');
