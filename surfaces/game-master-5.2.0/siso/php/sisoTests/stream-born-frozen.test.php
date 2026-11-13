<?php
/*
stream-born-frozen.test.php — an event is read-only from the moment
Event builds it (core-api EVENT / READ-ONLY, core 13). Port of
lua/sisoTests/stream-born-frozen.test.lua, case by case. Runs the
core-api read-only check on the real core, then pins what it leaves open:
the refusal, defaults, identities kept, and that the stream passes a
born-read-only event through untouched. The gates below are fixtures for
the stream, not project gates.
  php php/sisoTests/stream-born-frozen.test.php   (from the project root)

PHP holds read-only by the language (`public readonly`, arrays as
values), so Lua's "frozen" becomes: every write raises \Error, either
PHP's own "Cannot modify readonly property …" or the event's
"read-only: …" for a dynamic property (refuses() accepts both).

Turned into PHP's own attempt at the same violation (core-api SUBSTRATE):
  - freeze.isFrozen(e) / (e.data): both properties are declared readonly
    (ReflectionProperty::isReadOnly).
  - "read-only data keeps its identity": a __readonly sentinel is an enum
    case (Lua R4 vanishes); data passed on is the same value (arrays are
    values, compared with ===); a sentinel as the data itself is refused by
    the type (data is an array), PHP's own form of that attempt.
  - "marks survive and sharing is kept; a cycle terminates": an array
    reached twice is the same value; a cycle can only be built through a
    PHP reference, and Event refuses that recursive array (it terminates,
    as not plain data).
  - "R3 concession: rawset …": PHP has no rawset; its counterparts (a
    Reflection write, a closure bound into the class scope, a reference
    taken into the data) are refused: R3's rawset clause vanishes.
Inapplicable:
  - the data-mark half of "marks survive …" (a Lua metatable on a data
    table, `fx.array`): PHP arrays carry no metatable; there is nothing to
    survive.
*/
declare(strict_types=1);

namespace Siso\Tests\StreamBornFrozen;

require_once __DIR__ . '/../code/harness.php';
require_once __DIR__ . '/../code/contracts/core_api.php';
require_once __DIR__ . '/../code/core/event.php';
require_once __DIR__ . '/../code/core/gate.php';
require_once __DIR__ . '/../code/core/stream.php';

use Siso\Contracts\CoreApi as API;
use Siso\Core\Event;
use Siso\Core\Gate;
use Siso\Core\Stream;
use Siso\Harness\H;

const CORE = ['Event' => Event::class, 'Gate' => Gate::class, 'Stream' => Stream::class];

/** an immutable sentinel: an enum case */
enum Null_
{
    case Value;
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

function readonlyProps(object $o): bool
{
    foreach (['type', 'data'] as $p) {
        if (!(new \ReflectionProperty($o, $p))->isReadOnly()) {
            return false;
        }
    }
    return true;
}

// ── the contract on the real core ──────────────

H::test('core-api read-only holds on the core (from construction)', function (): void {
    $p = API::checkReadonly(CORE);
    H::assert($p === [], implode('; ', $p));
});

H::test('core-api doors still hold on the core', function (): void {
    $p = API::checkDoors(CORE);
    H::assert($p === [], implode('; ', $p));
});

// ── what Event builds ──────────────────────────

H::test('a new event refuses assignment at every depth, with the read-only refusal', function (): void {
    $e = new Event('t', ['v' => 1, 'list' => [1, 2], 'm' => ['inner' => ['x' => 5]]]);
    refuses('the type', static function () use ($e): void {
        $e->type = 'z';
    });
    refuses('the data', static function () use ($e): void {
        $e->data = [];
    });
    refuses('a new field', static function () use ($e): void {
        $e->extra = 1;
    });
    refuses('a data field', static function () use ($e): void {
        $e->data['v'] = 2;
    });
    refuses('a new data key', static function () use ($e): void {
        $e->data['added'] = 1;
    });
    refuses('a list member', static function () use ($e): void {
        $e->data['list'][0] = 0;
    });
    refuses('deep data', static function () use ($e): void {
        $e->data['m']['inner']['x'] = 0;
    });
    H::assertEqual(keysOf($e), 'data,type');
    H::assertEqual($e->type . ':' . $e->data['v'] . ':' . $e->data['list'][0] . ':' . $e->data['m']['inner']['x'], 't:1:1:5',
        'it reads back unchanged');
    H::assert(readonlyProps($e), 'the event and its data are read-only (declared readonly)');
    H::assert(Event::is($e), 'Event::is recognises it');
});

H::test('default data is an empty frozen table; extra arguments attach nothing', function (): void {
    $d = new Event('t');
    H::assertEqual(keysOf($d->data), '');
    refuses('default data', static function () use ($d): void {
        $d->data['x'] = 1;
    });
    $x = new Event('t', ['v' => 1], ['attempt' => 1], 'more');
    H::assertEqual(keysOf($x), 'data,type');
    H::assertEqual(keysOf($x->data), 'v');
});

H::test('data is taken as it stands: later writes to the passed table do not reach the event', function (): void {
    $r = 'a';
    $orig = ['v' => 1, 'list' => [1], 'm' => ['k' => 'a']];
    $orig['m']['k'] = &$r;  // PHP: a reference slot the caller keeps
    $e = new Event('t', $orig);
    $orig['v'] = 2;
    $orig['list'][0] = 9;
    $r = 'b';
    $orig['late'] = true;
    H::assertEqual($e->data['v'], 1);
    H::assertEqual($e->data['list'][0], 1);
    H::assertEqual($e->data['m']['k'], 'a', 'a write through a kept reference does not reach the event');
    H::assertEqual($e->data['late'] ?? null, null);
    $orig['v'] = 3;
    H::assertEqual($orig['v'], 3, "the caller's array stays its own (writable)");
    H::assertEqual(keysOf($orig), 'late,list,m,v', "the caller's array carries nothing new");
});

H::test('read-only data keeps its identity: frozen data, a carried event, a __readonly sentinel', function (): void {
    $null = Null_::Value;
    $a = new Event('a', ['v' => 1]);
    $passed = new Event('b', $a->data);
    H::assert($passed->data === $a->data, 'data passed on is the same value');
    $carried = new Event('c', ['inner' => $a, 'd' => $a->data, 'n' => $null, 'list' => [$null]]);
    H::assert($carried->data['inner'] === $a, 'an event in data is that event');
    H::assert($carried->data['d'] === $a->data, 'data nested is that data');
    H::assert($carried->data['n'] === $null, 'a sentinel nested keeps its identity');
    H::assert($carried->data['list'][0] === $null, 'a sentinel in a list keeps its identity');
    // PHP: data is an array, so a sentinel as the data itself is refused by the type
    H::assertThrows(static fn () => new Event('s', $null), 'must be of type ?array', 'a sentinel as the data itself');
});

H::test('marks survive and sharing is kept; a cycle terminates (not plain data)', function (): void {
    $shared = [1];
    $e = new Event('t', ['plain' => [], 'a' => $shared, 'b' => $shared]);
    H::assert($e->data['a'] === $e->data['b'], 'an array reached twice is one value');
    H::assertEqual(get_debug_type($e->data['plain']), 'array', 'plain data is an array, with nothing attached');
    // PHP: a cycle exists only through a reference; Event refuses it, and terminates
    $cyclic = ['name' => 'c'];
    $cyclic['self'] = &$cyclic;
    $err = null;
    try {
        new Event('cyc', $cyclic);
    } catch (\Throwable $t) {
        $err = $t;
    }
    H::assert($err instanceof \InvalidArgumentException, 'a recursive array is refused');
    H::assert(str_contains($err->getMessage(), "Event: data at ['self']") && str_contains($err->getMessage(), 'is not plain data (recursive array)'),
        'refused as not plain data: ' . $err->getMessage());
    unset($cyclic['self']);
});

// ── the stream with born-read-only events ──────

H::test("entry takes the stream's own copy of a born-frozen event; a returned one passes untouched", function (): void {
    // 13.0.1 (core-api OWNERSHIP): entry copies, so the gate is given the
    // stream's own event, equal in value; inside the propagation a gate's
    // return is shared with the stream and keeps its identity.
    $inner = new Event('fx_inner', ['w' => 1]);
    $entered = new Event('fx_in', ['v' => 1, 'list' => [1], 'e' => $inner]);
    $returned = null;
    $given = null;
    $s = new Stream();
    $s->register(new Gate('fx_in', static function (Event $e) use (&$given, &$returned) {
        $given = $e;
        $returned = new Event('fx_out', ['v' => $e->data['v']]);
        return $returned;
    }));
    $s->emit($entered);
    H::assert($given !== $entered, "the gate is given the stream's own copy, not the entering code's event");
    H::assert($given->data['e'] !== $inner, "the copy shares no object with the entering code's event");
    H::assertEqual($given->type . ':' . $given->data['v'] . ':' . $given->data['list'][0] . ':' . $given->data['e']->data['w'],
        'fx_in:1:1:1', 'the copy reads the same');
    H::assert(Event::is($given) && readonlyProps($given) && Event::is($given->data['e']), 'the copy is a read-only event');
    $out = $s->takeResidue()[0];
    H::assert($out === $returned, 'the residue is the very event the gate built');
});

H::test('an event a gate builds is read-only inside the gate, before it is returned', function (): void {
    $s = new Stream();
    $inGate = null;
    $s->register(new Gate('fx_build', static function () use (&$inGate) {
        $out = new Event('fx_out', ['v' => 1]);
        $inGate = API::attempt(static function () use ($out): void {
            $out->data['v'] = 2;
        });
        return $out;
    }));
    $s->emit(new Event('fx_build'));
    H::assertEqual($inGate, false, 'a write to a just-built event is refused in the gate');
    H::assertEqual($s->takeResidue()[0]->data['v'], 1);
});

H::test('a gate writing its born-frozen input rejects as a throw, and commits nothing', function (): void {
    $s = new Stream();
    $s->register(new Gate('fx_writer', static function (Event $e): void {
        $e->data['v'] = 2;
    }));
    $r = null;
    try {
        $s->emit(new Event('fx_writer', ['v' => 1]));
    } catch (\Throwable $t) {
        $r = $t;
    }
    H::assert($r !== null, 'a writing gate settled');
    $p = API::rejectionProblems($r, ['code' => 'SISO_GATE_THREW', 'eventId' => 1, 'eventType' => 'fx_writer', 'entry' => true]);
    H::assert($p === [], implode('; ', $p));
    H::assert(isRefusal($r->cause), 'the cause is the refusal: ' . $r->cause->getMessage());
    H::assertEqual(count($s->sampleHere()['residue']), 0);
});

H::test('R3 concession: rawset on a born-frozen event is not refused (pinned so a change is seen)', function (): void {
    // PHP: there is no rawset; each back door the substrate has is refused
    $e = new Event('t', ['v' => 1]);
    refuses('a Reflection write', static function () use ($e): void {
        (new \ReflectionProperty($e, 'data'))->setValue($e, ['v' => 2]);
    });
    refuses('a closure bound into the class scope', static function () use ($e): void {
        \Closure::bind(function (): void {
            $this->data = ['v' => 2];
        }, $e, Event::class)();
    });
    refuses('a reference into the data', static function () use ($e): void {
        $r = &$e->data['v'];
        $r = 2;
    });
    refuses('re-running the constructor', static function () use ($e): void {
        $e->__construct('t', ['v' => 2]);
    });
    H::assertEqual($e->data['v'], 1, 'R3 vanishes in PHP: no write reaches a born read-only event');
});

H::finish('stream-born-frozen');
