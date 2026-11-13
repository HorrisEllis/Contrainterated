<?php
/*
contract: observer-seam — what the stream shows an observer (core 13,
PHP instance). Port of lua/code/contracts/observer_seam.lua.

Between: stream -> observer. Only a contracts phase changes this file.

THE DOOR
  $stream->attach($observer) and $stream->detach($observer) (core-api).
  An observer is a \Closure (`f(...)` or \Closure::fromCallable turns any
  callable into one), compared by identity. Attaching anything else
  throws \LogicException("attach: an observer is a function, got
  <get_debug_type>"); attaching one that is attached throws
  \LogicException("attach: this observer is already attached"); detaching
  one that is not throws \LogicException("detach: this observer is not
  attached"). Attaching or detaching changes nothing the stream does: not
  the residue, not a rejection, not what a gate receives.

ONE CALL PER PROPAGATION, AFTER IT HAS ENDED
  The stream calls each observer attached when the entry arrives once
  per propagation, as `$observer($batch)`, when the propagation is over:
    settled   after its residue is committed;
    rejected  after nothing was committed, just before the rejection is
              thrown.
  No observer runs while a propagation is in progress, so an observer
  that calls a door (emit, register, takeResidue, attach, …) makes the
  next crossing; it cannot touch the propagation it is shown.
  A refused entry (SISO_NOT_EVENT at the entry door) is a propagation
  with no deliveries: its batch has no records.

THE BATCH
  [
    'outcome'   => 'settled' | 'rejected',
    'rejection' => the observer's own copy of the rejection emit throws
                   (rejected only, else null; see THE REJECTION),
    'records'   => [record, …]   the deliveries, in delivery order (a list),
  ]
  exactly those keys. Each observer receives its own batch: an array, a
  value, so nothing one observer does to its batch (by-reference
  parameter `function (array &$b)` included) reaches what another is
  shown.

THE REJECTION (core 13; 12 handed every observer the raised object itself)
  A plain array copy of the rejection emit throws, the observer's own
  (core 13.0.0): a key for each public field the rejection carries
  (non-null: code and message always; eventId, causeId, eventType when
  there is a delivery; depth; cause) with equal values. A `cause` that is
  an object is copied as an array of its public properties, as foreach
  from outside its class yields them, at every depth (arrays copied,
  Events copied fresh, enum cases kept, other objects copied the same
  way; an object met again inside its own copy, a cycle, becomes null):
  a \Throwable's protected message and trace are not carried; the
  rejection's `message` carries the rendering. No object in the copy is
  one the host's rejection or what the gate threw holds, and nothing in it
  is an object but a fresh Event or an enum case. Writing to it changes
  only that observer's copy (an array is a value): nothing reaches the
  rejection the entering code receives, what the gate threw, or another
  observer's copy (core-api OWNERSHIP). Read its `message`.

A RECORD
  ['eventId' => int, 'causeId' => ?int, 'eventType' => string,
   'claimed' => bool, 'data' => array], exactly those keys:
    eventId    1, 2, … in delivery order (core-api IDS), contiguous
    causeId    the eventId of the delivery whose gate returned this event;
               null for the entry delivery
    eventType  the event's type
    claimed    true when a gate claimed the event, false when it settled
               as residue
    data       the event's data as the gate received it, the observer's
               own: arrays are values, and every Event in it is a fresh
               copy for that observer (one memo per observer), so nothing
               done to it reaches the stream's residue or another
               observer (core-api OWNERSHIP)
  In a rejected propagation the records end with the delivery the
  rejection names: the one whose gate returned its own type or a
  non-Event (SISO_SELF_LOOP, SISO_NOT_EVENT), the one claimed too deep
  (SISO_CYCLE), or the one whose gate threw (SISO_GATE_THREW). A returned
  event that caused the rejection is never delivered, so it has no
  record.

ISOLATION
  An observer that throws (any \Throwable, a TypeError from its own
  parameter types included) is the observer's own failure. The stream
  calls observers in protected mode: emit still returns or throws exactly
  as it would with no observer, and every other observer still receives
  its batch. Building an observer's batch (its copies included) is part
  of that observer's call: if it fails, that observer fails alone. Every
  observer's batch is built before any observer is called, so nothing an
  observer does (taking the residue included) reaches what another
  observer is shown. The order in which observers are called carries no
  meaning.

What an observer stores, numbers or labels is its own (observer-side
state, Lua R2). The stream holds no stream id, lineage or level.

SUBSTRATE (PHP)
  - Lua's read-only batch, record data and rejection copy become value
    ownership: arrays are copied on write, so an observer's assignment
    reaches nothing else by the language. Lua's "accepts assignment"
    probes vanish; in their place the checks write (through a
    by-reference parameter too) and show that nothing else changed.
  - Lua's `rawset` on a copy becomes a \WeakMap stamp on the objects in
    it (Events) and a Reflection write; fresh copies mean neither reaches
    anything else.
  - Lua's "getmetatable answers false or a string" on the rejection copy
    becomes "no object in the copy but fresh Events and enum cases": an
    object would carry methods, a class and possibly writable properties
    of the host's. The check walks the copy for objects.
  - What Lua's metatable tamper probe reaches here: public properties of
    any object reachable from the copy, and public static properties of
    their classes. The ownership check writes both and shows the host's
    rejection, what the gate threw, and a later stream's rejection
    unchanged.
  - Since an observer is a \Closure, a by-reference parameter is the one
    way the call itself lets an observer name the stream's own variable:
    the stream gives each observer its own batch variable. PHP's debug
    facilities reach further: `debug_backtrace()` shows the Stream (emit's
    `$this`), and Reflection or a closure bound into its class reads its
    private state. They are convention, as Lua's debug library is
    (core-api SUBSTRATE).

ObserverSeam::rejectionCopyProblem($copy, $raised, $eventClass) judges one
observer's rejection against the one thrown (any runner may use it).
ObserverSeam::checkSeam($core) and ::checkOwnership($core) run this
contract against `$core = ['Event' => …, 'Gate' => …, 'Stream' => …]`
(class strings) and return a list of problems.
*/
declare(strict_types=1);

namespace Siso\Contracts;

require_once __DIR__ . '/core_api.php';

/** What the probes' gates throw: an error object with public fields of its own. */
final class SeamProbeError extends \RuntimeException
{
    public string $first = "the gate's own";
    public array $nested = [1];
    public string $msg = 'm';
    public string $kind = 'io';
}

final class ObserverSeam
{
    public const BATCH_FIELDS = ['outcome', 'rejection', 'records'];
    public const RECORD_FIELDS = ['eventId', 'causeId', 'eventType', 'claimed', 'data'];

    private function __construct()
    {
    }

    /** keys of $a not in $allowed, and keys of $allowed missing from $a */
    private static function keyProblems(array $a, array $allowed, string $where): array
    {
        $p = [];
        foreach ($a as $k => $_) {
            if (!in_array($k, $allowed, true)) {
                $p[] = $where . ": unexpected field '" . $k . "'";
            }
        }
        foreach ($allowed as $k) {
            if (!array_key_exists($k, $a)) {
                $p[] = $where . ": missing field '" . $k . "'";
            }
        }
        return $p;
    }

    /*
    batchProblems($batch) → list of problems: the batch's own shape, and
    the records' ids and causes consistent with one propagation.
    */
    public static function batchProblems(mixed $batch): array
    {
        if (!is_array($batch)) {
            return ['a batch is an array, got ' . get_debug_type($batch)];
        }
        $p = self::keyProblems($batch, self::BATCH_FIELDS, 'batch');
        $outcome = $batch['outcome'] ?? null;
        $rejection = $batch['rejection'] ?? null;
        if ($outcome !== 'settled' && $outcome !== 'rejected') {
            $p[] = 'batch: outcome is ' . CoreApi::show($outcome);
        }
        if ($outcome === 'settled' && $rejection !== null) {
            $p[] = 'batch: a settled batch carries a rejection';
        }
        if ($outcome === 'rejected' && !is_array($rejection)) {
            $p[] = 'batch: a rejected batch carries no rejection';
        }
        $records = $batch['records'] ?? null;
        if (!is_array($records)) {
            $p[] = 'batch: records is not a list';
            return $p;
        }
        if (!array_is_list($records)) {
            $p[] = 'batch: records is not a list (holes or named keys)';
            $records = array_values($records);
        }
        foreach ($records as $i => $r) {
            $n = $i + 1;
            $where = 'record ' . $n;
            if (!is_array($r)) {
                $p[] = $where . ': not an array';
                continue;
            }
            foreach (self::keyProblems($r, self::RECORD_FIELDS, $where) as $x) {
                $p[] = $x;
            }
            $id = $r['eventId'] ?? null;
            if ($id !== $n) {
                $p[] = $where . ': eventId is ' . CoreApi::show($id) . ', expected ' . $n;
            }
            $cause = $r['causeId'] ?? null;
            if ($n === 1) {
                if ($cause !== null) {
                    $p[] = $where . ': the entry delivery has a cause';
                }
            } elseif (!is_int($cause) || $cause < 1 || $cause >= $n) {
                $p[] = $where . ': causeId ' . CoreApi::show($cause) . ' is not an earlier delivery';
            } elseif (($records[$cause - 1]['claimed'] ?? null) !== true) {
                $p[] = $where . ': its cause was not claimed by a gate';
            }
            if (!is_string($r['eventType'] ?? null)) {
                $p[] = $where . ': eventType is not a string';
            }
            if (!is_bool($r['claimed'] ?? null)) {
                $p[] = $where . ': claimed is not a bool';
            }
            if (!is_array($r['data'] ?? null)) {
                $p[] = $where . ': data is not an array';
            }
        }
        if ($outcome === 'rejected' && is_array($rejection)) {
            $last = $records === [] ? null : $records[count($records) - 1];
            if (($rejection['eventId'] ?? null) === null) {
                if ($records !== []) {
                    $p[] = 'batch: a refused entry has records';
                }
            } elseif (!is_array($last) || ($last['eventId'] ?? null) !== $rejection['eventId']) {
                $p[] = 'batch: the records do not end with the delivery the rejection names';
            }
        }
        return $p;
    }

    /** the plain view of a value: an object (not an enum case) as its public properties */
    private static function view(mixed $v): mixed
    {
        if (is_object($v) && !$v instanceof \UnitEnum) {
            return get_object_vars($v);
        }
        return $v;
    }

    // a and b equal by value: objects through their public properties,
    // arrays by keys both ways, anything else by ===
    private static function same(mixed $a, mixed $b, int $depth = 0): bool
    {
        if ($a === $b) {
            return true;
        }
        if ($depth > 100) {
            return false;
        }
        $a = self::view($a);
        $b = self::view($b);
        if (!is_array($a) || !is_array($b)) {
            return $a === $b;
        }
        if (count($a) !== count($b)) {
            return false;
        }
        foreach ($a as $k => $x) {
            if (!array_key_exists($k, $b) || !self::same($x, $b[$k], $depth + 1)) {
                return false;
            }
        }
        return true;
    }

    /** ids of every object reachable from $v (arrays and public properties walked) */
    private static function objectsIn(mixed $v, array &$into): void
    {
        if (is_array($v)) {
            foreach ($v as $x) {
                self::objectsIn($x, $into);
            }
            return;
        }
        if (!is_object($v) || $v instanceof \UnitEnum) {
            return;
        }
        $id = spl_object_id($v);
        if (isset($into[$id])) {
            return;
        }
        $into[$id] = true;
        foreach (get_object_vars($v) as $x) {
            self::objectsIn($x, $into);
        }
    }

    /** the first value in $v that is not plain: an object of the raised one's, or any object but a fresh Event or enum case */
    private static function notPlainCopy(mixed $v, array $raisedObjects, ?string $eventClass, string $path, array &$seen): ?string
    {
        if (is_array($v)) {
            foreach ($v as $k => $x) {
                $why = self::notPlainCopy($x, $raisedObjects, $eventClass, $path . '[' . var_export($k, true) . ']', $seen);
                if ($why !== null) {
                    return $why;
                }
            }
            return null;
        }
        if (!is_object($v) || $v instanceof \UnitEnum) {
            return null;
        }
        $id = spl_object_id($v);
        if (isset($raisedObjects[$id])) {
            return $path . ' is the raised object itself, not a copy';
        }
        if (isset($seen[$id])) {
            return null;
        }
        $seen[$id] = true;
        if ($eventClass !== null && $eventClass::is($v)) {
            return self::notPlainCopy($v->data, $raisedObjects, $eventClass, $path . '->data', $seen);
        }
        return $path . ' is an object (' . get_class($v) . '), not plain data';
    }

    /*
    rejectionCopyProblem($copy, $raised, $eventClass) → null when `$copy`
    is an observer's own copy of the rejection `$raised` (THE REJECTION),
    else a string naming what is wrong: the same object, an object of the
    raised one's at any depth, any object but a fresh Event (an instance
    `$eventClass::is` recognises, when given) or an enum case, or a field
    missing, added or unequal (an object `cause` compared by its public
    properties).
    */
    public static function rejectionCopyProblem(mixed $copy, mixed $raised, ?string $eventClass = null): ?string
    {
        if (is_object($copy) && $copy === $raised) {
            return "the batch's rejection is the very object raised, not the observer's copy";
        }
        $raisedObjects = [];
        self::objectsIn($raised, $raisedObjects);
        $seen = [];
        $plain = self::notPlainCopy($copy, $raisedObjects, $eventClass, "the batch's rejection", $seen);
        if ($plain !== null) {
            return $plain;
        }
        if (!is_array($copy)) {
            return "the batch's rejection is not an array, got " . get_debug_type($copy);
        }
        $fields = is_object($raised) ? array_filter(get_object_vars($raised), static fn ($x): bool => $x !== null) : [];
        foreach ($fields as $k => $v) {
            if (!array_key_exists($k, $copy) || !self::same($v, $copy[$k])) {
                return "the batch's rejection differs from the one raised at '" . $k . "'";
            }
        }
        foreach ($copy as $k => $_) {
            if (!array_key_exists($k, $fields)) {
                return "the batch's rejection has a field the raised one lacks: '" . $k . "'";
            }
        }
        return null;
    }

    /** a field of an array or an object, null when absent */
    private static function field(mixed $v, string|int $k): mixed
    {
        if (is_array($v)) {
            return $v[$k] ?? null;
        }
        if (is_object($v) && !$v instanceof \UnitEnum) {
            return get_object_vars($v)[$k] ?? null;
        }
        return null;
    }

    /** every object (not an enum case) reachable from $v, at any depth */
    private static function reachableObjects(mixed $v): array
    {
        $ids = [];
        $out = [];
        $walk = static function (mixed $x) use (&$walk, &$ids, &$out): void {
            if (is_array($x)) {
                foreach ($x as $y) {
                    $walk($y);
                }
                return;
            }
            if (!is_object($x) || $x instanceof \UnitEnum || isset($ids[spl_object_id($x)])) {
                return;
            }
            $ids[spl_object_id($x)] = true;
            $out[] = $x;
            foreach (get_object_vars($x) as $y) {
                $walk($y);
            }
        };
        $walk($v);
        return $out;
    }

    public static function checkSeam(array $core): array
    {
        $p = [];
        foreach (['Event', 'Gate', 'Stream'] as $k) {
            if (!is_string($core[$k] ?? null) || !class_exists($core[$k])) {
                $p[] = "core['" . $k . "'] is not a class";
            }
        }
        if ($p !== []) {
            return $p;
        }
        $Event = $core['Event'];
        $Gate = $core['Gate'];
        $Stream = $core['Stream'];
        $fail = static function (string $s) use (&$p): void {
            $p[] = $s;
        };
        $addAll = static function (string $label, array $list) use ($fail): void {
            foreach ($list as $s) {
                $fail($label . ': ' . $s);
            }
        };
        $recorder = static function (array &$into): \Closure {
            return static function (array $b) use (&$into): void {
                $into[] = $b;
            };
        };
        $types = static function (array $b): string {
            $t = [];
            foreach ($b['records'] ?? [] as $r) {
                $t[] = ($r['eventType'] ?? '?') . (($r['claimed'] ?? false) ? '+' : '-') . '<' . CoreApi::show($r['causeId'] ?? null);
            }
            return implode(' ', $t);
        };

        try {
            // a settled propagation: one batch, after the residue is committed
            $s = new $Stream();
            $got = [];
            $residueSeen = null;
            $s->register(new $Gate('root', static fn () => [new $Event('a'), new $Event('b')]));
            $s->register(new $Gate('a', static fn () => new $Event('a1')));
            $s->attach(static function (array $b) use (&$got, &$residueSeen, $s): void {
                $got[] = $b;
                $residueSeen = count($s->sampleHere()['residue']);
            });
            $s->emit(new $Event('root', ['k' => 1]));
            if (count($got) !== 1) {
                $fail('a settled propagation gave ' . count($got) . ' batches');
            } else {
                $b = $got[0];
                $addAll('settled', self::batchProblems($b));
                if (($b['outcome'] ?? null) !== 'settled') {
                    $fail('settled: outcome ' . CoreApi::show($b['outcome'] ?? null));
                }
                if (count($b['records'] ?? []) !== 4) {
                    $fail('settled: ' . count($b['records'] ?? []) . ' records, expected 4: ' . $types($b));
                }
                if (($b['records'][0]['data']['k'] ?? null) !== 1) {
                    $fail('settled: the entry record does not carry its data');
                }
                if ($residueSeen !== 2) {
                    $fail('settled: the observer ran before the residue was committed');
                }
            }
            // the next propagation on the same stream numbers its own deliveries
            $s->emit(new $Event('root'));
            if (count($got) === 2) {
                $addAll('second propagation', self::batchProblems($got[1]));
            }

            // a rejected propagation: the batch carries the observer's own
            // copy of the rejection thrown (THE REJECTION)
            $r = new $Stream();
            $rb = [];
            $r->register(new $Gate('a', static fn () => new $Event('b')));
            $r->register(new $Gate('b', static function () {
                throw new \RuntimeException('boom');
            }));
            $r->attach($recorder($rb));
            $raised = null;
            try {
                $r->emit(new $Event('a'));
                $fail("rejected: the gate's throw did not reject");
            } catch (\Throwable $t) {
                $raised = $t;
            }
            if (count($rb) !== 1) {
                $fail('rejected: ' . count($rb) . ' batches');
            } else {
                $addAll('rejected', self::batchProblems($rb[0]));
                $why = self::rejectionCopyProblem($rb[0]['rejection'] ?? null, $raised, $Event);
                if ($why !== null) {
                    $fail('rejected: ' . $why);
                }
                if (count($rb[0]['records'] ?? []) !== 2) {
                    $fail('rejected: ' . count($rb[0]['records'] ?? []) . ' records, expected 2');
                }
            }

            // a refused entry: a batch with no records
            $e = new $Stream();
            $eb = [];
            $e->attach($recorder($eb));
            try {
                $e->emit('not an event');
            } catch (\Throwable) {
            }
            if (count($eb) !== 1 || ($eb[0]['records'] ?? null) !== [] || ($eb[0]['outcome'] ?? null) !== 'rejected') {
                $fail('refused entry: expected one rejected batch with no records');
            }

            // an observer that throws changes nothing, and others still see the batch
            $t = new $Stream();
            $tb = [];
            $t->register(new $Gate('x', static fn () => new $Event('y')));
            $t->attach(static function (): void {
                throw new \RuntimeException('observer failure');
            });
            $t->attach(static function (): void {
                throw new \Error('observer error');
            });
            $t->attach(static function (int $wrongType): void {
            });
            $t->attach($recorder($tb));
            try {
                $t->emit(new $Event('x'));
            } catch (\Throwable $errT) {
                $fail('a raising observer made emit throw: ' . $errT->getMessage());
            }
            if (count($t->sampleHere()['residue']) !== 1) {
                $fail('a raising observer changed the residue');
            }
            if (count($tb) !== 1) {
                $fail('a raising observer kept another from its batch');
            }

            // an observer that calls doors makes the next crossing; the
            // propagation it saw is untouched
            $u = new $Stream();
            $fired = false;
            $u->register(new $Gate('a', static fn () => new $Event('b')));
            $u->attach(static function () use (&$fired, $u, $Event, $Gate): void {
                if ($fired) {
                    return;
                }
                $fired = true;
                $u->register(new $Gate('b', static fn () => new $Event('late')));
                $u->emit(new $Event('other'));
            });
            $u->emit(new $Event('a'));
            $types2 = array_map(static fn ($ev): string => (string)$ev->type, $u->takeResidue());
            sort($types2, SORT_STRING);
            if (implode(',', $types2) !== 'b,other') {
                $fail("an observer's crossings reached the propagation it was shown: residue " . implode(',', $types2));
            }

            // each observer gets its own batch, a by-reference parameter included
            $v = new $Stream();
            $seen2 = null;
            $v->attach(static function (array &$b): void {
                array_shift($b['records']);
                $b['outcome'] = 'x';
            });
            $v->attach(static function (array $b) use (&$seen2): void {
                $seen2 = $b;
            });
            $v->emit(new $Event('q'));
            if (!is_array($seen2) || ($seen2['outcome'] ?? null) !== 'settled' || count($seen2['records'] ?? []) !== 1) {
                $fail("one observer's writes to its batch reached another observer");
            }

            // detach: no more batches; misuse throws
            $w = new $Stream();
            $wb = [];
            $f = $recorder($wb);
            $misuse = static function (string $label, callable $fn, string $message) use ($fail): void {
                try {
                    $fn();
                    $fail($label . ' did not throw');
                } catch (\LogicException $err) {
                    if ($err->getMessage() !== $message) {
                        $fail($label . ': message "' . $err->getMessage() . '", not "' . $message . '"');
                    }
                } catch (\Throwable $err) {
                    $fail($label . ': ' . get_class($err) . ', not a \LogicException');
                }
            };
            $misuse('attaching a value that is not a \Closure', static fn () => $w->attach('strlen'), 'attach: an observer is a function, got string');
            $w->attach($f);
            $misuse('attaching an attached observer', static fn () => $w->attach($f), 'attach: this observer is already attached');
            $w->detach($f);
            $w->emit(new $Event('z'));
            if (count($wb) !== 0) {
                $fail('a detached observer was called');
            }
            $misuse('detaching an observer that is not attached', static fn () => $w->detach($f), 'detach: this observer is not attached');

            // attaching changes nothing: same entry, same residue and rejection
            $run = static function (bool $withObserver) use ($Event, $Gate, $Stream): string {
                $x = new $Stream();
                $x->register(new $Gate('a', static fn () => [new $Event('b'), new $Event('c')]));
                $x->register(new $Gate('b', static fn () => new $Event('a')));
                if ($withObserver) {
                    $x->attach(static function (): void {
                    });
                }
                try {
                    $x->emit(new $Event('a'));
                    return 'settled';
                } catch (\Throwable $rr) {
                    $f = get_object_vars($rr);
                    return CoreApi::show($f['code'] ?? null) . '@' . CoreApi::show($f['eventId'] ?? null) . '/' . CoreApi::show($f['depth'] ?? null);
                }
            };
            if ($run(false) !== $run(true)) {
                $fail('attaching an observer changed a rejection');
            }
        } catch (\Throwable $err) {
            $fail('raised ' . get_class($err) . ': ' . $err->getMessage());
        }
        return $p;
    }

    public static function checkOwnership(array $core): array
    {
        $p = [];
        foreach (['Event', 'Gate', 'Stream'] as $k) {
            if (!is_string($core[$k] ?? null) || !class_exists($core[$k])) {
                $p[] = "core['" . $k . "'] is not a class";
            }
        }
        if ($p !== []) {
            return $p;
        }
        $Event = $core['Event'];
        $Gate = $core['Gate'];
        $Stream = $core['Stream'];
        $fail = static function (string $s) use (&$p): void {
            $p[] = $s;
        };
        try {
            // both attach orders, since the order observers are called in is free
            foreach ([true, false] as $writerFirst) {
                $s = new $Stream();
                $seen = null;
                $stamps = new \WeakMap();
                $writer = static function (array &$b) use ($stamps): void {
                    $d = &$b['records'][0]['data'];
                    $d['v'] = 99;
                    $d['n'][0] = 9;
                    if (is_object($d['e'] ?? null)) {
                        $stamps[$d['e']] = 'OBSERVER WROTE';
                        CoreApi::reflectWrite($d['e'], 'data', ['w' => 9]);
                    }
                };
                $reader = static function (array $b) use (&$seen): void {
                    $seen = $b['records'][0]['data'] ?? null;
                };
                if ($writerFirst) {
                    $s->attach($writer);
                    $s->attach($reader);
                } else {
                    $s->attach($reader);
                    $s->attach($writer);
                }
                $s->emit(new $Event('x', ['v' => 1, 'n' => [1], 'e' => new $Event('in', ['w' => 1])]));
                if (($seen['v'] ?? null) !== 1 || ($seen['n'][0] ?? null) !== 1) {
                    $fail("an observer's write reached another observer's record");
                }
                $seenE = $seen['e'] ?? null;
                if (!is_object($seenE) || isset($stamps[$seenE]) || ($seenE->data['w'] ?? null) !== 1) {
                    $fail("an observer's stamp on an event in its record reached another observer's record");
                }
                $r = $s->takeResidue()[0];
                if (($r->data['v'] ?? null) !== 1 || ($r->data['n'][0] ?? null) !== 1) {
                    $fail("an observer's write reached the residue");
                }
                $rE = $r->data['e'] ?? null;
                if (!is_object($rE) || isset($stamps[$rE]) || ($rE->data['w'] ?? null) !== 1) {
                    $fail("an observer's stamp on an event in its record reached the residue");
                }
            }

            // the rejection (THE REJECTION, core 13): each observer's copy
            // is its own; nothing done to it reaches the thrown one or
            // another observer
            $poke = static function (mixed $o): void {
                if (is_object($o) && !$o instanceof \UnitEnum) {
                    CoreApi::attempt(static function () use ($o): void {
                        $o->first = 'CHANGED';
                    });
                    CoreApi::attempt(static function () use ($o): void {
                        $o->nested[0] = 9;
                    });
                }
            };
            foreach ([true, false] as $writerFirst) {
                $s = new $Stream();
                $thrown = new SeamProbeError("the gate's own");
                $s->register(new $Gate('a', static function () use ($thrown) {
                    throw $thrown;
                }));
                $seen = null;
                $writer = static function (array &$b) use ($poke): void {
                    $r = &$b['rejection'];
                    if (is_array($r)) {
                        $r['code'] = 'CHANGED';
                        $r['eventId'] = 99;
                        if (is_array($r['cause'] ?? null)) {
                            $r['cause']['first'] = 'CHANGED';
                            $r['cause']['nested'][0] = 9;
                        } else {
                            $poke($r['cause'] ?? null);
                        }
                    } elseif (is_object($r)) {
                        CoreApi::attempt(static function () use ($r): void {
                            $r->code = 'CHANGED';
                            $r->eventId = 99;
                        });
                        $poke(self::field($r, 'cause'));
                    }
                };
                $reader = static function (array $b) use (&$seen): void {
                    $seen = $b['rejection'] ?? null;
                };
                if ($writerFirst) {
                    $s->attach($writer);
                    $s->attach($reader);
                } else {
                    $s->attach($reader);
                    $s->attach($writer);
                }
                $raised = null;
                try {
                    $s->emit(new $Event('a'));
                } catch (\Throwable $t) {
                    $raised = $t;
                }
                if ($raised === null) {
                    $fail("rejection ownership: the gate's throw did not reject");
                    continue;
                }
                if (self::field($raised, 'code') !== 'SISO_GATE_THREW' || self::field($raised, 'eventId') !== 1) {
                    $fail("an observer's write reached the rejection emit raised");
                }
                if (self::field($raised, 'cause') !== $thrown || $thrown->first !== "the gate's own" || $thrown->nested[0] !== 1) {
                    $fail("an observer's write reached what the gate raised");
                }
                $cause = self::field($seen, 'cause');
                if (self::field($seen, 'code') !== 'SISO_GATE_THREW' || self::field($seen, 'eventId') !== 1
                    || self::field($cause, 'first') !== "the gate's own" || (self::field($cause, 'nested')[0] ?? null) !== 1) {
                    $fail("an observer's write on its rejection reached another observer's");
                }
            }

            // observers are shown batches built before any is called: one
            // that takes the residue and writes to it reaches no other (both orders)
            foreach ([true, false] as $takerFirst) {
                $s = new $Stream();
                $seenV = null;
                $seenE = null;
                $stamps = new \WeakMap();
                $taker = static function () use (&$s, $stamps): void {
                    foreach ($s->takeResidue() as $e) {
                        CoreApi::reflectWrite($e, 'data', ['v' => 'TAKER WROTE']);
                        $stamps[$e] = 'TAKER WROTE';
                        if (is_object($e->data['e'] ?? null)) {
                            $stamps[$e->data['e']] = 'TAKER WROTE';
                        }
                    }
                };
                $reader = static function (array $b) use (&$seenV, &$seenE): void {
                    $seenV = $b['records'][0]['data']['v'] ?? null;
                    $seenE = $b['records'][0]['data']['e'] ?? null;
                };
                if ($takerFirst) {
                    $s->attach($taker);
                    $s->attach($reader);
                } else {
                    $s->attach($reader);
                    $s->attach($taker);
                }
                $s->emit(new $Event('x', ['v' => 1, 'e' => new $Event('in')]));
                if ($seenV !== 1 || !is_object($seenE) || isset($stamps[$seenE])) {
                    $fail('an observer that took the residue and wrote to it reached another observer\'s records ('
                        . ($takerFirst ? 'taker first' : 'reader first') . ')');
                }
            }

            // nothing the core holds is reachable from an observer's
            // rejection (core 13.0.0): an observer that writes to every
            // object it can reach from its copy, and to its classes'
            // public statics, changes neither the host's rejection, what
            // the gate threw, nor a later stream's rejection
            $saved = [];
            $tamper = static function (array $b) use (&$saved): void {
                foreach (self::reachableObjects($b['rejection'] ?? null) as $o) {
                    foreach (array_keys(get_object_vars($o)) as $k) {
                        CoreApi::attempt(static function () use ($o, $k): void {
                            $o->$k = 'OBSERVER WROTE';
                        });
                    }
                    $cls = get_class($o);
                    foreach ((new \ReflectionClass($cls))->getProperties(\ReflectionProperty::IS_STATIC) as $prop) {
                        if (!$prop->isPublic()) {
                            continue;
                        }
                        $owner = $prop->getDeclaringClass()->getName();
                        $name = $prop->getName();
                        $saved[] = [$owner, $name, $owner::${$name}];
                        CoreApi::attempt(static function () use ($owner, $name): void {
                            $owner::${$name} = 'OBSERVER WROTE';
                        });
                    }
                }
            };
            try {
                $m = new $Stream();
                $m->register(new $Gate('a', static function () {
                    throw new SeamProbeError('m');
                }));
                $m->attach($tamper);
                $raisedM = null;
                try {
                    $m->emit(new $Event('a'));
                } catch (\Throwable $t) {
                    $raisedM = $t;
                }
                $messageM = self::field($raisedM, 'message');
                $text = null;
                try {
                    $text = (string)$raisedM;
                } catch (\Throwable) {
                }
                if (!is_object($raisedM) || !is_string($messageM) || $text !== $messageM || str_contains($messageM, 'OBSERVER WROTE')
                    || self::field($raisedM, 'code') !== 'SISO_GATE_THREW' || self::field($raisedM, 'eventId') !== 1) {
                    $fail("an observer reached the host's rejection through its copy");
                } else {
                    $causeM = self::field($raisedM, 'cause');
                    if (!$causeM instanceof SeamProbeError || $causeM->kind !== 'io' || $causeM->msg !== 'm' || $causeM->first !== "the gate's own") {
                        $fail('an observer reached what the gate raised through its copy');
                    }
                }
                $later = new $Stream();
                $later->register(new $Gate('a', static function () {
                    throw new \RuntimeException('boom');
                }));
                $raisedL = null;
                try {
                    $later->emit(new $Event('a'));
                } catch (\Throwable $t) {
                    $raisedL = $t;
                }
                $textL = null;
                try {
                    $textL = (string)$raisedL;
                } catch (\Throwable) {
                }
                $messageL = self::field($raisedL, 'message');
                if (!is_object($raisedL) || !is_string($messageL) || $textL !== $messageL || str_contains($messageL, 'OBSERVER WROTE')
                    || self::field($raisedL, 'code') !== 'SISO_GATE_THREW' || CoreApi::rejectionProblems($raisedL) !== []) {
                    $fail("an observer's write through its copy reached a later stream's rejection");
                }
            } finally {
                foreach (array_reverse($saved) as [$owner, $name, $value]) {
                    CoreApi::attempt(static function () use ($owner, $name, $value): void {
                        $owner::${$name} = $value;
                    });
                }
            }
            $m2 = new $Stream();
            $m2->register(new $Gate('a', static function () {
                throw new SeamProbeError('m');
            }));
            $shown = null;
            $m2->attach(static function (array $b) use (&$shown): void {
                $shown = $b['rejection'] ?? null;
            });
            $raised2 = null;
            try {
                $m2->emit(new $Event('a'));
            } catch (\Throwable $t) {
                $raised2 = $t;
            }
            $why = self::rejectionCopyProblem($shown, $raised2, $Event);
            if ($why !== null) {
                $fail('an error object thrown by a gate: ' . $why);
            }
            if (is_array($shown) && is_array($shown['cause'] ?? null) && ($shown['cause']['msg'] ?? null) !== 'm') {
                $fail("an observer's copy lost the cause's own field");
            }
        } catch (\Throwable $err) {
            $fail('raised ' . get_class($err) . ': ' . $err->getMessage());
        }
        return $p;
    }
}
