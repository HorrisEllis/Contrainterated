<?php
/*
streamlog: Siso\Observer\StreamLog (php/code/observer/streamlog.php) on the
observer seam (core 13, PHP instance). Port of
lua/sisoTests/streamlog.test.lua, case by case and in its order; that suite
ports 11.1's legacy/js/tests/streamlog.test.js, and its "12:" / "11.1:"
comments are kept where they explain an expected value. Integration cases
drive php/sisoTests/contract-fake-core.php, as the Lua suite drives its
fake core; nothing here requires php/code/core/.

Every Lua case is kept (34 of 34). Where Lua's mechanism does not exist in
PHP, the case tests PHP's own counterpart:
  - Lua's absent field (`e.x == nil`) becomes an absent key
    (!array_key_exists); causeId, an EVENTS field, is a present key whose
    value is null for the entry delivery.
  - Lua's `math.type(t) == "integer"` becomes is_int.
  - "sample returns copy": Lua's copyEntry becomes value semantics; the
    case also writes through a reference into the sample.
  - "labels are per log": Lua's "no module-global counter" is pinned as
    PHP's "no static state" (CoreApi::staticStateOf on StreamLog).
  - pcall(s.emit, …) becomes try/catch; `err.code` / `err.eventId` are the
    rejection's public properties.
Cases that cannot apply in PHP: none.
*/
declare(strict_types=1);

namespace Siso\Tests\StreamLogTest;

require_once __DIR__ . '/../code/harness.php';
require_once __DIR__ . '/../code/observer/streamlog.php';
require_once __DIR__ . '/../code/contracts/observer_seam.php';
require_once __DIR__ . '/contract-fake-core.php';

use Siso\Contracts\CoreApi;
use Siso\Contracts\ObserverSeam;
use Siso\Harness\H;
use Siso\Observer\StreamLog;
use Siso\Tests\Fake\Fake;

$core = Fake::core();
$Event = $core['Event'];
$Gate = $core['Gate'];
$Stream = $core['Stream'];

// A hand-built batch stands in for 11.1's direct log.record(…) calls.
// 12: the log is fed a whole propagation, never one delivery.
function rec(int $id, ?int $cause, string $t, bool $claimed, ?array $data = null): array
{
    return ['eventId' => $id, 'causeId' => $cause, 'eventType' => $t, 'claimed' => $claimed, 'data' => $data ?? []];
}

function batch(array $records, string $outcome = 'settled', ?array $rejection = null): array
{
    return ['outcome' => $outcome, 'rejection' => $rejection, 'records' => $records];
}

function one(string $t, bool $claimed, ?array $data = null): array
{
    return batch([rec(1, null, $t, $claimed, $data)]);
}

// Walk causeId within one propagation of one stream: → "t1,t2,…".
function thread(array $entries, ?string $label, int $propagation, int $fromId): string
{
    $byId = [];
    foreach ($entries as $e) {
        if ($e['propagation'] === $propagation && ($label === null || ($e['streamLabel'] ?? null) === $label)) {
            $byId[$e['eventId']] = $e;
        }
    }
    $out = [];
    $cur = $byId[$fromId] ?? null;
    while ($cur !== null) {
        array_unshift($out, $cur['type']);
        $cur = $cur['causeId'] !== null ? ($byId[$cur['causeId']] ?? null) : null;
    }
    return implode(',', $out);
}

/** → the rejection emit threw, or null when it settled */
function emitCatch(object $s, mixed $entry): ?\Throwable
{
    try {
        $s->emit($entry);
        return null;
    } catch (\Throwable $t) {
        return $t;
    }
}

// ── Levels ─────────────────────────────────────

H::test('StreamLog: OFF records nothing', function (): void {
    $log = new StreamLog('OFF');
    $log->observer('s')(one('x', true));
    H::assertEqual($log->sample()['count'], 0);
});

H::test('StreamLog: default level is EVENTS', function (): void {
    H::assertEqual((new StreamLog())->level(), 'EVENTS');
    H::assertEqual((new StreamLog(null))->level(), 'EVENTS', 'null means EVENTS');
});

H::test('StreamLog: EVENTS records type and claimed', function (): void {
    $log = new StreamLog('EVENTS');
    $log->observer('s')(one('foo', true, ['big' => true]));
    $e = $log->sample()['entries'];
    H::assertEqual(count($e), 1);
    H::assertEqual($e[0]['type'], 'foo');
    // 12: claimed is the seam's boolean (11.1 gave the signature, which is the type)
    H::assertEqual($e[0]['claimed'], true);
    H::assertEqual($e[0]['eventId'], 1);
    H::assert(array_key_exists('causeId', $e[0]) && $e[0]['causeId'] === null, 'causeId present and null for the entry delivery');
    H::assertEqual($e[0]['propagation'], 1);
    H::assertEqual($e[0]['outcome'], 'settled');
    H::assert(!array_key_exists('streamLabel', $e[0]), 'no streamLabel at EVENTS');
    H::assert(!array_key_exists('data', $e[0]), 'no data at EVENTS');
});

H::test('StreamLog: EVENTS records claimed false for residue', function (): void {
    // 11.1: claimed null; 12: false
    $log = new StreamLog('EVENTS');
    $log->observer('s')(one('orphan', false));
    H::assertEqual($log->sample()['entries'][0]['claimed'], false);
});

H::test('StreamLog: DEEP includes streamLabel and parentLabel', function (): void {
    // 12: labels chosen by whoever attaches, in place of 11.1's numeric stream ids
    $log = new StreamLog('DEEP');
    $log->observer('parent');
    $log->observer('child', 'parent')(one('x', true));
    $e = $log->sample()['entries'][0];
    H::assertEqual($e['streamLabel'], 'child');
    H::assertEqual($e['parentLabel'], 'parent');
    H::assert(!array_key_exists('data', $e), 'no data at DEEP');
});

H::test('StreamLog: DEEP omits parentLabel when there is none', function (): void {
    $log = new StreamLog('DEEP');
    $log->observer('s')(one('x', false));
    $e = $log->sample()['entries'][0];
    H::assertEqual($e['streamLabel'], 's');
    H::assert(!array_key_exists('parentLabel', $e), 'no parentLabel key');
});

H::test('StreamLog: DATA includes everything', function (): void {
    $log = new StreamLog('DATA');
    $log->observer('p');
    $log->observer('c', 'p')(one('y', true, ['val' => 42]));
    $e = $log->sample()['entries'][0];
    H::assertEqual($e['streamLabel'], 'c');
    H::assertEqual($e['parentLabel'], 'p');
    H::assertEqual($e['data']['val'], 42);
});

// ── Unknown levels (X2) ────────────────────────

H::test('StreamLog: an unknown level is refused at construction (X2)', function (): void {
    // 11.1: an unknown level silently acted as OFF
    H::assertThrows(static fn () => new StreamLog('VERBOSE'), "unknown level 'VERBOSE'");
    H::assertThrows(static fn () => new StreamLog('events'), 'unknown level');
    H::assertThrows(static fn () => new StreamLog(3), 'unknown level');
});

H::test('StreamLog: an unknown level is refused when set, and the level is kept (X2)', function (): void {
    $log = new StreamLog('DEEP');
    H::assertThrows(static fn () => $log->setLevel('LOUD'), "unknown level 'LOUD'");
    H::assertThrows(static fn () => $log->setLevel(null), 'unknown level');
    H::assertEqual($log->level(), 'DEEP');
    $log->observer('s')(one('x', true));
    H::assertEqual($log->sample()['entries'][0]['streamLabel'], 's', 'still recording at DEEP');
});

// ── Sequencing ─────────────────────────────────

H::test('StreamLog: seq increments', function (): void {
    $log = new StreamLog('EVENTS');
    $obs = $log->observer('s');
    $obs(batch([rec(1, null, 'a', true), rec(2, 1, 'b', false)]));
    $obs(one('c', false));
    $e = $log->sample()['entries'];
    H::assertEqual($e[0]['seq'], 0);
    H::assertEqual($e[1]['seq'], 1);
    H::assertEqual($e[2]['seq'], 2);
});

H::test('StreamLog: entries have timestamps', function (): void {
    // 12: seconds (11.1: Date.now() milliseconds); PHP: time()
    $log = new StreamLog('EVENTS');
    $before = time();
    $log->observer('s')(one('x', false));
    $after = time();
    $t = $log->sample()['entries'][0]['time'];
    H::assert(is_int($t) && $t >= $before && $t <= $after, 'time within the call');
});

// ── Labels (12, in place of nextStreamId) ──────

H::test('StreamLog: labels are per log and unique within it', function (): void {
    $a = new StreamLog();
    $b = new StreamLog();
    $a->observer('s');
    $b->observer('s'); // no static counter or registry: another log is unaffected
    H::assertEqual(CoreApi::staticStateOf(StreamLog::class), [], 'StreamLog holds no static state');
    H::assertThrows(static fn () => $a->observer('s'), 'already used');
    H::assertThrows(static fn () => $a->observer(''), 'non-empty string');
    H::assertThrows(static fn () => $a->observer(7), 'non-empty string');
    H::assertThrows(static fn () => $a->observer('t', 'nope'), 'not a label of this log');
    H::assertThrows(static fn () => $b->observer('t', 'x'), 'not a label of this log');
});

H::test('StreamLog: propagations are numbered per observed stream', function (): void {
    $log = new StreamLog('DEEP');
    $p = $log->observer('p');
    $c = $log->observer('c', 'p');
    $p(one('a', false));
    $c(one('b', false));
    $p(one('d', false));
    $e = $log->sample()['entries'];
    H::assertEqual($e[0]['propagation'], 1);
    H::assertEqual($e[1]['propagation'], 1);
    H::assertEqual($e[2]['propagation'], 2);
});

H::test('StreamLog: propagations at OFF are still counted', function (): void {
    $log = new StreamLog('OFF');
    $obs = $log->observer('s');
    $obs(one('a', false));
    $log->setLevel('EVENTS');
    $obs(one('b', false));
    $e = $log->sample()['entries'];
    H::assertEqual(count($e), 1);
    H::assertEqual($e[0]['propagation'], 2);
});

// ── sample and clear ───────────────────────────

H::test('StreamLog: sample returns copy', function (): void {
    $log = new StreamLog('EVENTS');
    $log->observer('s')(one('x', false));
    $s = $log->sample();
    array_pop($s['entries']);
    H::assertEqual($log->sample()['count'], 1);
    $s2 = $log->sample();
    $s2['entries'][0]['type'] = 'changed';
    H::assertEqual($log->sample()['entries'][0]['type'], 'x', 'an entry write does not reach the log');
    // PHP: a write through a reference into a sample reaches nothing either
    $s3 = $log->sample();
    $ref = &$s3['entries'][0];
    $ref['type'] = 'by reference';
    unset($ref);
    H::assertEqual($log->sample()['entries'][0]['type'], 'x', 'a by-reference write does not reach the log');
    H::assertEqual($s2['level'], 'EVENTS');
});

H::test('StreamLog: clear resets entries and seq', function (): void {
    $log = new StreamLog('EVENTS');
    $obs = $log->observer('s');
    $obs(batch([rec(1, null, 'x', true), rec(2, 1, 'y', false)]));
    $log->clear();
    H::assertEqual($log->sample()['count'], 0);
    $obs(one('z', false));
    $e = $log->sample()['entries'][0];
    H::assertEqual($e['seq'], 0, 'seq resets');
    H::assertEqual($e['propagation'], 2, 'propagation numbers are kept');
});

// ── Runtime level change ───────────────────────

H::test('StreamLog: level change takes effect immediately', function (): void {
    $log = new StreamLog('OFF');
    $obs = $log->observer('s');
    $obs(one('a', false));
    H::assertEqual($log->sample()['count'], 0);
    $log->setLevel('EVENTS');
    $obs(one('b', false));
    H::assertEqual($log->sample()['count'], 1);
    $log->setLevel('OFF');
    $obs(one('c', false));
    H::assertEqual($log->sample()['count'], 1);
});

H::test('StreamLog: upgrade level mid-run adds detail', function (): void {
    $log = new StreamLog('EVENTS');
    $obs = $log->observer('s');
    $obs(one('a', false, ['x' => 1]));
    $log->setLevel('DATA');
    $obs(one('b', false, ['x' => 2]));
    $e = $log->sample()['entries'];
    H::assert(!array_key_exists('data', $e[0]), 'first entry has no data');
    H::assertEqual($e[1]['data']['x'], 2, 'second entry has data');
});

// ── Integration with a stream (contract fake core) ───

H::test("StreamLog: what it is shown passes the seam's batch check", function () use ($Event, $Gate, $Stream): void {
    $s = new $Stream();
    $log = new StreamLog('DATA');
    $obs = $log->observer('s');
    $shown = null;
    $s->attach(static function (array $b) use (&$shown): void {
        $shown = $b;
    });
    $s->attach($obs);
    $s->register(new $Gate('root', static fn () => [new $Event('a'), new $Event('b')]));
    $s->emit(new $Event('root', ['k' => 1]));
    H::assertEqual(ObserverSeam::batchProblems($shown), []);
    $e = $log->sample()['entries'];
    H::assertEqual(count($e), 3);
    H::assertEqual($e[0]['data']['k'], 1);
});

H::test('StreamLog: Stream.emit records to log', function () use ($Event, $Gate, $Stream): void {
    $log = new StreamLog('EVENTS');
    $s = new $Stream();
    $s->attach($log->observer('s'));
    $s->register(new $Gate('sink', static fn () => null));
    $s->emit(new $Event('sink', []));
    $s->emit(new $Event('orphan', []));
    $e = $log->sample()['entries'];
    H::assertEqual(count($e), 2);
    H::assertEqual($e[0]['claimed'], true);
    H::assertEqual($e[1]['claimed'], false);
});

H::test('StreamLog: shared log across parent and sub-stream', function () use ($Event, $Stream): void {
    $log = new StreamLog('DEEP');
    $parent = new $Stream();
    $child = new $Stream();
    $parent->attach($log->observer('parent'));
    $child->attach($log->observer('child', 'parent'));
    $parent->emit(new $Event('parent_event', []));
    $child->emit(new $Event('child_event', []));
    $e = $log->sample()['entries'];
    H::assertEqual(count($e), 2);
    H::assertEqual($e[0]['streamLabel'], 'parent');
    H::assert(!array_key_exists('parentLabel', $e[0]), 'the parent has no parentLabel');
    H::assertEqual($e[1]['streamLabel'], 'child');
    H::assertEqual($e[1]['parentLabel'], 'parent');
    // 12: both are delivery 1 of propagation 1 of their own stream; the label disambiguates
    H::assertEqual($e[0]['eventId'], $e[1]['eventId']);
});

H::test('StreamLog: returned events are recorded via log', function () use ($Event, $Gate, $Stream): void {
    $log = new StreamLog('EVENTS');
    $s = new $Stream();
    $s->attach($log->observer('s'));
    $s->register(new $Gate('forward', static fn ($ev) => new $Event('forwarded', ['from' => $ev->data['origin']])));
    $s->emit(new $Event('forward', ['origin' => 'test']));
    $e = $log->sample()['entries'];
    H::assertEqual(count($e), 2);
    H::assertEqual($e[0]['type'], 'forward');
    H::assertEqual($e[0]['claimed'], true);
    H::assertEqual($e[1]['type'], 'forwarded');
    H::assertEqual($e[1]['claimed'], false);
});

// ── Causal threads ─────────────────────────────

H::test('StreamLog: chain entries carry eventId and causeId', function () use ($Event, $Gate, $Stream): void {
    $log = new StreamLog('EVENTS');
    $s = new $Stream();
    $s->attach($log->observer('s'));
    $s->register(new $Gate('x', static fn () => new $Event('y')));
    $s->register(new $Gate('y', static fn () => new $Event('z')));
    $s->emit(new $Event('x', []));
    $e = $log->sample()['entries'];
    H::assertEqual(count($e), 3);
    H::assertEqual($e[0]['eventId'], 1);
    H::assertEqual($e[0]['causeId'], null, 'external emit has no cause');
    H::assertEqual($e[1]['eventId'], 2);
    H::assertEqual($e[1]['causeId'], 1, 'y caused by x');
    H::assertEqual($e[2]['eventId'], 3);
    H::assertEqual($e[2]['causeId'], 2, 'z caused by y');
});

H::test('StreamLog: fan-out siblings share a cause', function () use ($Event, $Gate, $Stream): void {
    $log = new StreamLog('EVENTS');
    $s = new $Stream();
    $s->attach($log->observer('s'));
    $s->register(new $Gate('root', static fn () => [new $Event('left'), new $Event('right')]));
    $s->emit(new $Event('root', []));
    $e = $log->sample()['entries'];
    H::assertEqual(count($e), 3);
    H::assertEqual($e[1]['causeId'], $e[0]['eventId'], 'left caused by root');
    H::assertEqual($e[2]['causeId'], $e[0]['eventId'], 'right caused by root');
});

H::test('StreamLog: separate external emits are separate roots', function () use ($Event, $Stream): void {
    $log = new StreamLog('EVENTS');
    $s = new $Stream();
    $s->attach($log->observer('s'));
    $s->emit(new $Event('a', []));
    $s->emit(new $Event('b', []));
    $e = $log->sample()['entries'];
    H::assertEqual($e[0]['causeId'], null);
    H::assertEqual($e[1]['causeId'], null);
    // 12: ids restart in every propagation (11.1: "ids continue across emits");
    // the log's propagation number tells the two apart
    H::assertEqual($e[0]['eventId'], 1);
    H::assertEqual($e[1]['eventId'], 1);
    H::assertEqual($e[0]['propagation'], 1);
    H::assertEqual($e[1]['propagation'], 2);
});

H::test('StreamLog: causal chain reconstructible from a mixed tree', function () use ($Event, $Gate, $Stream): void {
    // root fans out to two chains; entries interleave by generation, but
    // walking causeId untangles each thread: order means time, causeId cause.
    $log = new StreamLog('DEEP');
    $s = new $Stream();
    $s->attach($log->observer('s'));
    $mk = static fn (string $sig, array $outs): object => new $Gate($sig, static fn () => array_map(static fn (string $t): object => new $Event($t), $outs));
    $s->register($mk('root', ['a', 'b']));
    $s->register($mk('a', ['a1']));
    $s->register($mk('b', ['b1']));
    $s->register($mk('a1', ['a2']));
    $s->emit(new $Event('x')); // 12: an earlier propagation reuses the same ids; the walk must not cross it
    $s->emit(new $Event('root', []));
    $entries = $log->sample()['entries'];
    $a2 = null;
    foreach ($entries as $e) {
        if ($e['type'] === 'a2') {
            $a2 = $e;
        }
    }
    H::assert($a2 !== null, 'a2 was recorded');
    H::assertEqual(thread($entries, 's', $a2['propagation'], $a2['eventId']), 'root,a,a1,a2');
});

// ── Acyclicity diagnostics ─────────────────────

H::test('StreamLog: entries carry no retries field', function () use ($Event, $Stream): void {
    $log = new StreamLog('DATA');
    $s = new $Stream();
    $s->attach($log->observer('s'));
    $s->emit(new $Event('plain', []));
    $e = $log->sample()['entries'];
    H::assertEqual(count($e), 1);
    H::assert(!array_key_exists('retries', $e[0]), 'no retries in core 12');
});

H::test('StreamLog: a cycle is readable from the causal chain', function () use ($Event, $Gate, $Stream): void {
    // The rejection cannot name the loop by itself (K5); an attached log can,
    // by walking causeId from the delivery the rejection names.
    $log = new StreamLog('EVENTS');
    $s = new $Stream();
    $s->attach($log->observer('s'));
    $s->register(new $Gate('a', static fn () => new $Event('b')));
    $s->register(new $Gate('b', static fn () => new $Event('a')));
    $err = emitCatch($s, new $Event('a', []));
    H::assert($err !== null, 'the cycle rejects');
    H::assertEqual($err->code, 'SISO_CYCLE');
    $entries = $log->sample()['entries'];
    $last = $entries[count($entries) - 1];
    H::assertEqual($last['eventId'], $err->eventId, 'the records end with the delivery the rejection names');
    H::assertEqual($last['outcome'], 'rejected');
    H::assertEqual(thread($entries, null, $last['propagation'], $err->eventId), 'a,b,a');
});

H::test('StreamLog: self-loop rejection points at the producing entry', function () use ($Event, $Gate, $Stream): void {
    $log = new StreamLog('EVENTS');
    $s = new $Stream();
    $s->attach($log->observer('s'));
    $s->register(new $Gate('loop', static fn () => new $Event('loop')));
    $err = emitCatch($s, new $Event('loop', []));
    H::assert($err !== null, 'the self-loop rejects');
    H::assertEqual($err->code, 'SISO_SELF_LOOP');
    $e = $log->sample()['entries'];
    H::assertEqual(count($e), 1, 'the looping return is never delivered');
    H::assertEqual($e[0]['eventId'], $err->eventId);
    H::assertEqual($e[0]['claimed'], true);
    H::assertEqual($e[0]['outcome'], 'rejected');
});

H::test('StreamLog: gate-threw rejection points at the claiming entry', function () use ($Event, $Gate, $Stream): void {
    $log = new StreamLog('EVENTS');
    $s = new $Stream();
    $s->attach($log->observer('s'));
    $s->register(new $Gate('a', static fn () => new $Event('b')));
    $s->register(new $Gate('b', static function (): never {
        throw new \RuntimeException('boom');
    }));
    $err = emitCatch($s, new $Event('a', []));
    H::assert($err !== null, 'the throw rejects');
    H::assertEqual($err->code, 'SISO_GATE_THREW');
    $found = null;
    foreach ($log->sample()['entries'] as $x) {
        if ($x['eventId'] === $err->eventId) {
            $found = $x;
        }
    }
    H::assert($found !== null, 'the rejection names a logged delivery');
    H::assertEqual($found['type'], 'b');
    H::assertEqual($found['claimed'], true);
    H::assertEqual($found['causeId'], $err->causeId);
});

H::test('StreamLog: a refused entry is a propagation with no entries', function () use ($Event, $Stream): void {
    $log = new StreamLog('EVENTS');
    $s = new $Stream();
    $obs = $log->observer('s');
    $s->attach($obs);
    emitCatch($s, 'not an event');
    H::assertEqual($log->sample()['count'], 0);
    $s->emit(new $Event('ok'));
    H::assertEqual($log->sample()['entries'][0]['propagation'], 2);
});

H::test('StreamLog: log presence does not change acyclicity outcome', function () use ($Event, $Gate, $Stream): void {
    $run = static function (bool $withLog) use ($Event, $Gate, $Stream): string {
        $s = new $Stream();
        if ($withLog) {
            $s->attach((new StreamLog('DATA'))->observer('s'));
        }
        $s->register(new $Gate('a', static fn () => new $Event('b')));
        $s->register(new $Gate('b', static fn () => new $Event('a')));
        $e = emitCatch($s, new $Event('a', []));
        if ($e === null) {
            return 'none';
        }
        return $e->code . '@' . CoreApi::show($e->eventId) . '/' . CoreApi::show($e->depth);
    };
    H::assertEqual($run(true), $run(false));
});

H::test('StreamLog: log absence changes nothing', function () use ($Event, $Gate, $Stream): void {
    // 12: sampleHere carries no eventCount (core-api), so compare residue only
    $run = static function (bool $withLog) use ($Event, $Gate, $Stream): string {
        $s = new $Stream();
        if ($withLog) {
            $s->attach((new StreamLog('EVENTS'))->observer('s'));
        }
        $s->register(new $Gate('x', static fn () => new $Event('y')));
        $s->emit(new $Event('x', []));
        $r = $s->sampleHere()['residue'];
        return count($r) . ':' . $r[0]->type;
    };
    H::assertEqual($run(true), $run(false));
});

H::test('StreamLog: detaching stops recording; re-attaching continues numbering', function () use ($Event, $Stream): void {
    $log = new StreamLog('EVENTS');
    $s = new $Stream();
    $obs = $log->observer('s');
    $s->attach($obs);
    $s->emit(new $Event('a'));
    $s->detach($obs);
    $s->emit(new $Event('b'));
    H::assertEqual($log->sample()['count'], 1);
    $s->attach($obs);
    $s->emit(new $Event('c'));
    $e = $log->sample()['entries'];
    H::assertEqual(count($e), 2);
    H::assertEqual($e[1]['propagation'], 2);
});

H::finish('streamlog');
