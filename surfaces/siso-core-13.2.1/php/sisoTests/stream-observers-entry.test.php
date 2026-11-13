<?php
/*
stream-observers-entry.test.php — every observer's batch is built before
any observer is called (observer-seam ISOLATION); an entry too deep to
copy is refused as SISO_NOT_EVENT, never thrown raw (core-api: nothing
else is thrown); an observer's plain rejection copy keeps what plain data
carries (THE REJECTION). Port of
lua/sisoTests/stream-observers-entry.test.lua, case by case. The gates
below are fixtures for the stream, not project gates.
  php php/sisoTests/stream-observers-entry.test.php   (from the project root)

Turned into PHP's own attempt at the same violation:
  - the taker's `rawset` on the residue it took becomes a Reflection
    write and a \WeakMap stamp (core-api SUBSTRATE).
  - "a batch that cannot be built": Lua poisons `__pairs` on the cause;
    PHP reads a cause's public properties without running its code, so
    the poison is an Event forged by Reflection (never initialised) in a
    public field of the cause: copying it raises, inside that observer's
    protected call.
  - "an entry Event accepts but the stream cannot copy": the PHP copy is
    iterative (core-api SUBSTRATE: Depth), so on this substrate no depth
    Event accepts overflows it; the case probes the same depths and pins
    that whatever happens is a settled entry or a SISO_NOT_EVENT
    rejection with no delivery, never a raw throw (it prints when no
    depth was refused, as the Lua case does).
  - "keeps string marks: an array in a cause stays an array": PHP arrays
    carry no mark; the case pins that arrays in a cause (empty or not)
    stay arrays equal in value and that an immutable sentinel (an enum
    case) keeps its identity.
  - "hands out no table through getmetatable, at any depth": the copy
    holds no object at any depth (an object in the cause, nested in
    another, is copied as an array of its public fields).
Inapplicable:
  - the kit half of "keeps string marks" (kit.data.array, data.is_array,
    data.null, data.encode): the kit is another boundary (php-kit), not a
    contract at this one's edges; its sentinel is an enum case like any
    other, which the case covers.
*/
declare(strict_types=1);

namespace Siso\Tests\StreamObserversEntry;

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

enum Sentinel
{
    case Nothing;
}

/** what the probe gates throw: public fields of its own */
final class Thrown extends \RuntimeException
{
    public mixed $rows = null;
    public mixed $list = null;
    public mixed $n = null;
    public mixed $inner = null;
    protected string $kind = 'io';
}

/** an object with a protected field, nested in what a gate throws */
final class Inner
{
    public array $a = [1];
    protected string $kind = 'io';
}

function noProblems(array $list, ?string $label = null): void
{
    H::assert($list === [], ($label !== null ? $label . ': ' : '') . implode('; ', $list));
}

function deepArray(int $depth): array
{
    $t = [];
    for ($i = 0; $i < $depth; $i++) {
        $t = ['x' => $t];
    }
    return $t;
}

/** the first object found in $v at any depth (arrays walked), else null */
function anObject(mixed $v): ?object
{
    $stack = [$v];
    while ($stack !== []) {
        $x = array_pop($stack);
        if (is_object($x)) {
            return $x;
        }
        if (is_array($x)) {
            foreach ($x as $y) {
                $stack[] = $y;
            }
        }
    }
    return null;
}

// ── the contracts on the real core ──────────────

H::test('core-api: ownership holds on the core (entry copy, deep entries)', function (): void {
    noProblems(API::checkOwnership(CORE));
});

H::test('observer-seam: ownership and seam hold on the core', function (): void {
    noProblems(SEAM::checkOwnership(CORE), 'ownership');
    noProblems(SEAM::checkSeam(CORE), 'seam');
});

// ── batches built before any observer is called ──

H::test('an observer that takes the residue and writes to it reaches no other observer (both orders)', function (): void {
    foreach ([true, false] as $takerFirst) {
        $s = new Stream();
        $s->register(new Gate('a', static fn (Event $e) => new Event('b', ['v' => $e->data['v'], 'list' => [1], 'e' => new Event('in', ['w' => 1])])));
        $seen = null;
        $stamps = new \WeakMap();
        $taker = static function () use (&$s, $stamps): void {
            foreach ($s->takeResidue() as $e) {
                API::reflectWrite($e, 'data', ['v' => 'TAKER WROTE', 'list' => ['TAKER WROTE']]);
                $stamps[$e] = 'TAKER WROTE';
                $stamps[$e->data['e']] = 'TAKER WROTE';
                API::reflectWrite($e->data['e'], 'data', ['w' => 'TAKER WROTE']);
            }
        };
        $reader = static function (array $b) use (&$seen): void {
            $seen = $b;
        };
        if ($takerFirst) {
            $s->attach($taker);
            $s->attach($reader);
        } else {
            $s->attach($reader);
            $s->attach($taker);
        }
        $s->emit(new Event('a', ['v' => 1]));
        $label = $takerFirst ? 'taker first' : 'reader first';
        H::assertEqual($seen['records'][0]['data']['v'], 1, $label . ': entry record');
        H::assertEqual($seen['records'][1]['data']['v'], 1, $label . ': settled record');
        H::assertEqual($seen['records'][1]['data']['list'][0], 1, $label . ': nested data');
        $e = $seen['records'][1]['data']['e'];
        H::assert(!isset($stamps[$e]) && $e->data['w'] === 1, $label . ': an event in the record');
    }
});

H::test('an observer that raises still leaves every other observer its batch', function (): void {
    $s = new Stream();
    $got = [];
    $s->attach(static function (): void {
        throw new \RuntimeException('first observer fails');
    });
    $s->attach(static function (array $b) use (&$got): void {
        $got[] = $b['outcome'];
    });
    $s->emit(new Event('x'));
    H::assertEqual(implode(',', $got), 'settled');
});

H::test('a batch that cannot be built fails its observer alone: the rejection is raised all the same', function (): void {
    $s = new Stream();
    // PHP: a cause whose copy cannot be built (an Event forged by Reflection in a public field)
    $s->register(new Gate('a', static function () {
        $t = new Thrown('cannot be copied');
        $t->n = (new \ReflectionClass(Event::class))->newInstanceWithoutConstructor();
        throw $t;
    }));
    $calls = 0;
    $s->attach(static function () use (&$calls): void {
        $calls++;
    });
    $r = null;
    try {
        $s->emit(new Event('a'));
    } catch (\Throwable $t) {
        $r = $t;
    }
    H::assert($r !== null, "the gate's raise did not reject");
    H::assertEqual($r->code, 'SISO_GATE_THREW');
    H::assertEqual($calls, 0, 'the observer whose batch could not be built was called');
});

// ── an entry too deep to copy ───────────────────

H::test('an entry Event accepts but the stream cannot copy is SISO_NOT_EVENT, no delivery, nothing committed', function (): void {
    $found = false;
    foreach ([58000, 59000, 60000, 61000, 62000] as $depth) {
        $built = null;
        try {
            $built = new Event('deep', deepArray($depth));
        } catch (\Throwable) {
            continue;
        }
        $s = new Stream();
        $s->emit(new Event('earlier'));
        $seen = null;
        $s->attach(static function (array $b) use (&$seen): void {
            $seen = $b;
        });
        $r = null;
        try {
            $s->emit($built);
        } catch (\Throwable $t) {
            $r = $t;
        }
        if ($r !== null) {
            noProblems(API::rejectionProblems($r, ['code' => 'SISO_NOT_EVENT', 'noDelivery' => true]), $depth . '');
            H::assertEqual(count($s->sampleHere()['residue']), 1, $depth . ': a refused entry committed residue');
            H::assert($seen !== null && $seen['outcome'] === 'rejected' && $seen['records'] === [], $depth . ": the observer's batch");
            $found = true;
        } else {
            H::assert($seen !== null && $seen['outcome'] === 'settled', $depth . ': a copied deep entry settles');
            H::assertEqual(count($s->sampleHere()['residue']), 2, $depth . ': the deep entry settled as residue');
        }
        unset($built, $s, $seen, $r);
    }
    // the PHP copy is iterative; if no depth was refused, nothing here is wrong
    if (!$found) {
        echo "  (no depth in the window overflowed the copy on this substrate)\n";
    }
});

H::test('a shallow entry is unaffected: copied, settled, the stream usable after a refused deep one', function (): void {
    $s = new Stream();
    API::attempt(static fn () => $s->emit(new Event('deep', deepArray(61000))));
    $s->emit(new Event('fine', ['v' => 1]));
    $r = $s->takeResidue();
    H::assertEqual($r[count($r) - 1]->type, 'fine');
    H::assertEqual($r[count($r) - 1]->data['v'], 1);
});

// ── plain rejection copies keep plain data ──────

H::test("an observer's rejection keeps string marks: an array in a cause stays an array", function (): void {
    $s = new Stream();
    $s->register(new Gate('a', static function () {
        $t = new Thrown('marks');
        $t->rows = [];
        $t->list = [1, 2];
        $t->n = Sentinel::Nothing;
        throw $t;
    }));
    $shown = null;
    $s->attach(static function (array $b) use (&$shown): void {
        $shown = $b['rejection'];
    });
    $raised = null;
    try {
        $s->emit(new Event('a'));
    } catch (\Throwable $t) {
        $raised = $t;
    }
    $why = SEAM::rejectionCopyProblem($shown, $raised, Event::class);
    H::assert($why === null, (string)$why);
    H::assert($shown['cause']['rows'] === [], 'an empty array in the cause stays an empty array');
    H::assert($shown['cause']['list'] === [1, 2], 'an array in the cause stays that array');
    H::assert($shown['cause']['n'] === Sentinel::Nothing, 'a sentinel in the cause keeps its identity');
    H::assertEqual($shown['cause'], ['rows' => [], 'list' => [1, 2], 'n' => Sentinel::Nothing, 'inner' => null],
        "the copy's cause is the cause's public fields");
    H::assert(is_array($shown), 'the copy itself is plain data');
});

H::test("an observer's rejection hands out no table through getmetatable, at any depth", function (): void {
    $s = new Stream();
    $s->register(new Gate('a', static function () {
        $t = new Thrown('nested');
        $t->inner = new Inner();
        throw $t;
    }));
    $shown = null;
    $s->attach(static function (array $b) use (&$shown): void {
        $shown = $b['rejection'];
    });
    API::attempt(static fn () => $s->emit(new Event('a')));
    $o = anObject($shown);
    H::assert($o === null, 'an object at some depth of the copy: ' . ($o === null ? '' : get_class($o)));
    H::assertEqual($shown['cause']['inner'], ['a' => [1]], 'a nested object is copied as its public fields');
});

H::finish('stream-observers-entry');
