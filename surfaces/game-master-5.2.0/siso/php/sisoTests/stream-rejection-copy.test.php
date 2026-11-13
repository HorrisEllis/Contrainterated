<?php
/*
stream-rejection-copy.test.php — each observer is shown its own copy of
the rejection (observer-seam THE REJECTION, core 13), while the entering
code receives the very object thrown, `cause` included. Port of
lua/sisoTests/stream-rejection-copy.test.lua, case by case. Runs the
observer-seam checks on the real core, then pins each code, the copies'
independence, and that a copy is built inside its observer's protected
call. The gates below are fixtures for the stream, not project gates.
  php php/sisoTests/stream-rejection-copy.test.php   (from the project root)

Turned into PHP's own attempt at the same violation (observer-seam
SUBSTRATE: the read-only copy becomes value ownership):
  - "two observers … their own read-only copy": a copy is an array, a
    value; `not rawequal(a, b)` and "assigning to a copy is refused"
    become: an assignment to one copy changes only that copy, never the
    other observer's, nor the rejection thrown.
  - "each copy's cause is its own": the gate throws an exception object
    with public fields; each copy's cause is an array of those fields,
    and writing into one copy's cause reaches nothing else (Lua's rawset
    on a copy becomes that write).
  - "a non-table cause is carried as it is": PHP throws only objects; a
    gate's attempt to throw a string raises \Error, the host's cause is
    that \Error, and each copy carries it as its public fields (none), the
    rendering being in the copy's message.
  - "a copy that cannot be built fails that observer alone": Lua poisons
    `pairs` on the cause; PHP reads a cause's public properties without
    running its code, so the poison is an Event forged by Reflection
    (trusted-code deception, core-api SUBSTRATE) in a public field of the
    cause: copying it raises, inside that observer's protected call.
No case is inapplicable.
*/
declare(strict_types=1);

namespace Siso\Tests\StreamRejectionCopy;

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

/** what the probe gates throw: an exception with public fields of its own */
final class Thrown extends \RuntimeException
{
    public string $first = "the gate's own";
    public array $nested = [1];
    public mixed $poison = null;
}

function noProblems(array $list, ?string $label = null): void
{
    H::assert($list === [], ($label !== null ? $label . ': ' : '') . implode('; ', $list));
}

/**
 * Emit $entry on $s with $n recording observers attached; → [the thrown
 * rejection (null when it settled), each observer's batches].
 */
function watched(Stream $s, mixed $entry, int $n): array
{
    $seen = [];
    for ($i = 0; $i < $n; $i++) {
        $seen[$i] = [];
    }
    for ($i = 0; $i < $n; $i++) {
        $s->attach(static function (array $b) use (&$seen, $i): void {
            $seen[$i][] = $b;
        });
    }
    try {
        $s->emit($entry);
        return [null, $seen];
    } catch (\Throwable $t) {
        return [$t, $seen];
    }
}

// ── the contract on the real core ──────────────

H::test('observer-seam: check.seam holds on the core', function (): void {
    noProblems(SEAM::checkSeam(CORE));
});

H::test('observer-seam: check.ownership holds on the core (records and the rejection)', function (): void {
    noProblems(SEAM::checkOwnership(CORE));
});

// ── every code: a copy for the observer, the object itself for the host ──

function cases(): array
{
    return [
        ['SISO_SELF_LOOP', static function (): array {
            $s = new Stream();
            $s->register(new Gate('fx_loop', static fn () => new Event('fx_loop')));
            return [$s, new Event('fx_loop')];
        }],
        ['SISO_CYCLE', static function (): array {
            $s = new Stream();
            $s->register(new Gate('fx_a', static fn () => new Event('fx_b')));
            $s->register(new Gate('fx_b', static fn () => new Event('fx_a')));
            return [$s, new Event('fx_a')];
        }],
        ['SISO_GATE_THREW', static function (): array {
            $s = new Stream();
            $s->register(new Gate('fx_boom', static function () {
                throw new \RuntimeException('boom');
            }));
            return [$s, new Event('fx_boom')];
        }],
        ['SISO_NOT_EVENT', static function (): array {
            $s = new Stream();
            $s->register(new Gate('fx_bad', static fn () => 42));
            return [$s, new Event('fx_bad')];
        }],
        ['SISO_NOT_EVENT', static fn (): array => [new Stream(), ['type' => 'not', 'data' => []]]],
    ];
}

H::test('each code: two observers each get their own read-only copy; the host gets the rejection itself', function (): void {
    foreach (cases() as $i => [$code, $build]) {
        $where = $code . ' (case ' . ($i + 1) . ')';
        [$s, $entry] = $build();
        [$raised, $seen] = watched($s, $entry, 2);
        H::assert($raised !== null, $where . ': settled');
        noProblems(API::rejectionProblems($raised, ['code' => $code]), $where);
        H::assertEqual((string)$raised, $raised->message, $where . ': (string) of the thrown rejection');
        H::assertEqual(count($seen[0]), 1, $where . ': observer 1 batches');
        H::assertEqual(count($seen[1]), 1, $where . ': observer 2 batches');
        $a = $seen[0][0]['rejection'];
        $b = $seen[1][0]['rejection'];
        $why = SEAM::rejectionCopyProblem($a, $raised, Event::class) ?? SEAM::rejectionCopyProblem($b, $raised, Event::class);
        H::assert($why === null, $where . ': ' . (string)$why);
        H::assertEqual($a['message'], $raised->message, $where . ": the copy's message is read by indexing");
        // PHP: a copy is a value; assigning to one changes only that one
        $a['code'] = 'X';
        H::assertEqual($seen[0][0]['rejection']['code'], $code, $where . ": a write to a copy reached the observer's batch");
        H::assertEqual($b['code'], $code, $where . ': the two observers share one copy');
        H::assertEqual($raised->code, $code, $where . ': a write to a copy reached the thrown rejection');
    }
});

H::test("SISO_GATE_THREW: the host's cause is exactly what the gate raised; each copy's cause is its own", function (): void {
    $thrown = new Thrown('bad');
    $s = new Stream();
    $s->register(new Gate('fx_throw', static function () use ($thrown) {
        throw $thrown;
    }));
    [$raised, $seen] = watched($s, new Event('fx_throw'), 2);
    H::assert($raised->cause === $thrown, 'the host receives the very object raised as cause');
    $a = $seen[0][0]['rejection'];
    $b = $seen[1][0]['rejection'];
    H::assert(is_array($a['cause']) && is_array($b['cause']), "a copy's cause is not the raised object");
    H::assertEqual($a['cause']['first'], "the gate's own");
    H::assertEqual($a['cause']['nested'][0], 1);
    H::assert(!array_key_exists('message', $a['cause']), "a \\Throwable's protected message is not carried");
    $a['code'] = 'CHANGED';
    $a['cause']['first'] = 'CHANGED';
    $a['cause']['nested'][0] = 9;
    H::assertEqual($raised->code, 'SISO_GATE_THREW', 'a write on a copy reached the raised rejection');
    H::assertEqual($thrown->first, "the gate's own", 'a write on a copy reached what the gate raised');
    H::assertEqual($thrown->nested[0], 1);
    H::assertEqual($b['code'], 'SISO_GATE_THREW', 'a write on one copy reached another');
    H::assertEqual($b['cause']['first'], "the gate's own", "a write on one copy's cause reached another");
    H::assertEqual($b['cause']['nested'][0], 1);
});

H::test('a non-table cause is carried as it is', function (): void {
    // PHP: the gate's attempt to throw a string raises \Error; that is the cause
    $s = new Stream();
    $s->register(new Gate('fx_str', static function () {
        $plain = 'plain string';
        throw $plain;
    }));
    [$raised, $seen] = watched($s, new Event('fx_str'), 1);
    H::assert($raised->cause instanceof \Error && $raised->cause->getMessage() === 'Can only throw objects',
        "the host's cause is PHP's refusal of the throw");
    $copy = $seen[0][0]['rejection'];
    H::assertEqual($copy['cause'], [], "the copy carries the \\Error's public fields (none)");
    H::assert(str_contains($copy['message'], 'Can only throw objects'), "the copy's message carries the rendering");
    H::assert(SEAM::rejectionCopyProblem($copy, $raised, Event::class) === null, (string)SEAM::rejectionCopyProblem($copy, $raised, Event::class));
});

H::test('a settled batch carries no rejection; with no observer the rejection is raised all the same', function (): void {
    $s = new Stream();
    [$raised, $seen] = watched($s, new Event('fx_free'), 1);
    H::assertEqual($raised, null);
    H::assertEqual($seen[0][0]['outcome'], 'settled');
    H::assertEqual($seen[0][0]['rejection'], null);
    $t = new Stream();
    $t->register(new Gate('fx_boom', static function () {
        throw new \RuntimeException('boom');
    }));
    $r = null;
    try {
        $t->emit(new Event('fx_boom'));
    } catch (\Throwable $x) {
        $r = $x;
    }
    H::assert($r !== null);
    noProblems(API::rejectionProblems($r, ['code' => 'SISO_GATE_THREW', 'eventId' => 1]));
});

// ── the copy is built inside the observer's protected call ──

H::test('a copy that cannot be built fails that observer alone: emit raises exactly as without observers', function (): void {
    // PHP: an Event forged by Reflection (its properties never initialised)
    // in a public field of the cause: copying it raises
    $poisoned = new Thrown('poisoned');
    $poisoned->poison = (new \ReflectionClass(Event::class))->newInstanceWithoutConstructor();
    $run = static function (int $n) use ($poisoned): array {
        $s = new Stream();
        $s->register(new Gate('fx_poison', static function () use ($poisoned) {
            throw $poisoned;
        }));
        $called = 0;
        for ($i = 0; $i < $n; $i++) {
            $s->attach(static function () use (&$called): void {
                $called++;
            });
        }
        $r = null;
        try {
            $s->emit(new Event('fx_poison'));
        } catch (\Throwable $t) {
            $r = $t;
        }
        $calledThen = $called;
        $s->emit(new Event('fx_after'));
        return [$r, $calledThen, count($s->takeResidue())];
    };
    [$r0] = $run(0);
    [$r2, $called, $left] = $run(2);
    H::assert($r0 !== null && $r2 !== null, 'the poisoned gate settled');
    H::assertEqual($r2->code, $r0->code);
    H::assertEqual($r2->code, 'SISO_GATE_THREW');
    noProblems(API::rejectionProblems($r2, ['code' => 'SISO_GATE_THREW', 'eventId' => 1]));
    H::assert($r2->cause === $poisoned, 'the host still receives the very object raised');
    H::assertEqual($called, 0, 'an observer whose batch could not be built is not called');
    H::assertEqual($left, 1, 'the stream is usable afterwards and committed nothing from the rejection');
});

H::finish('stream-rejection-copy');
