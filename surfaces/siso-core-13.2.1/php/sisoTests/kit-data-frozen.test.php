<?php
/*
kit-data-frozen.test.php — php/code/kit/data.php's Data::encode reads
read-only event data (core-api READ-ONLY) as the plain data it was built
from (kit-api), so encode($event->data) === encode($data), and residue,
samples and observer records from the real Siso\Core\Stream serialise as
handed out. Port of lua/sisoTests/kit-data-frozen.test.lua, case by case.
  php php/sisoTests/kit-data-frozen.test.php   (from the project root)
Expected values: the encoding of the same data built plainly (the plain
encoder is pinned by php/sisoTests/kit-data.test.php), and for the fixed
samples a literal canonical text written by hand.

Turned into PHP's own counterpart (kit-api SUBSTRATE: Lua's `freeze`
option becomes the optional 'Event' class; core-api: an event is
read-only by the language from construction, and its data is an array, a
value):
  - freeze(v) is `(new Event('t', ['v' => v]))->data['v']`: the value as
    read back out of read-only event data (Event data must be an array, so
    the value under test sits under one key and is read back out).
  - "kit-api check passes with freeze given": the check with 'Event' =>
    Siso\Core\Event.
  - "freezing really happened": writes to the event's data are refused,
    a list and Json::EmptyArray read back as arrays, an empty array reads
    back as [] (not an array), PHP null passes through, foreach sees the
    contents.
  - "null … keeps its identity": PHP null is a value: it reads back null.
  - "a frozen table inside plain data": event data placed inside plain
    data.
  - "rejection: a hole in a frozen unmarked list", "mixed keys", "a marked
    array with a hole or a named key": PHP has none of these as refusals
    (kit-api: a non-list is an object with string keys; Data::array
    refuses a non-list); they become "an array that is not a list encodes
    alike, plain and in an event, as the same object".
  - "rejection: a non-string, non-number key": PHP keys are int|string:
    becomes int keys encode alike as string keys, plain and in an event.
  - "rejection: a foreign metatable is still refused (plain)": an object
    is refused plainly (Event refuses one in its data at construction,
    core-api); an Event and a foreign enum case, which Event keeps in its
    data, are refused alike, plain and from event data.
  - "records' data from an observer batch": a record's data is the
    observer's own array (a value, observer-seam), so Lua's "record data
    is not frozen" becomes "a write to the observer's copy reaches neither
    the stream nor a later encoding".
No case is inapplicable.
*/
declare(strict_types=1);

namespace Siso\Tests\KitDataFrozen;

require_once __DIR__ . '/../code/harness.php';
require_once __DIR__ . '/../code/contracts/kit_api.php';
require_once __DIR__ . '/../code/contracts/core_api.php';
require_once __DIR__ . '/../code/kit/data.php';
require_once __DIR__ . '/../code/core/event.php';
require_once __DIR__ . '/../code/core/gate.php';
require_once __DIR__ . '/../code/core/stream.php';

use Siso\Contracts\CoreApi as API;
use Siso\Contracts\KitApi as K;
use Siso\Core\Event;
use Siso\Core\Gate;
use Siso\Core\Stream;
use Siso\Harness\H;
use Siso\Kit\Data;
use Siso\Kit\Json;

enum Other
{
    case Case;
}

function enc(mixed $v): string
{
    $why = null;
    $s = Data::encode($v, $why);
    if ($s === null) {
        H::fail('encode failed: ' . (string)$why);
    }
    return $s;
}

function encErr(mixed $v, string $fragment): void
{
    $why = null;
    $s = Data::encode($v, $why);
    H::assertEqual($s, null, 'encode should fail');
    H::assert(is_string($why) && str_contains($why, $fragment), 'error ' . H::show($why) . " lacks '" . $fragment . "'");
}

/** Lua's freeze(v): v read back out of read-only event data */
function freeze(mixed $v): mixed
{
    return (new Event('t', ['v' => $v]))->data['v'];
}

/** encode(freeze(build())) === encode(build()), and both equal $want when given */
function same(callable $build, ?string $want = null): void
{
    $plain = enc($build());
    $frozen = enc(freeze($build()));
    H::assertEqual($frozen, $plain, 'frozen vs plain');
    if ($want !== null) {
        H::assertEqual($plain, $want, 'plain text');
    }
}

/** build() is refused plainly and from event data, with the same message */
function refusedAlike(callable $build, string $fragment): void
{
    encErr($build(), $fragment);
    $plainWhy = null;
    $frozenWhy = null;
    Data::encode($build(), $plainWhy);
    Data::encode(freeze($build()), $frozenWhy);
    H::assertEqual($frozenWhy, $plainWhy, 'frozen refusal vs plain refusal');
}

function nest(int $n): array
{
    $v = [];
    for ($i = 0; $i < $n; $i++) {
        $v = [$v];
    }
    return $v;
}

// ── the contract ──────────────────────────────

H::test('kit-api check passes with Event given (event data reads as plain)', function (): void {
    $p = K::check(['Data' => Data::class, 'Json' => Json::class, 'Event' => Event::class]);
    H::assert($p === [], implode('; ', $p));
});

H::test('read-only really happened: writes refused, arrays still arrays', function (): void {
    $e = new Event('t', ['a' => Data::array([1]), 'o' => [], 'e' => Data::array([]), 'n' => null]);
    H::assert(!API::attempt(static function () use ($e): void {
        $e->data['x'] = 1;
    }), 'event data accepted a write');
    H::assert(!API::attempt(static function () use ($e): void {
        $e->data['a'][0] = 2;
    }), 'nested event data accepted a write');
    H::assert(Data::isArray($e->data['a']), 'a list in event data lost its arrayness');
    H::assert(!Data::isArray($e->data['o']), 'an empty array in event data is an array');
    H::assert($e->data['e'] === Json::EmptyArray, 'Json::EmptyArray did not pass through');
    H::assert(array_key_exists('n', $e->data) && $e->data['n'] === null, 'null did not pass through');
    $seen = [];
    foreach ($e->data as $k => $_) {
        $seen[] = $k;
    }
    H::assertEqual($seen, ['a', 'o', 'e', 'n'], 'foreach sees the contents');
});

// ── encode(freeze(v)) === encode(v) ───────────

H::test('nested objects', function (): void {
    same(static fn () => ['z' => ['y' => ['x' => ['w' => 'deep']]], 'a' => ['b' => 1, 'c' => ['d' => true]]],
        '{"a":{"b":1,"c":{"d":true}},"z":{"y":{"x":{"w":"deep"}}}}');
});

H::test('Data::array arrays, nested and of objects', function (): void {
    same(static fn () => Data::array([Data::array([1, 2]), ['k' => 'v'], Data::array([Data::array([])])]),
        '[[1,2],{"k":"v"},[[]]]');
});

H::test('plain lists (keys exactly 0..n-1)', function (): void {
    same(static fn () => ['l' => [3, 2, 1], 'm' => [[1], ['a', 'b']]],
        '{"l":[3,2,1],"m":[[1],["a","b"]]}');
});

H::test('Json::EmptyArray, and an empty array is an object', function (): void {
    same(static fn () => Data::array([]), '[]');
    same(static fn () => ['e' => Data::array([]), 'o' => []], '{"e":[],"o":{}}');
    same(static fn () => [], '{}');
});

H::test('floats kept apart from integers', function (): void {
    same(static fn () => Data::array([1, 1.0, -0.0, 0.1, 1e21, 5e-324, 2.0 ** 53]),
        '[1,1.0,-0.0,0.1,1e+21,5e-324,9007199254740992.0]');
});

H::test('null, in arrays and objects, reads back as null', function (): void {
    same(static fn () => ['n' => null, 'l' => Data::array([null, 1, null])], '{"l":[null,1,null],"n":null}');
    H::assert(freeze(['n' => null]) === ['n' => null], "event data's null is not null");
});

H::test('strings, escapes and UTF-8', function (): void {
    same(static fn () => ['s' => "a\"b\\c\n\x00", 'u' => 'ääkköset ✓', 'ü' => ''],
        '{"s":"a\\"b\\\\c\\n\\u0000","u":"ääkköset ✓","ü":""}');
});

H::test('booleans and integers at the edges', function (): void {
    same(static fn () => Data::array([true, false, PHP_INT_MAX, PHP_INT_MIN, 0]),
        '[true,false,9223372036854775807,-9223372036854775808,0]');
});

H::test('event data inside plain data', function (): void {
    $inner = (new Event('t', ['k' => Data::array([1, 2.5])]))->data;
    H::assertEqual(enc(['wrap' => $inner, 'l' => Data::array([$inner])]), '{"l":[{"k":[1,2.5]}],"wrap":{"k":[1,2.5]}}');
});

// ── what is not a list encodes alike ──────────

H::test('an array with a hole encodes alike, plain and from event data', function (): void {
    same(static fn () => [0 => 1, 2 => 3], '{"0":1,"2":3}');
});

H::test('mixed keys encode alike, plain and from event data', function (): void {
    same(static fn () => [1, 2, 'x' => 3], '{"0":1,"1":2,"x":3}');
});

H::test('a list given a named key is no longer an array, alike plain and from event data', function (): void {
    same(static function (): array {
        $t = Data::array([1]);
        $t['x'] = 2;
        return $t;
    }, '{"0":1,"x":2}');
    same(static function (): array {
        $h = [];
        $h[1] = 1;
        $h[3] = 3;
        return $h;
    }, '{"1":1,"3":3}');
});

H::test('int keys encode as their strings, plain and from event data', function (): void {
    same(static fn () => [7 => 'a', 'b' => 2], '{"7":"a","b":2}');
});

// ── refusals still refuse in event data ───────

H::test('rejection: NaN and invalid UTF-8 inside event data', function (): void {
    refusedAlike(static fn () => ['x' => NAN], 'NaN is not allowed');
    refusedAlike(static fn () => ['x' => "\xff"], 'not valid UTF-8');
    refusedAlike(static fn () => ['x' => Data::array([1, INF])], 'Infinity is not allowed');
    refusedAlike(static fn () => ["\xff" => 1], 'not valid UTF-8');
});

H::test('rejection: an object is refused plainly; an Event and a foreign enum case alike in event data', function (): void {
    encErr(new \stdClass(), 'a stdClass is not data');
    H::assertThrows(static fn () => new Event('t', ['o' => new \stdClass()]), 'not plain data', 'Event refuses an object itself');
    refusedAlike(static fn () => ['e' => new Event('inner')], 'an Event is not data');
    refusedAlike(static fn () => ['c' => Other::Case], 'enum case');
});

H::test('rejection: nesting limit holds on event data', function (): void {
    same(static fn () => nest(511));
    refusedAlike(static fn () => nest(512), 'nesting too deep');
});

// ── real stream: residue, samples, observer records ──

/** what the gate returns, built plainly each time it is called */
function payload(mixed $v): array
{
    return [
        'v' => $v,
        'f' => 2.5,
        'whole' => 3.0,
        'n' => null,
        's' => "ä\t",
        'list' => [1, 2, 3],
        'marked' => Data::array([['k' => 'x'], Data::array([])]),
        'empty' => Data::array([]),
        'obj' => ['deep' => ['deeper' => Data::array([true, false])]],
    ];
}

function entryData(): array
{
    return ['v' => 7, 'tags' => Data::array(['a', 'b']), 'meta' => ['at' => 1.5]];
}

function newStream(): Stream
{
    $s = new Stream();
    $s->register(new Gate('in', static fn (Event $e) => [
        new Event('out', payload($e->data['v'])),
        new Event('plain', ['only' => $e->data['tags']]),
    ]));
    return $s;
}

/** plain encodings to compare with */
function plainEvents(): array
{
    return [
        'out' => enc(payload(7)),
        'plain' => enc(['only' => Data::array(['a', 'b'])]),
        'in' => enc(entryData()),
    ];
}

function byType(array $events): array
{
    $got = [];
    foreach ($events as $e) {
        $got[$e->type] = enc($e->data);
    }
    return $got;
}

H::test('residue from takeResidue encodes as the plain data', function (): void {
    $s = newStream();
    $s->emit(new Event('in', entryData()));
    $taken = $s->takeResidue();
    H::assertEqual(count($taken), 2, 'residue size');
    H::assert(!API::attempt(static function () use ($taken): void {
        $taken[0]->data['v'] = 0;
    }), 'taken residue is not read-only');
    $want = plainEvents();
    $got = byType($taken);
    H::assertEqual($got['out'] ?? null, $want['out'], 'out');
    H::assertEqual($got['plain'] ?? null, $want['plain'], 'plain');
});

H::test('events from sampleHere encode as the plain data', function (): void {
    $s = newStream();
    $s->emit(new Event('in', entryData()));
    $residue = $s->sampleHere()['residue'];
    H::assert(!API::attempt(static function () use ($residue): void {
        $residue[0]->data['v'] = 0;
    }), 'sampled residue is not read-only');
    $want = plainEvents();
    $got = byType($residue);
    H::assertEqual($got['out'] ?? null, $want['out'], 'out');
    H::assertEqual($got['plain'] ?? null, $want['plain'], 'plain');
    // a whole residue list of ['type', 'data'] encodes as built plainly
    $list = [];
    foreach ($residue as $e) {
        $list[] = ['type' => $e->type, 'data' => $e->data];
    }
    usort($list, static fn (array $a, array $b): int => strcmp($a['type'], $b['type']));
    $plainList = [];
    foreach (['out', 'plain'] as $t) {
        $plainList[] = ['type' => $t, 'data' => $t === 'out' ? payload(7) : ['only' => Data::array(['a', 'b'])]];
    }
    H::assertEqual(enc(Data::array($list)), enc(Data::array($plainList)), 'residue list');
});

H::test("records' data from an observer batch encode as the plain data", function (): void {
    $s = newStream();
    $batches = [];
    $s->attach(static function ($b) use (&$batches): void {
        $batches[] = $b;
    });
    $s->emit(new Event('in', entryData()));
    H::assertEqual(count($batches), 1, 'batches');
    $records = $batches[0]['records'];
    H::assertEqual(count($records), 3, 'records');
    $want = plainEvents();
    $seen = 0;
    foreach ($records as $r) {
        H::assertEqual(enc($r['data']), $want[$r['eventType']], 'record ' . $r['eventType']);
        $seen++;
    }
    H::assertEqual($seen, 3, 'records compared');
    // the observer's own copy: a write to it reaches nothing else
    $records[0]['data']['v'] = 0;
    $got = byType($s->sampleHere()['residue']);
    H::assertEqual($got['out'] ?? null, $want['out'], "a write to the observer's copy reached the stream");
    H::assertEqual(enc($batches[0]['records'][0]['data']), $want[$batches[0]['records'][0]['eventType']], "a write to a copy reached the batch");
});

H::finish('kit-data-frozen');
