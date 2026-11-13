<?php
/*
contract-fake-core — a throwaway implementation that exists only so the
contract tests can show each contract check passing on something that
honours the contract, and failing on each departure it names. Port of
lua/sisoTests/contract-fake-core.lua.

It is NOT the core and NOT a reference for it: no attention to cost, not
checked against the reference. The stream phases build php/code/core/
from the contracts and the Lua reference, never from this. It lives in
its own namespace (Siso\Tests\Fake), so it never collides with
Siso\Core.

  require_once __DIR__ . '/contract-fake-core.php';
  use Siso\Tests\Fake\Fake;
  $core = Fake::core();                   // honours core-api and observer-seam
  $core = Fake::core('skipNonEvents');    // 11.1's X1
  // ['Event' => class-string, 'Gate' => class-string, 'Stream' => class-string]

Switches, each reproducing one departure (one switch per core):
  skipNonEvents    non-Event list members are skipped (11.1 X1)
  acceptAnyEntry   the entry door takes any value (11.1)
  countIds         ids continue across propagations (11.1 R1)
  sampleCount      sampleHere carries eventCount (11.1 R1)
  noFreeze         events are not read-only (11.1 R3)
  observeDuring    observers are called per delivery, during the
                   propagation (11.1's observer)
  unprotected      an observer's throw escapes emit
  sharedBatch      every observer is handed the same batch variable (a
                   by-reference observer's writes reach the next one)
  shareFrozen      samples and observer records hand out the stream's own
                   objects instead of copies (12.0's X6)
  writableAtBirth  Event builds a mutable object that keeps the caller's
                   references; only the stream makes it read-only (12.0:
                   an event was writable until it entered)
  sharedRejection  every observer is shown the very rejection emit throws
                   (12.0's C-2)
  tableMeta        events carry a class with a public static array, a
                   channel for state (12.0.1 and 13.0.0's shared metatable)
  entryAlias       entry keeps the entering code's own event (13.0.0)
  richRejection    an observer's rejection copy is an object of the host's
                   class, sharing what the gate threw (13.0.0)
PHP-specific switches (a check exists to catch each):
  objectData       Event accepts any object in its data (writable state
                   reachable from an event)
  keepReferences   Event keeps the reference slots of the array it is
                   given (data not taken as it stands)
  forgeable        unserialize builds an event without validating it
  shallowRejection an observer's rejection copy is an array whose cause
                   is the very \Throwable the gate threw

HOW THE SWITCHES ARE HELD WITHOUT STATIC STATE: the checks take class
names, so each switch is its own small final class (an Event subclass
and/or a Stream subclass) that differs from the honest one only in class
constants (OPTS, FROZEN), read with static::. Constants are not state;
nothing here is written after load. The one departure that IS static
state (tableMeta) is that switch's own class. Test-only liberties: the
fake reads public properties with get_object_vars from its own scope and
keys a copy memo by spl_object_id.
*/
declare(strict_types=1);

namespace Siso\Tests\Fake;

/** what every fake event answers Event::is with */
interface FakeEvent
{
}

abstract class EventBase implements FakeEvent
{
    /** switches that change how an event is built */
    public const OPTS = [];

    public readonly string $type;
    public readonly array $data;

    public function __construct(string $type, ?array $data = null)
    {
        $data ??= [];
        if (empty(static::OPTS['objectData'])) {
            $path = [];
            self::validate($data, $path);
        }
        $this->type = $type;
        $this->data = empty(static::OPTS['keepReferences']) ? self::deref($data) : $data;
    }

    public static function is(mixed $v): bool
    {
        return $v instanceof FakeEvent;
    }

    public function __set(string $name, mixed $value): void
    {
        throw new \Error("read-only: an event and its data cannot be assigned (field '" . $name . "')");
    }

    public function __unset(string $name): void
    {
        throw new \Error("read-only: an event and its data cannot be assigned (field '" . $name . "')");
    }

    public function __serialize(): array
    {
        return ['type' => $this->type, 'data' => $this->data];
    }

    public function __unserialize(array $a): void
    {
        if (!empty(static::OPTS['forgeable'])) {
            $this->type = (string)($a['type'] ?? '');
            $this->data = (array)($a['data'] ?? []);
            return;
        }
        $keys = array_keys($a);
        sort($keys);
        if ($keys !== ['data', 'type'] || !is_string($a['type']) || !is_array($a['data'])) {
            throw new \UnexpectedValueException('Event: a serialized event holds exactly type and data');
        }
        $path = [];
        self::validate($a['data'], $path);
        $this->type = $a['type'];
        $this->data = self::deref($a['data']);
    }

    /** $path: the keys down to $d (a stack, so no path string is built unless a value is refused) */
    protected static function validate(array $d, array &$path): void
    {
        foreach ($d as $k => $v) {
            if (is_array($v)) {
                $path[] = $k;
                self::validate($v, $path);
                array_pop($path);
            } elseif (!($v === null || is_scalar($v) || $v instanceof FakeEvent || $v instanceof \UnitEnum)) {
                $path[] = $k;
                $at = implode('', array_map(static fn ($x): string => '[' . var_export($x, true) . ']', $path));
                throw new \InvalidArgumentException('Event: data at ' . $at . ' is not plain data (' . get_debug_type($v) . ')');
            }
        }
    }

    /** the array rebuilt without any reference slot */
    protected static function deref(array $d): array
    {
        $out = [];
        foreach ($d as $k => $v) {
            $out[$k] = is_array($v) ? self::deref($v) : $v;
        }
        return $out;
    }
}

final class Event extends EventBase
{
}

/** tableMeta: a class reachable from every event, holding writable static state */
final class StaticEvent extends EventBase
{
    public static array $meta = [];
}

final class ObjectDataEvent extends EventBase
{
    public const OPTS = ['objectData' => true];
}

final class RefEvent extends EventBase
{
    public const OPTS = ['keepReferences' => true];
}

final class ForgeableEvent extends EventBase
{
    public const OPTS = ['forgeable' => true];
}

/** noFreeze and writableAtBirth: a mutable event that keeps what it is given */
#[\AllowDynamicProperties]
final class LooseEvent implements FakeEvent
{
    public string $type;
    public array $data;

    public function __construct(string $type, ?array $data = null)
    {
        $this->type = $type;
        $this->data = $data ?? [];
    }

    public static function is(mixed $v): bool
    {
        return $v instanceof FakeEvent;
    }
}

final class Gate
{
    public readonly string $signature;
    public readonly \Closure $transform;
    public readonly ?array $shape;

    public function __construct(string $signature, ?callable $transform = null, ?array $shape = null)
    {
        $this->signature = $signature;
        $this->transform = $transform === null
            ? static function (mixed $event = null): mixed {
                return null;
            }
            : \Closure::fromCallable($transform);
        $this->shape = $shape;
    }
}

final class Rejection extends \RuntimeException
{
    public $code;
    public $message;
    public $eventId = null;
    public $causeId = null;
    public $eventType = null;
    public $depth = null;
    public $cause = null;

    public function __construct(string $code, string $message, ?int $eventId = null, ?int $causeId = null,
        ?string $eventType = null, ?int $depth = null, mixed $cause = null)
    {
        parent::__construct($message);
        $this->code = $code;
        $this->message = $message;
        $this->eventId = $eventId;
        $this->causeId = $causeId;
        $this->eventType = $eventType;
        $this->depth = $depth;
        $this->cause = $cause;
    }

    public function __toString(): string
    {
        return $this->message;
    }

    public static function is(mixed $v): bool
    {
        return $v instanceof self;
    }
}

abstract class StreamBase
{
    /** switches that change how the stream behaves */
    public const OPTS = [];
    /** the class the stream's own copies of events are built as */
    public const FROZEN = Event::class;

    private array $gates = [];
    private int $n = 0;
    private array $residue = [];
    private array $observers = [];
    private int $count = 0;

    private static function on(string $k): bool
    {
        return !empty(static::OPTS[$k]);
    }

    private static function isEvent(mixed $v): bool
    {
        return $v instanceof FakeEvent;
    }

    private static function describe(mixed $v): string
    {
        if ($v === null) {
            return 'null';
        }
        if (is_bool($v)) {
            return 'the bool ' . ($v ? 'true' : 'false');
        }
        if (is_int($v) || is_float($v)) {
            return 'the ' . get_debug_type($v) . ' ' . var_export($v, true);
        }
        if (is_string($v)) {
            return 'a string';
        }
        if (is_array($v)) {
            return 'an array that is not an Event';
        }
        return 'a ' . get_debug_type($v) . ' that is not an Event';
    }

    public function register(object $g): void
    {
        if (isset($this->gates[$g->signature])) {
            throw new \LogicException("Signature collision: '" . $g->signature . "'");
        }
        $this->gates[$g->signature] = $g;
        $this->n++;
    }

    /** a fresh copy of an event, fresh at every depth; one memo per owner */
    private function copy(object $e, array &$memo): object
    {
        $id = spl_object_id($e);
        if (isset($memo[$id])) {
            return $memo[$id];
        }
        $cls = static::FROZEN;
        $c = new $cls($e->type, $this->copyData($e->data, $memo));
        $memo[$id] = $c;
        return $c;
    }

    private function copyData(array $d, array &$memo): array
    {
        $out = [];
        foreach ($d as $k => $v) {
            if (is_array($v)) {
                $out[$k] = $this->copyData($v, $memo);
            } elseif (self::isEvent($v)) {
                $out[$k] = $this->copy($v, $memo);
            } else {
                $out[$k] = $v;
            }
        }
        return $out;
    }

    private function own(array $data, array &$memo): array
    {
        if (self::on('shareFrozen')) {
            return $data;
        }
        return $this->copyData($data, $memo);
    }

    /** an event a gate returned, made read-only when it was built writable (writableAtBirth) */
    private function freezeOut(object $o): object
    {
        if ($o instanceof LooseEvent && static::FROZEN !== LooseEvent::class) {
            $cls = static::FROZEN;
            return new $cls($o->type, $o->data);
        }
        return $o;
    }

    /** a value's public fields at every depth, as arrays (THE REJECTION) */
    private function plain(mixed $v, array &$memo, array &$onPath): mixed
    {
        if (is_array($v)) {
            $out = [];
            foreach ($v as $k => $x) {
                $out[$k] = $this->plain($x, $memo, $onPath);
            }
            return $out;
        }
        if (!is_object($v) || $v instanceof \UnitEnum) {
            return $v;
        }
        if (self::isEvent($v)) {
            return $this->copy($v, $memo);
        }
        $id = spl_object_id($v);
        if (isset($onPath[$id])) {
            return null;
        }
        $onPath[$id] = true;
        $out = $this->plain(get_object_vars($v), $memo, $onPath);
        unset($onPath[$id]);
        return $out;
    }

    private function rejectionFor(Rejection $rej): mixed
    {
        if (self::on('sharedRejection')) {
            return $rej;
        }
        if (self::on('richRejection')) {
            return new Rejection($rej->code, $rej->message, $rej->eventId, $rej->causeId, $rej->eventType, $rej->depth, $rej->cause);
        }
        $fields = array_filter(get_object_vars($rej), static fn ($x): bool => $x !== null);
        if (self::on('shallowRejection')) {
            return $fields;
        }
        $memo = [];
        $onPath = [];
        return $this->plain($fields, $memo, $onPath);
    }

    private function batchFor(string $outcome, ?Rejection $rej, array $recs): array
    {
        $memo = [];
        $copy = [];
        foreach ($recs as $r) {
            $r['data'] = $this->own($r['data'], $memo);
            $copy[] = $r;
        }
        return ['outcome' => $outcome, 'rejection' => $rej === null ? null : $this->rejectionFor($rej), 'records' => $copy];
    }

    private static function call(\Closure $o, array &$batch): void
    {
        if (self::on('unprotected')) {
            $o($batch);
            return;
        }
        try {
            $o($batch);
        } catch (\Throwable) {
        }
    }

    private function notify(string $outcome, ?Rejection $rej, array $recs): void
    {
        $list = $this->observers;
        if (self::on('sharedBatch')) {
            $shared = $this->batchFor($outcome, $rej, $recs);
            foreach ($list as $o) {
                self::call($o, $shared);
            }
            return;
        }
        $batches = [];
        foreach ($list as $i => $_) {
            try {
                $batches[$i] = $this->batchFor($outcome, $rej, $recs);
            } catch (\Throwable) {
            }
        }
        foreach ($list as $i => $o) {
            if (array_key_exists($i, $batches)) {
                self::call($o, $batches[$i]);
            }
        }
    }

    public function emit(mixed $event): void
    {
        $recs = [];
        $fail = function (Rejection $rej) use (&$recs): void {
            if (!self::on('observeDuring')) {
                $this->notify('rejected', $rej, $recs);
            }
            throw $rej;
        };
        if (!self::on('acceptAnyEntry') && !self::isEvent($event)) {
            $fail(new Rejection('SISO_NOT_EVENT', 'Not an Event: the entry door was given ' . self::describe($event)));
        }
        $base = self::on('countIds') ? $this->count : 0;
        if (!self::isEvent($event)) {
            $entered = is_object($event) ? $event : (object)[
                'type' => is_array($event) ? (string)($event['type'] ?? '') : '',
                'data' => is_array($event) ? ($event['data'] ?? []) : [],
            ];
        } elseif (self::on('entryAlias')) {
            $entered = $event;
        } else {
            $entered = null;
            try {
                $memo = [];
                $entered = $this->copy($event, $memo);
            } catch (\Throwable) {
                $fail(new Rejection('SISO_NOT_EVENT', 'Not an Event: the entry door was given an Event too deep to copy'));
            }
        }
        $queue = [['event' => $entered, 'causeId' => null, 'depth' => 1]];
        $settled = [];
        $head = 0;
        $id = 0;
        while ($head < count($queue)) {
            $item = $queue[$head];
            $head++;
            $cur = $item['event'];
            $id++;
            $eventId = $base + $id;
            if (self::on('countIds')) {
                $this->count = $eventId;
            }
            $type = $cur->type;
            $g = $this->gates[$type] ?? null;
            $rec = ['eventId' => $eventId, 'causeId' => $item['causeId'], 'eventType' => $type, 'claimed' => $g !== null, 'data' => $cur->data];
            $recs[] = $rec;
            if (self::on('observeDuring')) {
                $this->notify('settled', null, [$rec]);
            }
            if ($g === null) {
                $settled[] = $cur;
                continue;
            }
            $at = [$eventId, $item['causeId'], $type];
            if ($item['depth'] > $this->n) {
                $fail(new Rejection('SISO_CYCLE', "Cycle: '" . $type . "' claimed at depth " . $item['depth'] . ' with ' . $this->n
                    . ' gates registered — a type repeated on this causal chain', ...$at, depth: $item['depth']));
            }
            $res = null;
            try {
                $res = ($g->transform)($cur);
            } catch (\Throwable $t) {
                $fail(new Rejection('SISO_GATE_THREW', "Gate '" . $g->signature . "' threw: " . $t->getMessage(), ...$at, cause: $t));
            }
            // what the gate returned is released inside the stream's
            // protection (core-api SUBSTRATE, Release): a destructor or a
            // generator's finally that throws on release changes nothing
            try {
                $outs = $this->judge($res, $g->signature, $at, $fail);
            } catch (Rejection $r) {
                try {
                    $res = null;
                } catch (\Throwable) {
                }
                throw $r;
            }
            try {
                $res = null;
            } catch (\Throwable) {
            }
            foreach ($outs as $o) {
                $queue[] = ['event' => $this->freezeOut($o), 'causeId' => $eventId, 'depth' => $item['depth'] + 1];
            }
        }
        foreach ($settled as $e) {
            $this->residue[] = $e;
        }
        if (!self::on('observeDuring')) {
            $this->notify('settled', null, $recs);
        }
    }

    private function judge(mixed $res, string $sig, array $at, \Closure $fail): array
    {
        $notEvent = static fn (string $what): Rejection => new Rejection('SISO_NOT_EVENT',
            "Not an Event: gate '" . $sig . "' returned " . $what . ' (a gate returns null, an Event or a list of Events)', ...$at);
        $selfLoop = static fn (): Rejection => new Rejection('SISO_SELF_LOOP', "Self-loop: gate '" . $sig . "' returned its own type", ...$at);
        if ($res === null) {
            return [];
        }
        if (self::isEvent($res)) {
            if ($res->type === $sig) {
                $fail($selfLoop());
            }
            return [$res];
        }
        if (!is_array($res)) {
            $fail($notEvent(self::describe($res)));
        }
        if (self::on('skipNonEvents')) {
            $outs = array_values(array_filter($res, static fn ($m): bool => self::isEvent($m)));
            foreach ($outs as $m) {
                if ($m->type === $sig) {
                    $fail($selfLoop());
                }
            }
            return $outs;
        }
        $n = 0;
        while (array_key_exists($n, $res)) {
            $m = $res[$n];
            $n++;
            if (!self::isEvent($m)) {
                $fail($notEvent('a list whose member ' . $n . ' is ' . self::describe($m)));
            }
            if ($m->type === $sig) {
                $fail($selfLoop());
            }
        }
        if (count($res) !== $n) {
            $fail($notEvent('an array whose keys are not exactly 0..' . ($n - 1) . ' (a hole or a named key)'));
        }
        return $res;
    }

    public function sampleHere(): array
    {
        if (self::on('shareFrozen')) {
            $copy = $this->residue;
        } else {
            $memo = [];
            $copy = [];
            foreach ($this->residue as $e) {
                $copy[] = $this->copy($e, $memo);
            }
        }
        $s = ['residue' => $copy, 'gateCount' => $this->n];
        if (self::on('sampleCount')) {
            $s['eventCount'] = $this->count;
        }
        return $s;
    }

    public function takeResidue(): array
    {
        $t = $this->residue;
        $this->residue = [];
        return $t;
    }

    public function attach(mixed $o): void
    {
        if (!$o instanceof \Closure) {
            throw new \LogicException('attach: an observer is a function, got ' . get_debug_type($o));
        }
        foreach ($this->observers as $x) {
            if ($x === $o) {
                throw new \LogicException('attach: this observer is already attached');
            }
        }
        $this->observers[] = $o;
    }

    public function detach(mixed $o): void
    {
        foreach ($this->observers as $i => $x) {
            if ($x === $o) {
                array_splice($this->observers, $i, 1);
                return;
            }
        }
        throw new \LogicException('detach: this observer is not attached');
    }
}

final class Stream extends StreamBase
{
}

final class SkipNonEventsStream extends StreamBase
{
    public const OPTS = ['skipNonEvents' => true];
}

final class AcceptAnyEntryStream extends StreamBase
{
    public const OPTS = ['acceptAnyEntry' => true];
}

final class CountIdsStream extends StreamBase
{
    public const OPTS = ['countIds' => true];
}

final class SampleCountStream extends StreamBase
{
    public const OPTS = ['sampleCount' => true];
}

final class NoFreezeStream extends StreamBase
{
    public const FROZEN = LooseEvent::class;
}

final class ObserveDuringStream extends StreamBase
{
    public const OPTS = ['observeDuring' => true];
}

final class UnprotectedStream extends StreamBase
{
    public const OPTS = ['unprotected' => true];
}

final class SharedBatchStream extends StreamBase
{
    public const OPTS = ['sharedBatch' => true];
}

final class ShareFrozenStream extends StreamBase
{
    public const OPTS = ['shareFrozen' => true];
}

/** events arrive writable (LooseEvent); the stream's copies and returns become Event */
final class WritableAtBirthStream extends StreamBase
{
}

final class SharedRejectionStream extends StreamBase
{
    public const OPTS = ['sharedRejection' => true];
}

final class TableMetaStream extends StreamBase
{
    public const FROZEN = StaticEvent::class;
}

final class EntryAliasStream extends StreamBase
{
    public const OPTS = ['entryAlias' => true];
}

final class RichRejectionStream extends StreamBase
{
    public const OPTS = ['richRejection' => true];
}

final class ShallowRejectionStream extends StreamBase
{
    public const OPTS = ['shallowRejection' => true];
}

final class ObjectDataStream extends StreamBase
{
    public const FROZEN = ObjectDataEvent::class;
}

final class KeepReferencesStream extends StreamBase
{
    public const FROZEN = RefEvent::class;
}

final class ForgeableStream extends StreamBase
{
    public const FROZEN = ForgeableEvent::class;
}

final class Fake
{
    /** switch => [Event class, Stream class]; '' is the honest core */
    public const SWITCHES = [
        '' => [Event::class, Stream::class],
        'skipNonEvents' => [Event::class, SkipNonEventsStream::class],
        'acceptAnyEntry' => [Event::class, AcceptAnyEntryStream::class],
        'countIds' => [Event::class, CountIdsStream::class],
        'sampleCount' => [Event::class, SampleCountStream::class],
        'noFreeze' => [LooseEvent::class, NoFreezeStream::class],
        'observeDuring' => [Event::class, ObserveDuringStream::class],
        'unprotected' => [Event::class, UnprotectedStream::class],
        'sharedBatch' => [Event::class, SharedBatchStream::class],
        'shareFrozen' => [Event::class, ShareFrozenStream::class],
        'writableAtBirth' => [LooseEvent::class, WritableAtBirthStream::class],
        'sharedRejection' => [Event::class, SharedRejectionStream::class],
        'tableMeta' => [StaticEvent::class, TableMetaStream::class],
        'entryAlias' => [Event::class, EntryAliasStream::class],
        'richRejection' => [Event::class, RichRejectionStream::class],
        'shallowRejection' => [Event::class, ShallowRejectionStream::class],
        'objectData' => [ObjectDataEvent::class, ObjectDataStream::class],
        'keepReferences' => [RefEvent::class, KeepReferencesStream::class],
        'forgeable' => [ForgeableEvent::class, ForgeableStream::class],
    ];

    private function __construct()
    {
    }

    /** @return array{Event: class-string, Gate: class-string, Stream: class-string} */
    public static function core(string $switch = ''): array
    {
        if (!isset(self::SWITCHES[$switch])) {
            throw new \InvalidArgumentException("contract-fake-core: no switch '" . $switch . "'");
        }
        [$event, $stream] = self::SWITCHES[$switch];
        return ['Event' => $event, 'Gate' => Gate::class, 'Stream' => $stream];
    }
}
