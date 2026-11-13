<?php
/*
contract: core-api — what the core hands everyone outside it (core 13,
PHP instance). Port of lua/code/contracts/core_api.lua.

Between: stream -> observer, kit, fixtures, and every consuming project.
Only a contracts phase changes this file. It holds what crosses, not how
the stream works.

MODULES (files under php/code/, each require_once'd by path)
  core/event.php   Siso\Core\Event
  core/gate.php    Siso\Core\Gate
  core/stream.php  Siso\Core\Stream
  (core/rejection.php Siso\Core\Rejection and core/freeze.php
  Siso\Core\Freeze are the stream's own; what crosses of a rejection is
  stated under REJECTION.)

EVENT
  new Event(string $type, ?array $data = null) → an event with exactly two
    public properties, `type` (a string) and `data` (an array), both
    `public readonly`; read-only from the moment it is built (core 13):
    assignment to the event, to anything in its data, taking a reference
    into it, adding or unsetting a property raises (\Error), wherever the
    event was built (a gate, a boundary layer, a test). A dynamic
    property is refused by __set/__unset with
      \Error("read-only: an event and its data cannot be assigned (field '<name>')")
    `data` is [] when null. Extra arguments attach nothing. The data is
    taken as it stands when Event is called: arrays are values, and Event
    keeps no PHP reference slot the caller holds (a `&$x` inside the
    array is copied as its value), so a write the caller makes afterwards
    to the array it passed, or through a reference it kept, does not reach
    the event. Events and enum cases inside the data are kept as they
    are, so an event passed on in data keeps its identity.
  Event::is($v) → true only for an Event: `$v instanceof Event` (the
    class is final; an array or object that looks like one is not one).
  Plain data, checked at construction: null, bool, int, float, string,
    array (any keys, any depth), an Event, an enum case (\UnitEnum).
    Anything else (stdClass, a Closure, any other object, a resource) is
    refused:
      \InvalidArgumentException("Event: data at <path> is not plain data (<get_debug_type>)")
    where <path> is the keys from the data's root, each as
    `[` . var_export($key, true) . `]` (data ['a' => [new stdClass]] is
    "Event: data at ['a'][0] is not plain data (stdClass)").
    unserialize() builds an Event only through __unserialize, which
    validates exactly as the constructor does (keys exactly type and
    data): no event whose data is not plain, and no extra property, can
    be made by unserialize.
  STATELESS THROUGH THE EVENT (core 13.0.0): nothing reachable from an
  event holds state a gate could write and later read. Reachable from an
  event are its two readonly properties, arrays (values), other Events,
  enum cases (immutable singletons that can hold no property) and
  scalars. None of Event, Gate, Stream or the rejection class has a
  static property or a method with `static` variables; nothing in the
  core is written after load. (Lua's getmetatable answers the string
  "siso.event"; PHP has no metatable: the counterpart is that the class
  reachable from `$e::class` holds no static state.)
  An event is plain data: `data` holds what kit Data::encode encodes as
  canonical JSON (SISO "polyglot"; the kit checks this, the stream does
  not; an Event or an enum case other than the kit's Json::EmptyArray in
  data is allowed by the core and refused by the kit's encoder).
  READ-ONLY (core 13: from construction): every event Event builds, and
  so every event that enters, every event a gate returns, and every event
  a gate, a sample, a take or an observer is given, is read-only with its
  data at every depth. Reads work through indexing, count(), foreach and
  every array function; reading the same field twice gives the same
  value (===), events by identity. A gate that wants changed data copies
  it (`$d = $e->data; $d['v'] = 2;`), which changes only its own copy.
  IMMUTABLE SENTINELS are enum cases: kept by identity at every depth
  (the kit's Json::EmptyArray is one). The language makes them immutable
  (an enum holds no property), so Lua's trusted "readonly:" mark and its
  core 12 `__readonly` form (Lua R4) do not exist here.
  OWNERSHIP: the stream never holds an object the outside still holds,
  and never hands out one it still holds. Entry takes the stream's own
  copy of the event (core 13.0.0): a fresh Event, and a fresh Event for
  every Event inside its data, at every depth, so nothing the entering
  code holds is in the residue. A take hands the residue over (the
  stream holds none of it afterwards). A sample's residue holds the
  sampler's own fresh copies of the events (fresh at every depth, one
  memo per sample: an event reached twice is copied once), and an
  observer's records carry the observer's own copies of the data
  (observer-seam), so nothing done to them reaches the stream, a later
  sample or another observer. What "done to" means here (the counterpart
  of Lua's `rawset`): a write through Reflection (refused by readonly) and
  a per-object stamp, such as a \WeakMap or \SplObjectStorage entry keyed
  by the object, which any code can attach to any object; fresh
  identities mean such a stamp reaches nothing else. Only inside a
  propagation is an event shared: a gate shares its input with the
  stream, and a stamp a gate keeps on it is gate-held state (convention).

GATE
  new Gate(string $signature, ?callable $transform = null, ?array $shape = null)
    → a Gate with exactly `public readonly string $signature`,
    `public readonly \Closure $transform` (the callable as
    \Closure::fromCallable gives it: a Closure is kept as itself; no
    transform → a no-op returning null) and `public readonly ?array $shape`.
  The stream calls `($gate->transform)($event)` with the event as the only
  argument. `transform` returns:
    null (or no return)  consumed, no follow-up
    an Event             one follow-up
    a list of Events     an array whose keys are exactly 0..n-1
                         (array_is_list; [] is the empty list: consumed)
  Anything else (false, an int, a string, an array with other keys, an
  object that is not an Event, an enum case such as Json::EmptyArray) is a
  rejection (SISO_NOT_EVENT). `shape` is optional, a declaration for
  tooling (['emits' => [types…], 'rejects' => [types…]]); the stream never
  reads it. Event types and gate signatures are strings (PHP array keys):
  a narrowing of Lua's any-value type.

STREAM
  new Stream() → a stream with no gates, no residue, no observers.
  Doors (public methods; nothing else of the stream is public):
    $s->register(Gate $g): void    building. A duplicate signature throws
                                   \LogicException("Signature collision: '<sig>'").
    $s->emit(mixed $event): void   entry. Runs one propagation until it
                                   settles and returns, or throws a
                                   rejection (below). Nothing else is
                                   thrown.
    $s->takeResidue(): array       take. → the residue as a list; the
                                   stream holds none afterwards.
    $s->sampleHere(): array        sample. → ['residue' => <a list of the
                                   sampler's own copies of the residue's
                                   events (OWNERSHIP)>, 'gateCount' =>
                                   <registered gates>]. Nothing else (no
                                   event counter).
    $s->attach(mixed $observer): void   observe. An observer is a
    $s->detach(mixed $observer): void   \Closure; see the observer-seam
                                   contract. Attaching one that is
                                   attached, or detaching one that is not,
                                   throws \LogicException.
  Residue is a multiset: its order is not part of the answer.

IDS
  Each propagation numbers its own deliveries 1, 2, … in delivery order.
  The entry delivery is 1 and has no cause (causeId null). Nothing is
  counted across propagations, and no id survives the propagation except
  in what leaves through a door (a rejection, an observer's batch).

REJECTION
  A rejected propagation commits no residue and leaves the stream as it
  was before the entry. emit throws a Siso\Core\Rejection (final, extends
  \RuntimeException) whose public properties are exactly:
    code       one of the codes below (a string; getCode() answers it)
    message    a string; (string)$r and $r->getMessage() return it
    eventId    the delivery involved (null only for a refused entry)
    causeId    that delivery's cause (null for the entry delivery)
    eventType  that delivery's event type (null only for a refused entry)
    depth      SISO_CYCLE only: the delivery's depth on the causal chain
    cause      SISO_GATE_THREW only: exactly the \Throwable the gate threw
  each null when its code does not carry it, and no other public
  property. Rejection::is($v) is `$v instanceof Rejection`.
  Codes:
    SISO_SELF_LOOP   a gate returned an event of its own type. The
                     delivery is the one whose gate returned it.
    SISO_CYCLE       a claimed delivery sits deeper than the registered
                     gate count. The delivery is that one.
    SISO_GATE_THREW  a gate threw (any \Throwable: Exception or Error).
                     The delivery is the one it was given.
    SISO_NOT_EVENT   emit was given a value that is not an Event, or an
                     Event the stream cannot take its own copy of (no
                     delivery: eventId, causeId and eventType are null),
                     or a gate returned something that is not null, an
                     Event or a list of Events. The delivery is the one
                     whose gate returned it.
  Within one returned array, members are judged in key order 0, 1, …
  while the key exists; the first that is not an Event (SISO_NOT_EVENT)
  or is the gate's own type (SISO_SELF_LOOP) decides; then an array whose
  keys are not exactly 0..n-1 is SISO_NOT_EVENT. So [0 => <own type>,
  'k' => 1] is SISO_SELF_LOOP.
  Messages (core/rejection.php, Lua's templates with PHP's type words):
    "Self-loop: gate '<sig>' returned its own type"
    "Cycle: '<type>' claimed at depth <d> with <n> gates registered — a type repeated on this causal chain"
    "Gate '<sig>' threw: <getMessage()>"
    "Not an Event: the entry door was given <what>"
    "Not an Event: gate '<sig>' returned <what> (a gate returns null, an Event or a list of Events)"
  <what>: null → "null"; bool/int/float → "the bool false", "the int 42",
  "the float 1.5"; a string → "a string"; an array → "an array that is not
  an Event"; an object → "a <ClassName> that is not an Event"; a list
  member → "a list whose member <n> is <what>" (n counted from 1); bad
  keys → "an array whose keys are not exactly 0..<n-1> (a hole or a named
  key)"; an entry too deep to copy → "an Event too deep to copy".

SUBSTRATE (PHP >= 8.1; how each Lua mechanism maps, what vanished, what
remains)
  - Read-only by the language: `public readonly` properties and arrays as
    values replace Lua's frozen proxies. There is no `rawset`: assignment,
    reference-taking, unset and Reflection's setValue on an initialised
    readonly property all raise. Lua R3's rawset clause vanishes; so does
    the freeze's private key (Lua X1's captured-key clause).
  - Data is taken as it stands by copy-on-write plus dereferencing: PHP
    arrays may hold reference slots shared with the caller (`$a['r'] =
    &$x`), and a copy of such an array still shares the slot, so Event
    rebuilds the data without them. Probed below (the PHP counterpart of
    Lua's "a write to the table passed to Event").
  - Plain data is checked at construction (Lua passes functions through
    and freezes any table; a PHP object carries writable state no Lua
    value of plain data can, so refusing it is the thinnest honest form).
  - Stateless through the event: Lua's metatable channel becomes static
    state. The checks inspect, by Reflection (test code, not a gate), the
    Event, Gate, Stream and rejection classes and their parents for
    static properties and methods with static variables, and run Lua's
    count probe: a gate that increments every public static property
    reachable from its event's class must see 0 on a second stream.
  - Lua's R5 (the doors by convention) thins: the stream's state is
    private, so ordinary code reaches it only through the doors. PHP's
    debug facilities remain, the counterpart of Lua's debug library (R3):
    `debug_backtrace()` hands any running code the arguments and `$this`
    of every frame on the call stack: from a gate or an observer, the
    Stream itself (emit's `$this`). emit keeps the rejection, the
    observers list and the records in its own locals, which a backtrace
    does not show. Reflection or a closure bound into a class scope then
    reads and writes the stream's private state (residue, gates,
    observers). Through them a gate or an observer can reach the stream's
    state and host code can write the residue. Convention, not defended
    (independent review, 13.1.0); the R3 counterpart lists them.
  - Release: what a gate returned is released (its last reference
    dropped) inside the stream's protection. A throw while releasing it
    (an object's `__destruct`, a started generator's `finally`) adds
    nothing: the rejection its return was judged to be stands, and emit
    raises only that rejection. Objects a gate keeps elsewhere are
    destroyed when the gate's own code lets them go: a throw then is the
    gate's (SISO_GATE_THREW) if it happens during its call, and the
    destroying code's otherwise (convention).
  - Lua R4 (core 12 `__readonly` sentinels) vanishes: sentinels are enum
    cases.
  - Lua X1's forged event vanishes for ordinary code: Event is final and
    Event::is is instanceof; a look-alike array or object is not an
    Event. What remains is deception by trusted code, recorded and not
    defended: Reflection (newInstanceWithoutConstructor plus setValue on
    the still-uninitialised readonly properties) or a closure bound into
    the class scope can build an Event whose data was never validated,
    the counterpart of Lua's debug library.
  - Ownership's "rawset on a copy" becomes: a write through Reflection
    and a \WeakMap stamp. The checks use both.
  - Convention (Lua R3's remainder): a gate's own `static` variables,
    `use (&$x)` captures, globals and $GLOBALS, I/O, exit, warnings
    raised into an error handler, \WeakMaps keyed by events.
  - Depth: PHP frees nested arrays recursively in C, so data nested more
    deeply than the C stack allows (about 150,000 levels with an 8 MB
    stack) crashes the process when it is freed, wherever it was built:
    the substrate's own limit. Memory is the other: a recursive copy or
    validation exhausts memory_limit (a fatal error, not catchable) long
    before PHP's call stack fails. The ownership check's depth probes
    (CoreApi::DEEP) stop at 60,000, so a run fits PHP's default 128M
    memory_limit. An entry the stream cannot copy is SISO_NOT_EVENT;
    nothing else is thrown.
  - Exception plumbing: the rejection's file, line and trace (with
    arguments unless zend.exception_ignore_args) are the substrate's,
    not fields (they are not public properties).

CoreApi::checkDoors($core), ::checkReadonly($core) and ::checkOwnership($core)
run this contract against an implementation
`$core = ['Event' => class-string, 'Gate' => class-string, 'Stream' => class-string]`
and return a list of problems (empty when it holds). They are what the
stream phases' checks call. CoreApi::rejectionProblems($r, $want) judges
one thrown rejection.
*/
declare(strict_types=1);

namespace Siso\Contracts;

/** An immutable sentinel for the probes: an enum case, kept by identity. */
enum ProbeCase
{
    case Sentinel;
}

final class CoreApi
{
    public const CODES = [
        'SISO_SELF_LOOP' => true,
        'SISO_CYCLE' => true,
        'SISO_GATE_THREW' => true,
        'SISO_NOT_EVENT' => true,
    ];

    public const DOORS = ['register', 'emit', 'takeResidue', 'sampleHere', 'attach', 'detach'];

    public const REJECTION_FIELDS = ['code', 'message', 'eventId', 'causeId', 'eventType', 'depth', 'cause'];

    // The fields a rejection carries only for one code (beyond code and message).
    private const EXTRA = ['SISO_CYCLE' => 'depth', 'SISO_GATE_THREW' => 'cause'];

    // Depths of the entry-copy probes (see SUBSTRATE: Depth).
    public const DEEP = [1000, 10000, 30000, 60000];

    private function __construct()
    {
    }

    /*
    rejectionProblems($r, $want) → list of problems.
    `$want` (optional) pins fields: ['code' => …, 'eventId' => …,
    'causeId' => …, 'eventType' => …, 'depth' => …, 'cause' => …]; a key
    present in `$want` is compared with ===, 'noDelivery' => true requires
    eventId, causeId and eventType to be null, and 'entry' => true
    requires causeId to be null.
    */
    public static function rejectionProblems(mixed $r, ?array $want = null): array
    {
        $p = [];
        if (!is_object($r)) {
            return ['a rejection is an object, got ' . get_debug_type($r)];
        }
        if (!$r instanceof \Throwable) {
            $p[] = 'a rejection is a \Throwable, got ' . get_class($r);
        }
        // public properties, as seen from outside the class
        $f = get_object_vars($r);
        $code = $f['code'] ?? null;
        if (!is_string($code) || !isset(self::CODES[$code])) {
            $p[] = 'unknown code ' . self::show($code);
        }
        $message = $f['message'] ?? null;
        if (!is_string($message)) {
            $p[] = 'message is not a string';
        } else {
            $text = null;
            try {
                $text = (string)$r;
            } catch (\Throwable) {
            }
            if ($text !== $message) {
                $p[] = '(string)$rejection is not its message';
            }
            if ($r instanceof \Throwable && $r->getMessage() !== $message) {
                $p[] = 'getMessage() is not its message';
            }
        }
        foreach ($f as $k => $_) {
            if (!in_array($k, self::REJECTION_FIELDS, true)) {
                $p[] = "unexpected field '" . $k . "'";
            }
        }
        foreach (self::REJECTION_FIELDS as $k) {
            if (!array_key_exists($k, $f)) {
                $p[] = "missing field '" . $k . "' (a field its code does not carry is null)";
            }
        }
        foreach (self::EXTRA as $c => $field) {
            if ($code !== $c && ($f[$field] ?? null) !== null) {
                $p[] = $field . ' is carried only by ' . $c;
            }
        }
        if ($code === 'SISO_CYCLE' && !is_int($f['depth'] ?? null)) {
            $p[] = 'SISO_CYCLE carries an integer depth';
        }
        if ($code === 'SISO_GATE_THREW' && !(($f['cause'] ?? null) instanceof \Throwable)) {
            $p[] = 'SISO_GATE_THREW carries the \Throwable the gate threw';
        }
        foreach (['eventId', 'causeId'] as $k) {
            if (($f[$k] ?? null) !== null && !is_int($f[$k])) {
                $p[] = $k . ' is not an integer';
            }
        }
        if (($f['eventType'] ?? null) !== null && !is_string($f['eventType'])) {
            $p[] = 'eventType is not a string';
        }
        if ($want !== null) {
            foreach (['code', 'eventId', 'causeId', 'eventType', 'depth', 'cause'] as $k) {
                if (array_key_exists($k, $want) && ($f[$k] ?? null) !== $want[$k]) {
                    $p[] = $k . ': expected ' . self::show($want[$k]) . ', got ' . self::show($f[$k] ?? null);
                }
            }
            if (!empty($want['noDelivery'])
                && (($f['eventId'] ?? null) !== null || ($f['causeId'] ?? null) !== null || ($f['eventType'] ?? null) !== null)) {
                $p[] = 'a refused entry names no delivery';
            }
            if (!empty($want['entry']) && ($f['causeId'] ?? null) !== null) {
                $p[] = 'the entry delivery has no cause';
            }
        }
        return $p;
    }

    // ── running the contract against an implementation ──────────────

    /** A short rendering of a value, for problem messages only. */
    public static function show(mixed $v): string
    {
        if ($v === null) {
            return 'null';
        }
        if (is_string($v)) {
            return '"' . addcslashes($v, "\"\\\0..\37") . '"';
        }
        if (is_bool($v)) {
            return $v ? 'true' : 'false';
        }
        if (is_int($v) || is_float($v)) {
            return var_export($v, true);
        }
        if ($v instanceof \UnitEnum) {
            return get_class($v) . '::' . $v->name;
        }
        return get_debug_type($v);
    }

    /** problems for a $core that does not name three classes */
    private static function coreProblems(array $core): array
    {
        $p = [];
        foreach (['Event', 'Gate', 'Stream'] as $k) {
            if (!is_string($core[$k] ?? null) || !class_exists($core[$k])) {
                $p[] = "core['" . $k . "'] is not a class";
            }
        }
        return $p;
    }

    /** an object's public properties (seen from outside), sorted and joined */
    private static function fieldsOf(object $o): string
    {
        $ks = array_map('strval', array_keys(get_object_vars($o)));
        sort($ks, SORT_STRING);
        return implode(',', $ks);
    }

    /** → null when it settled, else what it threw */
    private static function emitCatch(object $s, mixed $e): ?\Throwable
    {
        try {
            $s->emit($e);
            return null;
        } catch (\Throwable $t) {
            return $t;
        }
    }

    /**
     * Runs $fn with notices silenced (a write a lax implementation
     * accepts may raise a deprecation); → true when it completed, false
     * when it threw.
     */
    public static function attempt(callable $fn): bool
    {
        set_error_handler(static fn (): bool => true);
        try {
            $fn();
            return true;
        } catch (\Throwable) {
            return false;
        } finally {
            restore_error_handler();
        }
    }

    /** the substrate's back door on an object: a Reflection write (refused on readonly) */
    public static function reflectWrite(object $o, string $property, mixed $value): void
    {
        self::attempt(static function () use ($o, $property, $value): void {
            (new \ReflectionProperty($o, $property))->setValue($o, $value);
        });
    }

    /** $cls and its parents */
    private static function lineage(string $cls): array
    {
        $out = [];
        for ($c = new \ReflectionClass($cls); $c !== false; $c = $c->getParentClass()) {
            $out[] = $c;
        }
        return $out;
    }

    /** the static state a class or its parents hold: static properties and methods with static variables */
    public static function staticStateOf(string $cls): array
    {
        $found = [];
        foreach (self::lineage($cls) as $c) {
            foreach ($c->getProperties(\ReflectionProperty::IS_STATIC) as $prop) {
                if ($prop->getDeclaringClass()->getName() === $c->getName()) {
                    $found[] = 'static property ' . $c->getName() . '::$' . $prop->getName();
                }
            }
            foreach ($c->getMethods() as $m) {
                if ($m->getDeclaringClass()->getName() === $c->getName() && $m->isUserDefined()
                    && $m->getStaticVariables() !== []) {
                    $found[] = 'static variables in ' . $c->getName() . '::' . $m->getName() . '()';
                }
            }
        }
        return $found;
    }

    /** classes of every object reachable from $v (arrays and public properties walked) */
    private static function classesIn(mixed $v, array &$into, array &$seen): void
    {
        if (is_array($v)) {
            foreach ($v as $x) {
                self::classesIn($x, $into, $seen);
            }
            return;
        }
        if (!is_object($v)) {
            return;
        }
        $id = spl_object_id($v);
        if (isset($seen[$id])) {
            return;
        }
        $seen[$id] = true;
        foreach (self::lineage(get_class($v)) as $c) {
            $into[$c->getName()] = true;
        }
        if (!$v instanceof \UnitEnum) {
            foreach (get_object_vars($v) as $x) {
                self::classesIn($x, $into, $seen);
            }
        }
    }

    /** [class, name] of every public static property reachable from $v's classes */
    private static function publicStaticsFrom(mixed $v): array
    {
        $into = [];
        $seen = [];
        self::classesIn($v, $into, $seen);
        $out = [];
        foreach (array_keys($into) as $cls) {
            foreach ((new \ReflectionClass($cls))->getProperties(\ReflectionProperty::IS_STATIC | \ReflectionProperty::IS_PUBLIC) as $prop) {
                if ($prop->isStatic() && $prop->isPublic() && $prop->getDeclaringClass()->getName() === $cls) {
                    $out[] = [$cls, $prop->getName()];
                }
            }
        }
        return $out;
    }

    public static function checkDoors(array $core): array
    {
        $p = self::coreProblems($core);
        if ($p !== []) {
            return $p;
        }
        $Event = $core['Event'];
        $Gate = $core['Gate'];
        $Stream = $core['Stream'];
        $fail = static function (string $s) use (&$p): void {
            $p[] = $s;
        };
        $guard = static function (string $label, callable $fn) use ($fail): void {
            try {
                $fn();
            } catch (\Throwable $t) {
                $fail($label . ': raised ' . get_class($t) . ': ' . $t->getMessage());
            }
        };
        $gate = static fn (string $sig, ?callable $fn = null, ?array $shape = null): object => new $Gate($sig, $fn, $shape);

        $guard('Event', static function () use ($Event, $fail): void {
            $e = new $Event('t', ['x' => 1]);
            if (self::fieldsOf($e) !== 'data,type') {
                $fail('Event fields are ' . self::fieldsOf($e));
            }
            if ($e->type !== 't' || ($e->data['x'] ?? null) !== 1) {
                $fail('Event does not hold type and data');
            }
            $d = new $Event('t');
            if ($d->data !== []) {
                $fail('Event data does not default to []');
            }
            if (self::fieldsOf(new $Event('t', [], ['attempt' => 1])) !== 'data,type') {
                $fail('an extra argument attached a field');
            }
            if ($Event::is($e) !== true) {
                $fail('Event::is(event) is not true');
            }
            if ($Event::is(['type' => 't', 'data' => []])) {
                $fail('Event::is accepts an array');
            }
            if ($Event::is((object)['type' => 't', 'data' => []])) {
                $fail('Event::is accepts an object that looks like an event');
            }
        });

        $guard('Gate', static function () use ($Event, $Gate, $fail): void {
            $fn = static function ($e) {
                return null;
            };
            $shape = ['emits' => [], 'rejects' => []];
            $g = new $Gate('g', $fn, $shape);
            if (self::fieldsOf($g) !== 'shape,signature,transform' || $g->signature !== 'g' || $g->transform !== $fn || $g->shape !== $shape) {
                $fail('Gate(signature, transform, shape) does not hold exactly its three fields');
            }
            $none = new $Gate('n');
            if (!$none->transform instanceof \Closure || ($none->transform)(new $Event('t')) !== null) {
                $fail('a Gate with no transform is not a no-op Closure');
            }
        });

        $guard('Stream doors', static function () use ($Stream, $fail): void {
            $s = new $Stream();
            foreach (self::DOORS as $d) {
                if (!is_callable([$s, $d])) {
                    $fail('stream has no door ' . $d);
                }
            }
            $sm = $s->sampleHere();
            if (!is_array($sm)) {
                $fail('sampleHere returns ' . get_debug_type($sm));
                return;
            }
            $ks = array_map('strval', array_keys($sm));
            sort($ks, SORT_STRING);
            if (implode(',', $ks) !== 'gateCount,residue') {
                $fail('sampleHere carries ' . implode(',', $ks));
            }
            foreach (get_object_vars($s) as $k => $_) {
                $fail("the stream has a public property '" . $k . "'");
            }
        });

        $guard('register', static function () use ($Stream, $gate, $fail): void {
            $s = new $Stream();
            $s->register($gate('a'));
            try {
                $s->register($gate('a'));
                $fail('a duplicate signature was accepted');
            } catch (\Throwable $err) {
                if ($err->getMessage() !== "Signature collision: 'a'") {
                    $fail('collision message is ' . $err->getMessage());
                }
                if (!$err instanceof \LogicException) {
                    $fail('a collision is ' . get_class($err) . ', not a \LogicException');
                }
            }
            if ($s->sampleHere()['gateCount'] !== 1) {
                $fail('gateCount is not the registered count');
            }
        });

        $guard('emit and take', static function () use ($Event, $Stream, $gate, $fail): void {
            $s = new $Stream();
            $args = -1;
            $s->register($gate('a', static function (...$a) use ($Event, &$args) {
                $args = count($a);
                return new $Event('b', ['v' => $a[0]->data['v']]);
            }));
            $returned = $s->emit(new $Event('a', ['v' => 7]));
            if ($returned !== null) {
                $fail('emit returned a value');
            }
            if ($args !== 1) {
                $fail('a gate was called with ' . $args . ' arguments, not the event alone');
            }
            $smp = $s->sampleHere();
            if (count($smp['residue']) !== 1 || ($smp['residue'][0]->type ?? null) !== 'b') {
                $fail('residue after a settled chain');
            }
            array_pop($smp['residue']);
            if (count($s->sampleHere()['residue']) !== 1) {
                $fail("a sample's list reached the stream");
            }
            $taken = $s->takeResidue();
            if (!is_array($taken) || !array_is_list($taken) || count($taken) !== 1 || ($taken[0]->data['v'] ?? null) !== 7) {
                $fail('takeResidue did not return the residue as a list');
            }
            if (count($s->sampleHere()['residue']) !== 0) {
                $fail('the stream still holds taken residue');
            }
        });

        $guard('returns', static function () use ($Event, $Stream, $gate, $fail): void {
            $s = new $Stream();
            $s->register($gate('one', static fn () => new $Event('x')));
            $s->register($gate('many', static fn () => [new $Event('x'), new $Event('y')]));
            $s->register($gate('none', static function () {
                return null;
            }));
            $s->register($gate('bare', static function (): void {
            }));
            $s->register($gate('empty', static fn () => []));
            foreach (['one', 'many', 'none', 'bare', 'empty'] as $t) {
                $s->emit(new $Event($t));
            }
            if (count($s->takeResidue()) !== 3) {
                $fail('null / no return / [] / Event / list returns do not settle as declared');
            }
        });

        $rej = static function (string $label, object $s, mixed $entry, array $want) use (&$p, $fail): void {
            $before = count($s->sampleHere()['residue']);
            $r = self::emitCatch($s, $entry);
            if ($r === null) {
                $fail($label . ': settled, expected ' . $want['code']);
                return;
            }
            foreach (self::rejectionProblems($r, $want) as $x) {
                $p[] = $label . ': ' . $x;
            }
            if (count($s->sampleHere()['residue']) !== $before) {
                $fail($label . ': a rejection committed residue');
            }
        };

        $guard('rejections', static function () use ($Event, $Stream, $gate, $rej, $fail): void {
            $s = new $Stream();
            $s->emit(new $Event('earlier'));
            $s->register($gate('loop', static fn () => [new $Event('other'), new $Event('loop')]));
            $rej('self-loop', $s, new $Event('loop'), ['code' => 'SISO_SELF_LOOP', 'eventId' => 1, 'eventType' => 'loop', 'entry' => true]);

            $c = new $Stream();
            $c->register($gate('a', static fn () => new $Event('b')));
            $c->register($gate('b', static fn () => new $Event('a')));
            $rej('cycle', $c, new $Event('a'), ['code' => 'SISO_CYCLE', 'eventId' => 3, 'causeId' => 2, 'eventType' => 'a', 'depth' => 3]);

            $t = new $Stream();
            $thrown = new \RuntimeException("the gate's own value");
            $t->register($gate('a', static fn () => new $Event('b')));
            $t->register($gate('b', static function () use ($thrown) {
                throw $thrown;
            }));
            $rej('gate threw', $t, new $Event('a'), ['code' => 'SISO_GATE_THREW', 'eventId' => 2, 'causeId' => 1, 'eventType' => 'b', 'cause' => $thrown]);
            // ids are per propagation: the same entry names the same deliveries again
            $rej('gate threw, again', $t, new $Event('a'), ['code' => 'SISO_GATE_THREW', 'eventId' => 2, 'causeId' => 1]);
            $t->register($gate('error', static fn () => intdiv(1, 0)));
            $rej('a gate\'s \Error', $t, new $Event('error'), ['code' => 'SISO_GATE_THREW', 'eventId' => 1]);

            $n = new $Stream();
            $rej('entry not an Event', $n, ['type' => 'x', 'data' => []], ['code' => 'SISO_NOT_EVENT', 'noDelivery' => true]);
            $rej('entry null', $n, null, ['code' => 'SISO_NOT_EVENT', 'noDelivery' => true]);
            $rej('entry an object that looks like an Event', $n, (object)['type' => 'x', 'data' => []], ['code' => 'SISO_NOT_EVENT', 'noDelivery' => true]);
            $n->register($gate('bad', static fn () => [new $Event('ok'), ['type' => 'fake']]));
            $rej('output not an Event', $n, new $Event('bad'), ['code' => 'SISO_NOT_EVENT', 'eventId' => 1, 'eventType' => 'bad']);
            $n->register($gate('holes', static fn () => [0 => new $Event('ok'), 2 => new $Event('ok')]));
            $rej('output list with a hole', $n, new $Event('holes'), ['code' => 'SISO_NOT_EVENT', 'eventId' => 1]);
            $n->register($gate('keyed', static fn () => ['k' => new $Event('ok')]));
            $rej('output array with a named key', $n, new $Event('keyed'), ['code' => 'SISO_NOT_EVENT', 'eventId' => 1]);
            $n->register($gate('num', static fn () => 42));
            $rej('output a number', $n, new $Event('num'), ['code' => 'SISO_NOT_EVENT']);
            $n->register($gate('f', static fn () => false));
            $rej('output false', $n, new $Event('f'), ['code' => 'SISO_NOT_EVENT']);
            $n->register($gate('str', static fn () => 'text'));
            $rej('output a string', $n, new $Event('str'), ['code' => 'SISO_NOT_EVENT']);
            $n->register($gate('obj', static fn () => new \stdClass()));
            $rej('output an object', $n, new $Event('obj'), ['code' => 'SISO_NOT_EVENT']);
            $n->register($gate('enum', static fn () => ProbeCase::Sentinel));
            $rej('output an enum case', $n, new $Event('enum'), ['code' => 'SISO_NOT_EVENT']);
            // what a gate returned is released inside the stream's protection:
            // a destructor or a generator's finally that throws on release
            // changes nothing (the judged rejection stands; nothing raw leaves)
            $n->register($gate('dtor', static fn () => new class {
                public function __destruct()
                {
                    throw new \RuntimeException('released');
                }
            }));
            $rej('output an object whose destructor throws', $n, new $Event('dtor'), ['code' => 'SISO_NOT_EVENT', 'eventId' => 1]);
            $n->register($gate('gen', static function () {
                $g = (static function () {
                    try {
                        yield 1;
                    } finally {
                        throw new \RuntimeException('released');
                    }
                })();
                $g->current();
                return $g;
            }));
            $rej('output a started generator whose finally throws', $n, new $Event('gen'), ['code' => 'SISO_NOT_EVENT', 'eventId' => 1]);
            // members in key order, then the keys
            $n->register($gate('ownfirst', static fn () => [0 => new $Event('ownfirst'), 'k' => 1]));
            $rej('a list member judged before the keys', $n, new $Event('ownfirst'), ['code' => 'SISO_SELF_LOOP', 'eventId' => 1]);
            $n->register($gate('second', static fn () => [42, new $Event('second')]));
            $rej('list members judged in order', $n, new $Event('second'), ['code' => 'SISO_NOT_EVENT', 'eventId' => 1]);

            $u = new $Stream();
            $u->register($gate('boom', static function () {
                throw new \RuntimeException('x');
            }));
            self::emitCatch($u, new $Event('boom'));
            $u->emit(new $Event('fine'));
            if (count($u->takeResidue()) !== 1) {
                $fail('the stream is not usable after a rejection');
            }
        });

        return $p;
    }

    public static function checkReadonly(array $core): array
    {
        $p = self::coreProblems($core);
        if ($p !== []) {
            return $p;
        }
        $Event = $core['Event'];
        $Gate = $core['Gate'];
        $Stream = $core['Stream'];
        $fail = static function (string $s) use (&$p): void {
            $p[] = $s;
        };
        $refuses = static function (string $label, callable $fn) use ($fail): void {
            if (self::attempt($fn)) {
                $fail($label . ' was accepted');
            }
        };
        try {
            // read-only from construction (core 13): before any stream sees it
            $x = 1;
            $y = 1;
            $orig = ['v' => 1, 'list' => [1]];
            $orig['r'] = &$x;
            $orig['list'][1] = &$y;
            $born = new $Event('a', $orig);
            $refuses('writing a field of a new event', static function () use ($born): void {
                $born->type = 'z';
            });
            $refuses('adding a field to a new event', static function () use ($born): void {
                $born->extra = 1;
            });
            $refuses('unsetting a field of a new event', static function () use ($born): void {
                unset($born->data);
            });
            $refuses("writing a new event's data", static function () use ($born): void {
                $born->data['v'] = 2;
            });
            $refuses("writing into a new event's nested data", static function () use ($born): void {
                $born->data['list'][0] = 0;
            });
            $refuses("taking a reference into a new event's data", static function () use ($born): void {
                $r = &$born->data['v'];
                $r = 2;
            });
            $refuses("writing a new event's data through Reflection", static function () use ($born): void {
                (new \ReflectionProperty($born, 'data'))->setValue($born, ['v' => 3]);
            });
            $refuses("writing a new event's default data", static function () use ($Event): void {
                $e = new $Event('t');
                $e->data['x'] = 1;
            });
            if (self::fieldsOf($born) !== 'data,type') {
                $fail("a new event's fields are " . self::fieldsOf($born));
            }
            $orig['v'] = 2;
            $orig['list'][0] = 9;
            $orig['late'] = true;
            $x = 2;
            $y = 2;
            if (($born->data['v'] ?? null) !== 1 || ($born->data['list'][0] ?? null) !== 1 || array_key_exists('late', $born->data)
                || ($born->data['r'] ?? null) !== 1 || ($born->data['list'][1] ?? null) !== 1) {
                $fail('a write to the array passed to Event (or through a reference it held) reached the event');
            }
            $own = $born->data;
            $own['v'] = 5;
            $own['r'] = 5;
            if ($born->data['v'] !== 1 || ($born->data['r'] ?? null) !== 1) {
                $fail("a write to a copy of a new event's data reached the event");
            }
            $inner = new $Event('i');
            $carried = new $Event('o', ['inner' => $inner, 'd' => $born->data, 'n' => ProbeCase::Sentinel]);
            if (($carried->data['inner'] ?? null) !== $inner) {
                $fail('an event carried in data lost its identity');
            }
            if (($carried->data['d'] ?? null) !== $born->data) {
                $fail('data passed on changed');
            }
            if (($carried->data['n'] ?? null) !== ProbeCase::Sentinel) {
                $fail('an enum case in new data lost its identity');
            }
            if (!$Event::is($born)) {
                $fail('Event::is does not recognise a new event');
            }

            // plain data only (PHP: an object carries writable state)
            $refusesData = static function (string $label, array $data, string $message) use ($Event, $fail): void {
                try {
                    new $Event('t', $data);
                    $fail('Event accepted ' . $label . ' in its data');
                } catch (\InvalidArgumentException $err) {
                    if ($err->getMessage() !== $message) {
                        $fail('Event refused ' . $label . ' with "' . $err->getMessage() . '", not "' . $message . '"');
                    }
                } catch (\Throwable $err) {
                    $fail('Event refused ' . $label . ' with ' . get_class($err) . ', not \InvalidArgumentException');
                }
            };
            $refusesData('a stdClass', ['a' => [new \stdClass()]], "Event: data at ['a'][0] is not plain data (stdClass)");
            $refusesData('a Closure', ['f' => static fn () => 1], "Event: data at ['f'] is not plain data (Closure)");
            $refusesData('an ArrayObject', [3 => ['k' => new \ArrayObject()]], "Event: data at [3]['k'] is not plain data (ArrayObject)");
            $fh = fopen('php://memory', 'r');
            $refusesData('a resource', ['h' => $fh], "Event: data at ['h'] is not plain data (resource (stream))");
            fclose($fh);

            // unserialize validates as the constructor does
            $placeholder = 'SISO-CONTRACT-PLACEHOLDER';
            $ser = null;
            try {
                $ser = serialize(new $Event('t', ['o' => $placeholder]));
            } catch (\Throwable) {
            }
            if (is_string($ser)) {
                $quoted = 's:' . strlen($placeholder) . ':"' . $placeholder . '";';
                $forged = str_replace($quoted, 'O:8:"stdClass":1:{s:1:"n";i:0;}', $ser);
                $u = null;
                if ($forged !== $ser) {
                    self::attempt(static function () use ($forged, &$u): void {
                        $u = unserialize($forged);
                    });
                }
                if ($Event::is($u) && is_object($u->data['o'] ?? null)) {
                    $fail('unserialize built an event whose data is not plain data');
                }
                $extra = preg_replace_callback('/^(O:\d+:"[^"]+":)(\d+)(:\{)/', static fn (array $m): string => $m[1] . ((int)$m[2] + 1) . $m[3] . 's:5:"extra";i:1;', $ser, 1);
                $v = null;
                if (is_string($extra) && $extra !== $ser) {
                    self::attempt(static function () use ($extra, &$v): void {
                        $v = unserialize($extra);
                    });
                }
                if ($Event::is($v) && self::fieldsOf($v) !== 'data,type') {
                    $fail('unserialize built an event with fields ' . self::fieldsOf($v));
                }
            }

            // stateless through the event (core 13.0.0): no static state
            // in the classes an event, a gate, a stream or a rejection
            // reaches, and Lua's count probe
            $classes = ['Event' => $Event, 'Gate' => $Gate, 'Stream' => $Stream];
            $rs = new $Stream();
            $rs->register(new $Gate('r', static function () {
                throw new \RuntimeException('r');
            }));
            $raised = self::emitCatch($rs, new $Event('r'));
            if ($raised !== null) {
                $classes['rejection'] = get_class($raised);
            }
            foreach ($classes as $role => $cls) {
                foreach (self::staticStateOf($cls) as $what) {
                    $fail('the ' . $role . ' class holds static state: ' . $what);
                }
            }
            $statics = self::publicStaticsFrom(new $Event('count', ['e' => new $Event('inner')]));
            $saved = [];
            foreach ($statics as [$cls, $name]) {
                $saved[] = [$cls, $name, $cls::${$name}];
            }
            $countRun = static function () use ($Event, $Gate, $Stream): mixed {
                $cs = new $Stream();
                $cs->register(new $Gate('count', static function ($e) use ($Event) {
                    $n = 0;
                    foreach (self::publicStaticsFrom($e) as [$cls, $name]) {
                        try {
                            $ref = &$cls::${$name};
                            if (is_array($ref)) {
                                $ref['siso-contract-probe'] = ($ref['siso-contract-probe'] ?? 0) + 1;
                                $n += $ref['siso-contract-probe'];
                            } elseif ($ref === null || is_int($ref)) {
                                $ref = ($ref ?? 0) + 1;
                                $n += $ref;
                            }
                            unset($ref);
                        } catch (\Throwable) {
                        }
                    }
                    return new $Event('counted', ['n' => $n]);
                }));
                $cs->emit(new $Event('count', ['e' => new $Event('inner')]));
                return $cs->takeResidue()[0]->data['n'] ?? null;
            };
            try {
                $countRun();
                if ($countRun() !== 0) {
                    $fail('a gate carried a count from one stream to another through static state reachable from its event');
                }
            } finally {
                foreach ($saved as [$cls, $name, $value]) {
                    self::attempt(static function () use ($cls, $name, $value): void {
                        $cls::${$name} = $value;
                    });
                }
            }

            $seen = null;
            $s = new $Stream();
            $s->register(new $Gate('a', static function ($e) use (&$seen, $Event, $refuses, $fail) {
                $seen = $e;
                $refuses("a gate writing its event's data", static function () use ($e): void {
                    $e->data['v'] = 2;
                });
                $refuses('a gate writing into nested data', static function () use ($e): void {
                    $e->data['list'][0] = 0;
                });
                $refuses("a gate taking a reference into its event's data", static function () use ($e): void {
                    $r = &$e->data['v'];
                    $r = 2;
                });
                $d = $e->data;
                $d['v'] = 2;
                $d['r'] = 99;
                $d['list'][0] = 0;
                if (($e->data['v'] ?? null) !== 1 || ($e->data['r'] ?? null) !== 1 || ($e->data['list'][0] ?? null) !== 1) {
                    $fail("a gate's write to its own copy of the data reached the event");
                }
                if ($e->data['list'] !== $e->data['list']) {
                    $fail('two reads of one field give different values');
                }
                return new $Event('b', ['list' => [1, 2], 'v' => $e->data['v'] ?? null, 'n' => ProbeCase::Sentinel]);
            }));
            $z = 1;
            $entry = ['v' => 1, 'list' => [1, 2]];
            $entry['r'] = &$z;
            $s->emit(new $Event('a', $entry));
            $refuses('writing a field of an event', static function () use ($seen): void {
                $seen->type = 'z';
            });
            if (!$Event::is($seen)) {
                $fail('Event::is does not recognise an event a gate was given');
            }
            $r = $s->sampleHere()['residue'][0] ?? null;
            if (!is_object($r)) {
                $fail('no residue to sample');
                return $p;
            }
            if (($r->data['n'] ?? null) !== ProbeCase::Sentinel) {
                $fail('an enum case lost its identity');
            }
            if (!$Event::is($r)) {
                $fail('Event::is does not recognise a sampled event');
            }
            $refuses("writing a sampled event's data", static function () use ($r): void {
                $r->data['v'] = 99;
            });
            $refuses("writing into a sampled event's nested data", static function () use ($r): void {
                $r->data['list'][0] = 9;
            });
            if (($s->sampleHere()['residue'][0]->data['v'] ?? null) !== 1) {
                $fail("a sample's write reached the stream");
            }
            $l = $r->data['list'];
            if (count($l) !== 2 || $l[1] !== 2) {
                $fail('count() and indexing do not read a list in data');
            }
            $sum = 0;
            foreach ($l as $v) {
                $sum += $v;
            }
            if ($sum !== 3) {
                $fail('foreach does not read a list in data');
            }
            if (count($r->data) !== 3) {
                $fail('count() does not read the data');
            }
            $t = $s->takeResidue()[0];
            $refuses("writing a taken event's data", static function () use ($t): void {
                $t->data['v'] = 5;
            });
        } catch (\Throwable $err) {
            $fail('raised ' . get_class($err) . ': ' . $err->getMessage());
        }
        return $p;
    }

    public static function checkOwnership(array $core): array
    {
        $p = self::coreProblems($core);
        if ($p !== []) {
            return $p;
        }
        $Event = $core['Event'];
        $Stream = $core['Stream'];
        $fail = static function (string $s) use (&$p): void {
            $p[] = $s;
        };
        try {
            $s = new $Stream();
            $s->emit(new $Event('x', ['v' => 1, 'n' => [1], 'e' => new $Event('in', ['w' => 1])]));
            $a = $s->sampleHere()['residue'][0];
            // the substrate's back doors on a sampled event: a Reflection
            // write (refused by readonly) and a per-object stamp
            self::reflectWrite($a, 'type', 'z');
            self::reflectWrite($a, 'data', ['v' => 7, 'n' => [9]]);
            $stamps = new \WeakMap();
            $stamps[$a] = 'SAMPLER WROTE';
            if (is_object($a->data['e'] ?? null)) {
                $stamps[$a->data['e']] = 'SAMPLER WROTE';
            }
            $b = $s->sampleHere()['residue'][0];
            if ($b->type !== 'x') {
                $fail('a Reflection write on a sampled event reached the stream');
            }
            if (($b->data['v'] ?? null) !== 1 || ($b->data['n'][0] ?? null) !== 1) {
                $fail("a Reflection write on a sampled event's data reached the stream");
            }
            if (isset($stamps[$b])) {
                $fail('a stamp on a sampled event reached the stream: two samples hand out the same object');
            }
            if (is_object($b->data['e'] ?? null) && isset($stamps[$b->data['e']])) {
                $fail("a stamp on an event in a sampled event's data reached the stream: two samples share it");
            }
            if (self::attempt(static function () use ($b): void {
                $b->data['v'] = 2;
            })) {
                $fail('a sampled copy accepts assignment');
            }
            if (!$Event::is($b)) {
                $fail('Event::is does not recognise a sampled copy');
            }
            $t = $s->takeResidue()[0];
            if ($t->type !== 'x' || ($t->data['v'] ?? null) !== 1 || ($t->data['n'][0] ?? null) !== 1 || isset($stamps[$t])
                || (is_object($t->data['e'] ?? null) && isset($stamps[$t->data['e']]))) {
                $fail('a write or a stamp on a sample reached the residue later taken');
            }

            // an entry too deep to copy is refused as a rejection, never
            // thrown raw (nothing else is thrown): every depth Event accepts
            foreach (self::DEEP as $depth) {
                $deep = [];
                for ($i = 0; $i < $depth; $i++) {
                    $deep = ['x' => $deep];
                }
                try {
                    $built = new $Event('deep', $deep);
                } catch (\Throwable) {
                    continue;
                }
                unset($deep);
                $ds = new $Stream();
                $raisedE = self::emitCatch($ds, $built);
                if ($raisedE !== null && !isset(self::CODES[get_object_vars($raisedE)['code'] ?? ''])) {
                    $fail('an entry ' . $depth . ' deep made emit throw ' . get_class($raisedE) . ': '
                        . substr($raisedE->getMessage(), 0, 60) . ', not a rejection');
                }
                unset($built, $ds, $raisedE);
            }

            // entry takes the stream's own copy (core 13.0.0)
            $h = new $Stream();
            $heldInner = new $Event('in', ['w' => 1]);
            $held = new $Event('x', ['v' => 1, 'n' => [1], 'e' => $heldInner]);
            $h->emit($held);
            self::reflectWrite($held, 'type', 'z');
            self::reflectWrite($held, 'data', ['v' => 7, 'n' => [9]]);
            $stamps[$held] = 'ENTERING CODE WROTE';
            $stamps[$heldInner] = 'ENTERING CODE WROTE';
            // a take hands over the stream's own objects, so it shows what it held
            $back = $h->takeResidue()[0];
            if ($back === $held || $back->type !== 'x' || ($back->data['v'] ?? null) !== 1 || ($back->data['n'][0] ?? null) !== 1
                || isset($stamps[$back])) {
                $fail("the entering code's own event reached the residue");
            }
            if (($back->data['e'] ?? null) === $heldInner || (is_object($back->data['e'] ?? null) && isset($stamps[$back->data['e']]))) {
                $fail("an event in the entering code's own data reached the residue");
            }
            $h->emit($back);
            self::reflectWrite($back, 'data', ['v' => 8]);
            $stamps[$back] = 'TAKER WROTE';
            $again = $h->takeResidue()[0];
            if ($again === $back || ($again->data['v'] ?? null) !== 1 || isset($stamps[$again])) {
                $fail('taken residue entered again reached the residue');
            }
        } catch (\Throwable $err) {
            $fail('raised ' . get_class($err) . ': ' . $err->getMessage());
        }
        return $p;
    }
}
