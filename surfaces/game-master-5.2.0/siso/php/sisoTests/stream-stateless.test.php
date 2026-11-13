<?php
/*
stream-stateless.test.php — the doors carry no state (core-api STATELESS
THROUGH THE EVENT, IMMUTABLE SENTINELS, OWNERSHIP; observer-seam THE
REJECTION; core 13.0.1). Port of lua/sisoTests/stream-stateless.test.lua,
case by case. Nothing reachable from an event, a sample, a take or an
observer's batch may be an object the core holds or shares. Runs the
contracts' checks on the real core, then walks everything a holder can
reach (arrays, public properties, the classes of the objects reached)
and pins that it is fresh, immutable and shared with no other stream or
propagation. The gates below are fixtures for the stream, not project
gates.
  php php/sisoTests/stream-stateless.test.php   (from the project root)

Turned into PHP's own attempt at the same violation (core-api SUBSTRATE:
"Lua's metatable channel becomes static state"; "the freeze's private key
vanishes"; sentinels are enum cases):
  - getmetatable answers ("siso.event", false, "siso.rejection") become:
    the event and rejection classes (and Gate, Stream, Freeze) hold no
    static state, data are arrays (no class), Event::is / Rejection::is
    are instanceof.
  - reach() walks arrays and public properties (what a holder reaches
    without Reflection); "tables" are objects (arrays are values and have
    no identity, so the scenario carries Events in data).
  - a gate's `rawset` of a counter into everything it reaches becomes a
    dynamic-property write on every object it reaches (refused) plus the
    count probe on public statics (the contract's check).
  - "pairs hands out the frozen table itself as its state": iteration over
    an event hands out its two properties; by reference it is refused.
  - the captured freeze key: PHP's privileged path past a refusal is a
    closure bound into the Event class scope; its writes (assignment, a
    new property, unset) are refused, so nothing written through it
    reaches anything.
  - "the core 12 form is not handed to observers": its PHP counterpart is
    a writable object (not an enum case) in what a gate throws: the host's
    rejection carries it, the observer's copy carries it as an array of
    its public fields.
  - "an inherited field is not carried": a protected field of the thrown
    object is not in the copy.
No case is inapplicable.
*/
declare(strict_types=1);

namespace Siso\Tests\StreamStateless;

require_once __DIR__ . '/../code/harness.php';
require_once __DIR__ . '/../code/contracts/core_api.php';
require_once __DIR__ . '/../code/contracts/observer_seam.php';
require_once __DIR__ . '/../code/core/event.php';
require_once __DIR__ . '/../code/core/gate.php';
require_once __DIR__ . '/../code/core/stream.php';
require_once __DIR__ . '/../code/core/rejection.php';
require_once __DIR__ . '/../code/core/freeze.php';

use Siso\Contracts\CoreApi as API;
use Siso\Contracts\ObserverSeam as SEAM;
use Siso\Core\Event;
use Siso\Core\Freeze;
use Siso\Core\Gate;
use Siso\Core\Rejection;
use Siso\Core\Stream;
use Siso\Harness\H;

const CORE = ['Event' => Event::class, 'Gate' => Gate::class, 'Stream' => Stream::class];

/** an immutable sentinel (IMMUTABLE SENTINELS) */
enum Sentinel
{
    case Stateless;
    case KitDataNull;
}

/** what the probe gates throw: public fields of its own, and a protected one */
final class Err extends \RuntimeException
{
    public string $msg = 'm';
    public array $nested = [1, [2]];
    public mixed $n = null;
    protected string $kind = 'io';

    public function kind(): string
    {
        return $this->kind;
    }
}

function noProblems(array $list, ?string $label = null): void
{
    H::assert($list === [], ($label !== null ? $label . ': ' : '') . implode('; ', $list));
}

/**
 * reach($roots) → every object reachable from $roots by what a holder can
 * do without Reflection: arrays and public properties; enum cases are
 * immutable values shared by design and are not entered. Keyed by id,
 * holding the objects (so no id is reused while the set lives).
 */
function reach(array $roots): array
{
    $seen = [];
    $stack = $roots;
    while ($stack !== []) {
        $v = array_pop($stack);
        if (is_array($v)) {
            foreach ($v as $x) {
                $stack[] = $x;
            }
            continue;
        }
        if (!is_object($v) || $v instanceof \UnitEnum || isset($seen[spl_object_id($v)])) {
            continue;
        }
        $seen[spl_object_id($v)] = $v;
        foreach (get_object_vars($v) as $x) {
            $stack[] = $x;
        }
    }
    return $seen;
}

function overlap(array $a, array $b): int
{
    return count(array_intersect_key($a, $b));
}

/**
 * scenario() → [everything that left one fresh stream through its doors
 * in a settled and a rejected propagation: the events its gates were
 * given, two samples, the take, and an observer's batches; the batches].
 * Each call builds its own stream, gates and entries.
 */
function scenario(): array
{
    $out = [];
    $null = Sentinel::Stateless;
    $s = new Stream();
    $s->register(new Gate('fx_root', static function (Event $e) use (&$out, $null) {
        $out[] = $e;
        return [new Event('fx_mid', ['list' => [$e->data['v'], 2], 'm' => ['k' => 'a'], 'n' => $null, 'e' => new Event('fx_in')]),
            new Event('fx_leaf', $e->data)];
    }));
    $s->register(new Gate('fx_mid', static function (Event $e) use (&$out) {
        $out[] = $e;
        return new Event('fx_out', ['from' => $e->data, 'e' => new Event('fx_in2', ['x' => $e->data['e']])]);
    }));
    $s->register(new Gate('fx_bad', static function (Event $e) use (&$out, $null) {
        $out[] = $e;
        $err = new Err("the gate's own");
        $err->n = $null;
        throw $err;
    }));
    $batches = [];
    $s->attach(static function (array $b) use (&$batches): void {
        $batches[] = $b;
    });
    $s->emit(new Event('fx_root', ['v' => 1, 'deep' => ['a' => ['b' => [1, new Event('fx_deep')]]]]));
    API::attempt(static fn () => $s->emit(new Event('fx_bad', ['w' => [1], 'e' => new Event('fx_w')])));
    $out[] = $s->sampleHere();
    $out[] = $s->sampleHere();
    $out[] = $s->takeResidue();
    foreach ($batches as $b) {
        $out[] = $b;
    }
    return [$out, $batches];
}

// ── the contracts on the real core ─────────────

H::test('core-api read-only, ownership and doors hold on the core', function (): void {
    noProblems(API::checkReadonly(CORE), 'readonly');
    noProblems(API::checkOwnership(CORE), 'ownership');
    noProblems(API::checkDoors(CORE), 'doors');
});

H::test('observer-seam seam and ownership hold on the core', function (): void {
    noProblems(SEAM::checkSeam(CORE), 'seam');
    noProblems(SEAM::checkOwnership(CORE), 'ownership');
});

// ── identities answer with classes that hold nothing ──

H::test('events answer "siso.event", plain data false, rejections "siso.rejection"', function (): void {
    $e = new Event('t', ['list' => [1], 'm' => []]);
    H::assertEqual(get_class($e), Event::class);
    H::assert(is_array($e->data) && is_array($e->data['list']) && is_array((new Event('u'))->data), 'data are arrays, with no class');
    H::assert(Event::is($e) && !Event::is(['type' => 't', 'data' => []]) && !Event::is((object)['type' => 't', 'data' => []]),
        'Event::is is instanceof');
    $s = new Stream();
    $s->register(new Gate('fx_boom', static function () {
        throw new \RuntimeException('boom');
    }));
    $r = null;
    try {
        $s->emit(new Event('fx_boom'));
    } catch (\Throwable $t) {
        $r = $t;
    }
    H::assertEqual(get_class($r), Rejection::class);
    H::assert(Rejection::is($r), 'Rejection::is is instanceof');
    H::assertEqual((string)$r, $r->message, '(string) of the thrown rejection is still its message');
    foreach ([Event::class, Gate::class, Stream::class, Rejection::class, Freeze::class] as $cls) {
        H::assertEqual(API::staticStateOf($cls), [], 'nothing writable is reachable from the class ' . $cls);
    }
});

// ── nothing reachable is shared ────────────────

H::test('two streams share no table through anything that left their doors', function (): void {
    [$outA] = scenario();
    [$outB] = scenario();
    $a = reach($outA);
    $b = reach($outB);
    H::assert(count($a) > 20 && count($b) > 20, 'the walk reached the outputs (' . count($a) . ', ' . count($b) . ')');
    H::assertEqual(overlap($a, $b), 0, 'objects reachable from both streams');
});

H::test('two events, and two propagations of one stream, share no table', function (): void {
    $x = reach([new Event('t', ['a' => [new Event('i')]])]);
    $y = reach([new Event('t', ['a' => [new Event('i')]])]);
    H::assertEqual(overlap($x, $y), 0, 'two events');
    $s = new Stream();
    $given = [];
    $s->register(new Gate('fx_g', static function (Event $e) use (&$given) {
        $given[] = $e;
        return new Event('fx_out', ['got' => $e->data]);
    }));
    $entry = new Event('fx_g', ['v' => [1], 'e' => new Event('fx_in')]);
    $s->emit($entry);
    $first = reach([$given[0], $s->takeResidue()]);
    $s->emit($entry);
    $second = reach([$given[1], $s->takeResidue()]);
    H::assertEqual(overlap($first, $second), 0, 'one entry entered twice');
    H::assertEqual(overlap(reach([$entry]), $first), 0, "the entering code's event and the stream's");
});

H::test('plain data reaches only immutable values: every reachable table refuses assignment and hands out no table', function (): void {
    [$out] = scenario();
    $checked = 0;
    foreach (reach($out) as $o) {
        $checked++;
        H::assert($o instanceof Event, 'a reachable object is a ' . get_class($o) . ', not an Event');
        H::assert(!API::attempt(static function () use ($o): void {
            $o->__probe = 1;
        }), 'a reachable event accepts a new property');
        H::assert(!API::attempt(static function () use ($o): void {
            (new \ReflectionProperty($o, 'data'))->setValue($o, []);
        }), 'a reachable event accepts a Reflection write');
    }
    H::assert($checked > 20, 'the walk checked the events (' . $checked . ')');
});

H::test('a gate writing (rawset included) into everything it can reach counts nothing across propagations or streams', function (): void {
    $run = static function (Stream $s): mixed {
        $s->emit(new Event('fx_count', ['a' => ['b' => [new Event('fx_in')]], 'l' => [1, 2]]));
        return $s->takeResidue()[0]->data['n'];
    };
    $counting = static function (): Stream {
        $s = new Stream();
        $s->register(new Gate('fx_count', static function (Event $e) {
            $most = 0;
            foreach (reach([$e]) as $o) {
                $n = (get_object_vars($o)['__probe'] ?? 0) + 1;
                API::attempt(static function () use ($o, $n): void {
                    $o->__probe = $n;
                });
                API::attempt(static function () use ($o, $n): void {
                    (new \ReflectionProperty($o, 'data'))->setValue($o, ['__probe' => $n]);
                });
                $most = max($most, $n, $o->data['__probe'] ?? 0);
            }
            return new Event('fx_counted', ['n' => $most]);
        }));
        return $s;
    };
    $s = $counting();
    H::assertEqual($run($s), 1, 'first propagation');
    H::assertEqual($run($s), 1, 'a second propagation of the same stream');
    H::assertEqual($run($counting()), 1, 'another stream');
});

// ── the privileged path (PHP's counterpart of a captured freeze key) ──

/** a closure bound into the Event class scope, running $fn($event) there */
function inScope(Event $e, \Closure $fn): bool
{
    return API::attempt(\Closure::bind(static function () use ($e, $fn): void {
        $fn($e);
    }, null, Event::class));
}

H::test('pairs hands out the frozen table itself as its state; the step reads its own proxy', function (): void {
    $e = new Event('t', ['a' => 1]);
    $keys = [];
    foreach ($e as $k => $_) {
        $keys[] = $k;
    }
    H::assertEqual(implode(',', $keys), 'type,data', 'iteration hands out the two properties');
    H::assert(!API::attempt(static function () use ($e): void {
        foreach ($e as &$v) {
            $v = 'z';
        }
    }), 'iteration by reference is refused');
    H::assertEqual($e->data['a'], 1);
});

H::test('a captured key is a function that holds no field; the view it reaches answers false', function (): void {
    $e = new Event('t', ['v' => 1, 'list' => [1]]);
    H::assert(!inScope($e, static function (Event $x): void {
        $x->data = ['v' => 2];
    }), 'assignment from the class scope is refused');
    H::assert(!inScope($e, static function (Event $x): void {
        $x->extra = 1;
    }), 'a new property from the class scope is refused');
    H::assert(!inScope($e, static function (Event $x): void {
        unset($x->data);
    }), 'unset from the class scope is refused');
    H::assert(!inScope($e, static function (Event $x): void {
        $r = &$x->data['v'];
        $r = 2;
    }), 'a reference taken from the class scope is refused');
    H::assertEqual($e->data['v'], 1, 'no write past the refusal reached the event');
    H::assertEqual(array_keys(get_object_vars($e)), ['type', 'data']);
});

H::test('nothing written through a captured key reaches another propagation, stream, sample or observer', function (): void {
    $counting = static function (): Stream {
        $s = new Stream();
        $s->register(new Gate('fx_count', static function (Event $e) {
            $most = 0;
            foreach (reach([$e]) as $o) {
                $n = ($o->data['__probe'] ?? 0) + 1;
                inScope($o, static function (Event $x) use ($n): void {
                    $x->data = ['__probe' => $n];
                });
                $most = max($most, $n, $o->data['__probe'] ?? 0);
            }
            return new Event('fx_counted', ['n' => $most]);
        }));
        return $s;
    };
    $run = static function (Stream $s): mixed {
        $s->emit(new Event('fx_count', ['a' => ['b' => [new Event('fx_in')]], 'l' => [1, 2]]));
        return $s->takeResidue()[0]->data['n'];
    };
    $s = $counting();
    H::assertEqual($run($s), 1, 'first propagation');
    H::assertEqual($run($s), 1, 'a second propagation of the same stream');
    H::assertEqual($run($counting()), 1, 'another stream');

    // the outside, through the class scope: a sample's and a record's events
    $t = new Stream();
    $batches = [];
    $t->attach(static function (array $b) use (&$batches): void {
        $batches[] = $b;
    });
    $t->attach(static function (array $b) use (&$batches): void {
        $batches[] = $b;
    });
    $t->emit(new Event('fx_kept', ['v' => 1, 'e' => new Event('fx_in', ['w' => 1])]));
    $sampled = $t->sampleHere()['residue'][0];
    inScope($sampled, static function (Event $x): void {
        $x->data = ['v' => 9];
    });
    inScope($batches[0]['records'][0]['data']['e'], static function (Event $x): void {
        $x->data = ['w' => 8];
    });
    H::assertEqual($t->sampleHere()['residue'][0]->data['v'], 1, "a write through a sample reached the stream");
    H::assertEqual($batches[1]['records'][0]['data']['e']->data['w'], 1, "a write through one observer's record reached another");
    H::assertEqual($t->takeResidue()[0]->data['v'], 1, 'a write reached the residue taken');
});

// ── immutable sentinels ────────────────────────

H::test('a "readonly:" sentinel keeps its identity through every door; the core 12 form is not handed to observers', function (): void {
    $null = Sentinel::KitDataNull;
    $s = new Stream();
    $inGate = null;
    $s->register(new Gate('fx_null', static function (Event $e) use (&$inGate) {
        $inGate = $e->data['n'];
        return new Event('fx_out', ['n' => $e->data['n'], 'l' => [$e->data['n']]]);
    }));
    $s->register(new Gate('fx_throw', static function (Event $e) {
        $err = new Err('x');
        $err->n = $e->data['n'];
        throw $err;
    }));
    $batches = [];
    $s->attach(static function (array $b) use (&$batches): void {
        $batches[] = $b;
    });
    $s->emit(new Event('fx_null', ['n' => $null]));
    $raised = null;
    try {
        $s->emit(new Event('fx_throw', ['n' => $null]));
    } catch (\Throwable $t) {
        $raised = $t;
    }
    H::assert($inGate === $null, 'a gate is given the sentinel itself, through the entry copy');
    $sampled = $s->sampleHere()['residue'][0];
    H::assert($sampled->data['n'] === $null && $sampled->data['l'][0] === $null, 'a sample keeps it');
    H::assert($batches[0]['records'][1]['data']['n'] === $null, "an observer's record keeps it");
    H::assert($batches[1]['rejection']['cause']['n'] === $null, "an observer's rejection keeps it");
    H::assert($raised->cause->n === $null, "the host's rejection keeps it");
    $taken = $s->takeResidue()[0];
    H::assert($taken->data['n'] === $null, 'a take keeps it');

    // PHP's counterpart of the core 12 form: a writable object in what a
    // gate throws; the host's rejection carries it, the observer's copy
    // carries its public fields
    $old = new \stdClass();
    $old->v = 1;
    $t = new Stream();
    $t->register(new Gate('fx_old', static function () use ($old) {
        $err = new Err('old');
        $err->n = $old;
        throw $err;
    }));
    $seen = null;
    $t->attach(static function (array $b) use (&$seen): void {
        $seen = $b['rejection'];
    });
    $r2 = null;
    try {
        $t->emit(new Event('fx_old'));
    } catch (\Throwable $x) {
        $r2 = $x;
    }
    H::assert($r2->cause->n === $old, "the host's rejection carries what the gate raised");
    H::assertEqual($seen['cause']['n'], ['v' => 1], "the observer's copy carries the object's public fields, not the object");
    H::assert(SEAM::rejectionCopyProblem($seen, $r2, Event::class) === null, (string)SEAM::rejectionCopyProblem($seen, $r2, Event::class));
});

// ── the rejection an observer is shown ─────────

H::test("an observer's rejection is plain at every depth: own fields only, no metatable, nothing shared", function (): void {
    $cause = new Err('E');
    $s = new Stream();
    $s->register(new Gate('fx_err', static function () use ($cause) {
        throw $cause;
    }));
    $copies = [];
    $s->attach(static function (array $b) use (&$copies): void {
        $copies[] = $b['rejection'];
    });
    $s->attach(static function (array $b) use (&$copies): void {
        $copies[] = $b['rejection'];
    });
    $raised = null;
    try {
        $s->emit(new Event('fx_err'));
    } catch (\Throwable $t) {
        $raised = $t;
    }
    H::assertEqual(count($copies), 2);
    foreach ($copies as $i => $c) {
        $why = SEAM::rejectionCopyProblem($c, $raised, Event::class);
        H::assert($why === null, 'copy ' . $i . ': ' . (string)$why);
        H::assertEqual($c['cause']['msg'], 'm', 'copy ' . $i . ": the cause's own field");
        H::assert(!array_key_exists('kind', $c['cause']), 'copy ' . $i . ': a protected field is not carried');
        H::assertEqual($c['cause']['nested'][1][0], 2);
        H::assertEqual($c['message'], $raised->message, 'copy ' . $i . ': the message carries the rendering');
        H::assertEqual(count(reach([$c])), 0, 'copy ' . $i . ': an object in it');
        H::assertEqual(overlap(reach([$c]), reach([$raised])), 0, 'copy ' . $i . ' shares an object with the raised rejection');
    }
    H::assertEqual(overlap(reach([$copies[0]]), reach([$copies[1]])), 0, "the two observers' copies share an object");
    H::assertEqual($cause->getMessage(), 'E', "the gate's error object is untouched");
    H::assertEqual($cause->kind(), 'io');
});

H::finish('stream-stateless');
