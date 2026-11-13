"""contract: core-api -- what the core hands everyone outside it (core 13,
Python instance). Port of lua/code/contracts/core_api.lua.

Between: stream -> observer, kit, fixtures, and every consuming project.
Only a contracts phase changes this file. It holds what crosses, not how
the stream works.

MODULES (import root py/code/; absolute imports of the package `siso`)
  siso.core.event      Event
  siso.core.gate       Gate
  siso.core.stream     Stream
  siso.core.rejection  Rejection
  siso.core.freeze     FrozenDict, the core's frozen mapping, and the
                       copies the stream takes
  siso.core            re-exports Event, Gate, Rejection, Stream and
                       FrozenDict
  The core imports only pure standard-library modules and nothing that
  does I/O.

EVENT
  Event(type, data=None) -> an instance of the final class Event (a
    subclass is refused when its class statement runs, TypeError) with
    exactly two fields, `type` and `data` (__slots__ = ("type", "data"),
    no instance __dict__), read-only from the moment it is built:
    assigning or deleting an attribute of the event raises
      AttributeError("read-only: an event and its data cannot be assigned (field '<name>')")
    and nothing in its data can be assigned either, wherever the event
    was built (a gate, a boundary layer, a test).
    `type` is a str (a substrate narrowing, as PHP's): anything else is
      TypeError("Event: type is not a str (<type name>)").
    `data` is a mapping: a dict or the core's FrozenDict; None (or no
    argument) is an empty FrozenDict; anything else is
      TypeError("Event: data is not a mapping (<type name>)").
    A third argument is refused by Python's own arity check (TypeError):
    nothing a caller passes is silently dropped (Drift 7).
    The data is taken as it stands: Event deep-copies it, iteratively
    (data of any depth; Python's recursion limit is about 1,000 frames),
    into frozen values, so a write the caller makes afterwards to what it
    passed never reaches the event, and the caller's own objects are never
    changed:
      dict (exact type) or FrozenDict  -> FrozenDict
      list or tuple (exact types)      -> tuple
      str, int, float, bool, None (exact types)  as they are
      an Event, a FrozenDict           kept by identity (already frozen),
                                       so an event or data passed on
                                       unchanged keeps its identity
    Mapping keys must be str. Sharing within one call is preserved (a
    dict or list reached twice gives the same frozen value both times);
    a cycle is refused (a tuple cannot hold itself; plain data is
    acyclic, and kit.data refuses cycles too). Anything else -- a set,
    frozenset, bytes, bytearray, complex, a function, a class or module,
    a class instance, a subclass of dict, list, tuple, str, int or float
    (an OrderedDict, a namedtuple, an IntEnum), a generator, a
    MappingProxyType, a key that is not a str, a cycle -- is refused:
      TypeError("Event: data at <path> is not plain data (<what>)")
    where <path> is "data" followed by [repr(key)] for each step from the
    data's root (data {'a': [set()]} is "Event: data at data['a'][0] is
    not plain data (set)"), <what> is the value's type name, "a key of
    type <type name>" (the path is the mapping's) or "a cycle". Nothing
    is dropped or repaired: carry or refuse.
  Event.isEvent(v) -> True only for an Event: equivalent to
    isinstance(v, Event), because Event is final (Lua's Event.is: `is` is
    a Python keyword). A look-alike (a dict, an object with `type` and
    `data` attributes, a SimpleNamespace) answers False; any value is
    answered without raising.
  FrozenDict: a mapping (not a dict, and not an ABC: it implements the
    Mapping protocol itself and is registered with collections.abc.Mapping
    from outside, so isinstance(fd, Mapping) holds and no ABC registry is
    reachable from an event): `[]`, get, keys, values, items, len,
    iteration, `in` and == (equal to a dict with the same items) work; it has no mutating method (no update, pop, popitem,
    setdefault, clear, __setitem__, __delitem__, __ior__), refuses attribute
    assignment, and holds its storage so that ordinary attribute access
    cannot reach a mutable dict (a MappingProxyType, say). Every value in
    it is frozen.
  STATELESS THROUGH THE EVENT (core 13.0.0): nothing reachable from an
    event by ordinary access holds state a gate could write and later
    read. From an event built of plain data a gate reaches only
    immutable values: str, int, float, bool, None, tuple, FrozenDict,
    Event, and the core's classes. Every core class (Event, FrozenDict,
    Gate, Stream, Rejection and any helper class reachable from them) is
    locked: its metaclass refuses setting or deleting a class attribute,
    so `type(e).counter = 1` raises (the Python form of the EventMeta
    state channel the reference closed in 13.0.0). Every attribute of a
    core class or core object reached by a name that is not a dunder is
    immutable: no core class attribute is a Python function (a function's
    attributes are writable: `type(e).isEvent.n = 1` would be a channel),
    so a callable class attribute such as Event.isEvent is an instance of
    a locked class with __slots__ = (). No class reachable from an event
    is an ABC: an ABC's registry is state anyone can write through
    register(), and a lock on register() is passed by
    super(type(T), T).register (independent review, 13.2.0). What remains is named under SUBSTRATE (the metaclass,
    dunder introspection, the standard library's own classes).
  An event is plain data: `data` holds what kit.data encodes as
    canonical JSON (SISO "polyglot"; the kit checks this, the stream does
    not: the core accepts NaN, the kit refuses it).
  READ-ONLY (from construction): every event Event builds, and so every
    event that enters, every event a gate returns, and every event a
    gate, a sample, a take or an observer is given, is read-only with its
    data at every depth. Reads work through indexing, len, iteration,
    get/keys/values/items and `in`, and reading the same field twice gives
    the same object (`is`). A list in data reads back as a tuple.
  IMMUTABLE SENTINELS: JSON null is None, which needs no sentinel; Lua's
    "readonly:" sentinels and its core 12 `__readonly` form (R4) vanish.
  OWNERSHIP: the stream never holds an object the outside still holds,
    and never hands out one it still holds. Entry takes the stream's own
    copy of the event: a fresh Event, a fresh FrozenDict and a fresh
    Event for every Event in its data, at every depth (one memo per copy,
    so sharing inside it is preserved), so nothing the entering code
    holds is in the residue. A take hands the residue over (the stream
    holds none of it afterwards). A sample's residue holds the sampler's
    own fresh copies, and an observer's records carry the observer's own
    fresh copies of the data (observer-seam), so nothing done to them --
    `object.__setattr__` past the refusal (Python's rawset), or a stamp
    keyed by an object's identity -- reaches the stream, a later sample or
    another observer. Only inside a propagation is an event shared: a
    gate shares its input with the stream.

GATE
  Gate(signature, transform=None, shape=None) -> a frozen Gate with
  exactly `signature` (a str; else TypeError("Gate: signature is not a
  str (<type name>)")), `transform` (the callable as given; None gives the
  core's no-op, which returns None and is an instance of a locked class;
  a value that is not callable is TypeError("Gate: transform is not
  callable (<type name>)")) and `shape` (as given); assigning or deleting
  an attribute raises AttributeError. The stream calls
  `gate.transform(event)` with the event as its one positional argument.
  `transform` returns:
    None                consumed, no follow-up
    an Event            one follow-up
    a list or a tuple   exact types (not a subclass, which can override
    of Events           __iter__, __len__ or __getitem__: the X1 lying
                        __len counterpart, refused by construction),
                        every member an Event; read once into the
                        stream's own list; empty: consumed. A tuple a gate
                        read from its event's data is a tuple like any
                        other.
  Anything else (False, 0, "", a number, a str, a dict, a FrozenDict, a
  set, a list or tuple subclass, a generator, a coroutine -- an
  `async def` transform -- or any other object) is a rejection
  (SISO_NOT_EVENT). `shape` is optional, a declaration for tooling
  ({"emits": [types...], "rejects": [types...]}); the stream never reads
  it.

STREAM
  Stream() -> a stream with no gates, no residue, no observers; it takes
  no argument. Its doors are the reference's six and it has no other
  attribute: no instance __dict__, its state held in closures the doors
  share (but a running frame is reachable by ordinary attribute access:
  SUBSTRATE, Frames); the instance refuses setting or deleting an attribute (no door
  can be shadowed) and its class is locked.
  Doors:
    stream.register(gate)      building. `gate` is a Gate (else
                               TypeError("register: a gate is a Gate, got
                               <type name>")). A duplicate signature raises
                               ValueError("Signature collision: '<sig>'").
    stream.emit(event)         entry. Runs one propagation until it
                               settles and returns None, or raises a
                               rejection (below). Nothing else is raised
                               (but see SUBSTRATE: process-level exits,
                               the recursion limit).
    stream.takeResidue()       take. -> the residue as a list; the stream
                               holds none of it afterwards.
    stream.sampleHere()        sample. -> a dict {"residue": <a list of the
                               sampler's own frozen copies of the
                               residue's events (OWNERSHIP)>, "gateCount":
                               <registered gates>}. Nothing else (no event
                               counter).
    stream.attach(observer)    observe. An observer is any callable,
    stream.detach(observer)    compared by identity (`is`); see the
                               observer-seam contract for its errors.
  Residue is a multiset: its order is not part of the answer.

IDS
  Each propagation numbers its own deliveries 1, 2, ... in delivery
  order. The entry delivery is 1 and has no cause (causeId None). Nothing
  is counted across propagations, and no id survives the propagation
  except in what leaves through a door (a rejection, an observer's batch).

REJECTION
  A rejected propagation commits no residue and leaves the stream as it
  was before the entry. emit raises a Rejection: final, a subclass of
  Exception, whose fields are exactly
    code       one of the codes below
    message    a str; str(rejection) is it, and rejection.args is
               (message,)
    eventId    the delivery involved (None only for a refused entry)
    causeId    that delivery's cause (None for the entry delivery)
    eventType  that delivery's event type (None only for a refused entry)
    depth      SISO_CYCLE only: the delivery's depth on the causal chain
    cause      SISO_GATE_THREW only: exactly the exception the gate raised
  each None when its code does not carry it (present, never missing), and
  no other field. Each is set once, when it is built: assigning or
  deleting a field, or adding one, raises AttributeError (the dunder
  attributes __traceback__, __context__, __cause__, __notes__ are the
  substrate's). emit raises it `from None`, so __cause__ is None and
  __suppress_context__ is True: the gate's exception is reachable as
  `.cause` only. Rejection.isRejection(v) is isinstance(v, Rejection);
  Rejection.selfLoop, .cycle, .gateThrew and .notEvent build one, as Lua's
  core/rejection.lua.
  Codes:
    SISO_SELF_LOOP   a gate returned an event of its own type. The
                     delivery is the one whose gate returned it.
    SISO_CYCLE       a claimed delivery sits deeper than the registered
                     gate count (read when the propagation starts). The
                     delivery is that one.
    SISO_GATE_THREW  a gate raised an Exception (any subclass, RecursionError
                     and MemoryError included). The delivery is the one it
                     was given.
    SISO_NOT_EVENT   emit was given a value that is not an Event, or an
                     Event the stream cannot take its own copy of (too
                     deep for the substrate, or one built past its
                     constructor, object.__new__(Event), whose fields are
                     unset) (no delivery: eventId, causeId and eventType
                     are None), or a gate returned something that is not
                     None, an Event or a list or tuple of Events (an Event
                     whose fields are unset included). The delivery is the
                     one whose gate returned it.
  Within one returned list, members are judged in order; the first that
  is not an Event or is the gate's own type decides the rejection.
  Nothing a gate returns is dropped or repaired (Drift 7).
  Messages (Lua's templates with Python's type words):
    "Self-loop: gate '<sig>' returned its own type"
    "Cycle: '<type>' claimed at depth <d> with <n> gates registered -- a type repeated on this causal chain"
      (the dash is Lua's em dash, U+2014)
    "Gate '<sig>' threw: <str(exc)>"   (str(exc) read under protection:
                                        when it raises or is not a str,
                                        "<TypeName>" in angle brackets)
    "Not an Event: the entry door was given <what>"
    "Not an Event: gate '<sig>' returned <what> (a gate returns None, an Event or a list of Events)"
  <what>: None -> "None"; bool, int, float -> "the bool False", "the int
  42", "the float 1.5"; a str -> "a str"; any other value -> "a <type
  name> that is not an Event" (a dict, a list subclass, a coroutine...);
  a list member -> "a list whose member <n> is <what>" (n from 1); an
  entry the stream cannot copy -> "an Event too deep to copy"; an Event
  whose fields are unset -> "an Event whose fields are unset".

SUBSTRATE (Python >= 3.10, standard library only; how each Lua mechanism
maps here, what vanished and what remains)
  - Read-only by the language's own refusals: a final slotted Event whose
    __setattr__/__delattr__ raise, a FrozenDict with no mutating method,
    tuples for lists, locked classes. Lua's proxy tables, their private
    key (X1's captured key) and the view a captured key reaches vanish.
  - Python's rawset: object.__setattr__(e, "type", x) on a slotted
    instance, type.__setattr__(cls, "x", 1) on a class, object.__new__
    (Event) (an Event built past its constructor), ctypes, gc
    (get_referents reaches a FrozenDict's hidden dict), sys._getframe(n)
    .f_locals, inspect and pickle (which can call any callable). State
    reached that way is convention, the counterpart of Lua's `rawset` and
    debug library (R3) and of PHP's debug facilities (P2): named, not
    defended. The checks use object.__setattr__ as their rawset, and
    object.__new__(Event) only to show that emit still raises nothing but
    a rejection.
  - Dunder introspection is the same counterpart: a name that begins and
    ends with "__" reaches the substrate's machinery. A core class's
    dunder methods are Python functions whose attributes, __globals__ (the
    module's namespace) and __closure__ (a door's cells) are writable:
    `type(e).__init__.n = 1` and `door.__closure__[0].cell_contents`
    reach state that way. Convention. The checks walk only names that are
    not dunders (and type()).
  - The lock's anchor: a class is locked by its metaclass, and the
    metaclass is itself a class whose own metaclass is `type`; pure Python
    cannot lock it (assigning __class__ on a type is refused, and immutable
    heap types need the C API). So `type(type(e)).n = 1` writes the core's
    metaclass: a writable class reachable from every event. Convention
    (and the lock itself is the metaclass's __setattr__: one
    type.__setattr__ on the metaclass replaces it and unlocks every core
    class, the rawset counterpart above); the checks skip metaclasses. Vanishes when the core's classes are immutable types
    (built by a C extension, or when Python offers immutable heap types).
  - The standard library's classes and functions (the counterpart of
    JavaScript's realm intrinsics, J2): collections.abc.Mapping, which
    FrozenDict is registered with, is a standard-library global reached by
    import, not through the event; FrozenDict inherits nothing from it. Builtin types (str, tuple, int) cannot take attributes,
    so they are no channel. Module namespaces (builtins, sys.modules, any
    module) are writable globals reached by import, not through the event
    (convention, as Lua's globals). The checks never write a standard-
    library object; they skip them.
  - Lua's getmetatable answers become "every class reached is locked";
    Lua's data marks (kit.data.array) vanish: a list is a tuple, an
    object a FrozenDict. Immutable sentinels vanish (None), and with them
    R4.
  - Lua's R5 (host code reaches the stream's fields) thins: no instance
    __dict__, state in closures, a locked instance and class. What
    remains is the introspection above (door.__closure__, gc, frames).
  - Data is plain data, checked at construction: Lua freezes any table
    and passes functions through; a Python function, set, or class
    instance carries writable state a Lua value of plain data cannot, so
    refusing it is the thinnest honest form. Exact types only, since a
    subclass of a builtin can carry a __dict__ or override its reads.
  - Return values: an exact list or tuple, read once; a returned
    coroutine is closed by the stream under protection (no "never
    awaited" RuntimeWarning, and an exception from a started coroutine's
    finally is swallowed: the judged rejection stands); any other returned
    object is released under protection. CPython prints and ignores an
    exception raised in __del__ or in a collected generator's finally
    ("Exception ignored in", through sys.unraisablehook): a note, never a
    raise out of emit.
  - Frames: a running frame is reachable by ordinary attribute access,
    with no import and no dunder name: a generator's or coroutine's
    gi_frame / cr_frame, then f_back, then f_locals, which Python 3.13
    lets code write through. From a gate or an observer that reaches
    emit's frame, and so the stream's state (residue, gates, gateCount,
    the queue, the rejection, the batches), to read or write mid-run
    (independent review, 13.2.0). This is Python's debug library reached
    by ordinary attributes, the counterpart of Lua's `debug` global
    (which also needs no require) and PHP's debug_backtrace: convention,
    named, not defended. emit keeps the rejection and the observers list
    out of helper arguments, but its own locals are reachable. Vanishes
    when gates run outside the host's interpreter (another process or an
    isolated interpreter).
  - The recursion limit: emit called with the interpreter's recursion
    limit nearly reached can raise RecursionError before it can build a
    rejection (JavaScript's J5); a RecursionError inside a gate's frame is
    that gate's SISO_GATE_THREW.
  - Process-level exits: a BaseException that is not an Exception
    (SystemExit, KeyboardInterrupt, GeneratorExit) raised by a gate, or by
    code of a gate's objects that the stream calls (an exception's
    __str__ while the message is built, a returned generator's finally
    while it is closed, the cause's attributes while its snapshot is
    taken), is not the gate's return: it passes through emit unchanged, the propagation
    commits nothing and the stream stays usable (a substrate concession,
    like a Lua gate calling os.exit; to be recorded in the ledger).
  - Depth: every copy is iterative, so data of any depth is accepted and
    copied; the checks probe 1,000 to 100,000 levels. An entry the stream
    cannot copy (for want of memory) is SISO_NOT_EVENT.
  - Exception plumbing: a rejection's __traceback__, __context__ and
    __cause__ are the substrate's (not fields); only the host holds them.
  - What has no counterpart: Lua's `select("#", ...)` probe of emit's
    return count becomes "emit returns None"; holes and named keys in a
    returned list cannot exist in a Python list (their place is taken by
    the subclass and member probes).

rejectionProblems(r, want), checkDoors(core), checkReadonly(core) and
checkOwnership(core) run this contract against an implementation
core = {"Event": ..., "Gate": ..., "Stream": ...} and return a list of
problem strings (empty when it holds). They are what the stream phases'
checks call. No check leaves global state changed: what a probe writes is
restored, and no standard-library object is written.
"""

import abc
import collections.abc
import gc
import sys
import types
import warnings

CODES = frozenset({"SISO_SELF_LOOP", "SISO_CYCLE", "SISO_GATE_THREW", "SISO_NOT_EVENT"})

DOORS = ("register", "emit", "takeResidue", "sampleHere", "attach", "detach")

REJECTION_FIELDS = ("code", "message", "eventId", "causeId", "eventType", "depth", "cause")

# The fields a rejection carries only for one code (beyond code and message).
_EXTRA = (("SISO_CYCLE", "depth"), ("SISO_GATE_THREW", "cause"))

# Depths of the entry-copy probes (SUBSTRATE: Depth).
DEEP = (1000, 10000, 50000, 100000)

_PROBE = "sisoContractProbe"
_COUNT = "sisoContractCount"


class _Missing:
    __slots__ = ()

    def __repr__(self):
        return "<missing>"


_MISSING = _Missing()


# ── small helpers (reads only, guarded) ─────────────────────────────

def _str(v):
    try:
        s = str(v)
        if isinstance(s, str):
            return s
    except Exception:
        pass
    return "<" + type(v).__name__ + ">"


def show(v):
    """A short rendering of a value, for problem messages only."""
    try:
        s = repr(v)
        if isinstance(s, str):
            return s if len(s) <= 80 else s[:77] + "..."
    except Exception:
        pass
    return "<" + type(v).__name__ + ">"


def _typeName(v):
    return type(v).__name__


def _isInt(v):
    return type(v) is int


def _strict(a, b):
    """a and b equal with the same type (1, 1.0 and True differ), or the same object."""
    if a is b:
        return True
    if type(a) is not type(b):
        return False
    try:
        return bool(a == b)
    except Exception:
        return False


def _attr(o, name):
    try:
        return getattr(o, name)
    except Exception:
        return _MISSING


def _item(o, k):
    try:
        return o[k]
    except Exception:
        return _MISSING


def _isAny(o, objs):
    return any(o is x for x in objs)


def _dunder(name):
    return name.startswith("__") and name.endswith("__")


def _rawset(o, k, v):
    """Python's rawset: object.__setattr__ past the refusal; never raises."""
    try:
        object.__setattr__(o, k, v)
        return True
    except Exception:
        return False


def _unset(o, name):
    try:
        delattr(o, name)
        return
    except Exception:
        pass
    try:
        if isinstance(o, type):
            type.__delattr__(o, name)
        else:
            object.__delattr__(o, name)
    except Exception:
        pass


def _acceptsProbe(o):
    """True when an attribute can be set on o; what was set is removed again."""
    try:
        setattr(o, _PROBE, 1)
    except Exception:
        return False
    _unset(o, _PROBE)
    return True


def _refused(fn):
    """True when fn raised (the write was refused)."""
    try:
        fn()
    except Exception:
        return True
    return False


def _ownFields(o):
    """The non-dunder fields stored on an instance: its __dict__ keys and set slots."""
    names = set()
    try:
        d = object.__getattribute__(o, "__dict__")
    except Exception:
        d = None
    if isinstance(d, dict):
        names.update(k for k in d if isinstance(k, str))
    for c in type(o).__mro__:
        slots = c.__dict__.get("__slots__", ())
        if isinstance(slots, str):
            slots = (slots,)
        try:
            slots = tuple(slots)
        except Exception:
            slots = ()
        for n in slots:
            if not isinstance(n, str) or n in ("__dict__", "__weakref__"):
                continue
            stored = n
            if n.startswith("__") and not n.endswith("__"):
                stored = "_" + c.__name__.lstrip("_") + n
            try:
                object.__getattribute__(o, stored)
                names.add(stored)
            except Exception:
                pass
    return sorted(n for n in names if not _dunder(n))


def _fieldsOf(o):
    return ",".join(_ownFields(o))


def _hasDict(o):
    try:
        object.__getattribute__(o, "__dict__")
        return True
    except Exception:
        return False


def _emitCatch(s, e):
    """emit and catch an Exception: -> None when it settled, else what it raised."""
    try:
        s.emit(e)
        return None
    except Exception as err:
        return err


class _Quiet:
    """Records sys.unraisablehook calls and warnings while a probe runs; restores both."""

    def __enter__(self):
        self.unraisable = []
        self.warned = []
        self._hook = sys.unraisablehook
        sink = self.unraisable

        def hook(u):
            sink.append(_typeName(u.exc_value) if u.exc_value is not None else "?")

        sys.unraisablehook = hook
        self._cw = warnings.catch_warnings(record=True)
        self._log = self._cw.__enter__()
        warnings.simplefilter("always")
        return self

    def __exit__(self, *exc):
        try:
            gc.collect()
            self.warned = [_str(w.message) for w in self._log]
        finally:
            self._cw.__exit__(*exc)
            sys.unraisablehook = self._hook
        return False


# ── reach: what ordinary access reaches from a value ────────────────

_IMMUTABLE_TYPES = (type(None), bool, int, float, complex, str, bytes, range,
                    type(Ellipsis), type(NotImplemented))
_OPAQUE = (types.BuiltinFunctionType, types.MethodWrapperType, types.WrapperDescriptorType,
           types.MethodDescriptorType, types.ClassMethodDescriptorType,
           types.MemberDescriptorType, types.GetSetDescriptorType, types.MethodType)
_MUTABLE = (dict, list, set, bytearray, types.SimpleNamespace, collections.deque)


_HEAPTYPE = 1 << 9
_IMMUTABLETYPE = 1 << 8


def _resolve(mod, qualname):
    obj = mod
    for part in qualname.split("."):
        obj = getattr(obj, part, _MISSING)
        if obj is _MISSING:
            return _MISSING
    return obj


def isSubstrate(o):
    """True for a class, function or object of the standard library (never judged, never written).

    A class is the substrate's when it is a static or an immutable type
    (Python code can create neither), when it claims a standard-library
    module written in C, or when it claims a standard-library module and
    that module reaches it by its qualified name. `__module__` alone is not
    trusted: a class created by a metaclass's __new__ (ABCMeta's, say)
    claims the metaclass's module.
    """
    if isinstance(o, types.FunctionType):
        target = o
    elif isinstance(o, type):
        target = o
    else:
        target = type(o)
    try:
        modName = target.__module__
        qualname = target.__qualname__
    except Exception:
        return False
    if isinstance(target, type):
        flags = target.__flags__
        if not flags & _HEAPTYPE or flags & _IMMUTABLETYPE:
            return True
    if not isinstance(modName, str) or modName.split(".")[0] not in sys.stdlib_module_names:
        return False
    mod = sys.modules.get(modName)
    if mod is None:
        return False
    if isinstance(target, type):
        f = getattr(mod, "__file__", None)
        if not (isinstance(f, str) and f.endswith(".py")):
            return True  # a type of a module written in C
    return isinstance(qualname, str) and _resolve(mod, qualname) is target


_substrate = isSubstrate


def _names(o):
    try:
        names = dir(o)
    except Exception:
        return []
    return [n for n in names if isinstance(n, str) and not _dunder(n)]


def _walk(roots, problems=None, targets=None):
    """Walks what ordinary access (non-dunder names, items, type()) reaches from roots.

    problems: a list the walk appends to (writable state found; a class or
    object that accepts an attribute is probed and the probe removed).
    targets: a list of the core classes and core objects reached (no
    metaclass, nothing of the standard library). Iterative.
    """
    def report(s):
        if problems is not None:
            problems.append(s)

    seen = {}
    stack = list(reversed(roots))
    while stack:
        o, path = stack.pop()
        if id(o) in seen:
            continue
        seen[id(o)] = o
        t = type(o)
        if t in _IMMUTABLE_TYPES or isinstance(o, _OPAQUE):
            continue
        if t is tuple or t is frozenset:
            for i, x in enumerate(o):
                stack.append((x, path + "[" + str(i) + "]"))
            continue
        if t is types.MappingProxyType:
            for k, x in list(o.items()):
                stack.append((x, path + "[" + show(k) + "]"))
            continue
        if isinstance(o, _MUTABLE):
            report(path + " is a " + t.__name__ + " (mutable: state anyone can write)")
            continue
        if isinstance(o, types.FunctionType):
            if not _substrate(o):
                report(path + " is a function (its attributes are writable state)")
            continue
        if isinstance(o, type):
            if _substrate(o) or issubclass(o, type):
                continue  # the standard library's own classes; the lock's anchor (SUBSTRATE)
            if targets is not None:
                targets.append(o)
            if problems is not None and _acceptsProbe(o):
                report(path + " is not locked (an attribute can be set on the class: state shared by everything that reaches it)")
            for n in _names(o):
                v = _attr(o, n)
                if v is not _MISSING:
                    stack.append((v, path + "." + n))
            continue
        if _substrate(o):
            try:
                if t.__dictoffset__:
                    report(path + " is a " + t.__name__ + " with a __dict__ (state anyone can write)")
            except Exception:
                pass
            continue
        if targets is not None:
            targets.append(o)
        if problems is not None and _acceptsProbe(o):
            report(path + " accepts attributes (state anyone can write)")
        if isinstance(o, collections.abc.Mapping):
            try:
                items = list(o.items())
            except Exception:
                items = []
            for k, x in items:
                stack.append((x, path + "[" + show(k) + "]"))
        for n in _names(o):
            v = _attr(o, n)
            if v is not _MISSING:
                stack.append((v, path + "." + n))
        stack.append((t, "type(" + path + ")"))


# ── rejections ──────────────────────────────────────────────────────

def rejectionProblems(r, want=None):
    """rejectionProblems(r, want) -> list of problems.

    `want` (optional) pins fields: {"code", "eventId", "causeId",
    "eventType", "depth", "cause"}; a key present in `want` is compared
    strictly (same type and value; `cause` by identity), "noDelivery":
    True requires eventId, causeId and eventType to be None, and "entry":
    True requires causeId to be None.
    """
    if not isinstance(r, BaseException):
        return ["a rejection is an exception, got " + _typeName(r)]
    p = []
    if not isinstance(r, Exception):
        p.append("a rejection is not an Exception (a process-level exit)")
    if type(r).__name__ != "Rejection":
        p.append("a rejection's class is " + type(r).__name__ + ", not Rejection")
    f = {}
    for k in REJECTION_FIELDS:
        f[k] = _attr(r, k)
        if f[k] is _MISSING:
            p.append("missing field '" + k + "' (a field its code does not carry is None)")
            f[k] = None
    for k in _ownFields(r):
        if k not in REJECTION_FIELDS:
            p.append("unexpected field '" + k + "'")
    code = f["code"]
    if not (type(code) is str and code in CODES):
        p.append("unknown code " + show(code))
    message = f["message"]
    if type(message) is not str:
        p.append("message is not a str")
    else:
        try:
            rendered = str(r)
        except Exception:
            rendered = _MISSING
        if rendered != message:
            p.append("str(rejection) is not its message")
        if _attr(r, "args") != (message,):
            p.append("rejection.args is not (message,)")
    for c, field in _EXTRA:
        if code != c and f[field] is not None:
            p.append(field + " is carried only by " + c)
    if code == "SISO_CYCLE" and not _isInt(f["depth"]):
        p.append("SISO_CYCLE carries an integer depth")
    if code == "SISO_GATE_THREW" and not isinstance(f["cause"], Exception):
        p.append("SISO_GATE_THREW carries the exception the gate raised")
    for k in ("eventId", "causeId"):
        if f[k] is not None and not _isInt(f[k]):
            p.append(k + " is not an int")
    if f["eventType"] is not None and type(f["eventType"]) is not str:
        p.append("eventType is not a str")
    if want:
        for k in ("code", "eventId", "causeId", "eventType", "depth", "cause"):
            if k in want:
                same = (f[k] is want[k]) if k == "cause" else _strict(f[k], want[k])
                if not same:
                    p.append(k + ": expected " + show(want[k]) + ", got " + show(f[k]))
        if want.get("noDelivery") and (f["eventId"] is not None or f["causeId"] is not None
                                       or f["eventType"] is not None):
            p.append("a refused entry names no delivery")
        if want.get("entry") and f["causeId"] is not None:
            p.append("the entry delivery has no cause")
    return p


# ── running the contract against an implementation ──────────────────

def _coreProblems(core):
    p = []
    if not isinstance(core, collections.abc.Mapping):
        return ["core is a mapping {'Event', 'Gate', 'Stream'}, got " + _typeName(core)]
    for k in ("Event", "Gate", "Stream"):
        if not callable(core.get(k)):
            p.append("core['" + k + "'] is not callable")
    return p


def _add(p, label, lst):
    for s in lst:
        p.append(label + ": " + s)


class _BadStr(Exception):
    def __str__(self):
        raise RuntimeError("str() refused")


class _NotStr(Exception):
    def __str__(self):
        return 42  # str() raises TypeError: __str__ returned non-string


class _DelRaises:
    def __del__(self):
        raise RuntimeError("released")


class _Yield:
    def __await__(self):
        yield


def checkDoors(core):
    p = _coreProblems(core)
    if p:
        return p
    Event, Gate, Stream = core["Event"], core["Gate"], core["Stream"]
    fail = p.append

    def guard(label, fn):
        try:
            fn()
        except Exception as err:
            fail(label + ": raised " + _typeName(err) + ": " + _str(err))

    def gate(sig, fn=None, shape=None):
        return Gate(sig, fn, shape)

    def event():
        e = Event("t", {"x": 1})
        if _fieldsOf(e) != "data,type":
            fail("Event fields are " + _fieldsOf(e))
        if _hasDict(e):
            fail("an event has a __dict__ (its fields are not exactly type and data)")
        if _attr(e, "type") != "t" or _item(_attr(e, "data"), "x") != 1:
            fail("Event does not hold type and data")
        d = Event("t").data
        if not isinstance(d, collections.abc.Mapping) or isinstance(d, dict) or len(d) != 0:
            fail("Event data does not default to an empty frozen mapping")
        if not _refused(lambda: Event("t", {}, {"attempt": 1})):
            fail("an extra argument to Event was accepted (it is refused, never dropped)")
        if Event.isEvent(e) is not True:
            fail("Event.isEvent(event) is not True")
        if Event.isEvent(Event("u", Event("t", {"x": 1}).data)) is not True:
            fail("Event.isEvent(an event built of another event's data) is not True")
        lookalikes = [
            ("a dict", {"type": "t", "data": {}}),
            ("a SimpleNamespace", types.SimpleNamespace(type="t", data=e.data)),
            ("an event's data", e.data),
            ("a Gate", Gate("t")),
            ("the Event class", Event),
        ]
        for label, v in lookalikes:
            if Event.isEvent(v) is not False:
                fail("Event.isEvent accepts " + label)
        for v in (None, 0, "t", True, ()):
            try:
                if Event.isEvent(v) is not False:
                    fail("Event.isEvent(" + show(v) + ") is not False")
            except Exception as err:
                fail("Event.isEvent(" + show(v) + ") raised " + _typeName(err))
        if not _refused(lambda: Event(1)):
            fail("Event accepted a type that is not a str")
        try:
            Event(1)
        except TypeError:
            pass
        except Exception as err:
            fail("Event(1) raised " + _typeName(err) + ", not TypeError")
        for label, v in (("a list", [1]), ("an int", 5)):
            try:
                Event("t", v)
                fail("Event accepted " + label + " as its data (data is a mapping)")
            except TypeError:
                pass
            except Exception as err:
                fail("Event refused " + label + " as data with " + _typeName(err) + ", not TypeError")

    guard("Event", event)

    def gateCheck():
        def fn(e):
            return None
        shape = {"emits": [], "rejects": []}
        g = Gate("g", fn, shape)
        if _attr(g, "signature") != "g" or _attr(g, "transform") is not fn or _attr(g, "shape") is not shape:
            fail("Gate(signature, transform, shape) does not hold its three fields as given")
        if _fieldsOf(g) != "shape,signature,transform":
            fail("a gate's fields are " + _fieldsOf(g))
        if not _refused(lambda: setattr(g, "signature", "z")) or not _refused(lambda: setattr(g, "extra", 1)):
            fail("a gate accepts assignment (it is frozen)")
        if not _refused(lambda: delattr(g, "transform")):
            fail("a gate accepts deleting a field")
        noop = Gate("n")
        if not callable(_attr(noop, "transform")) or noop.transform(Event("n")) is not None:
            fail("a gate with no transform is not a no-op")
        for label, args in (("a signature that is not a str", (1,)), ("a transform that is not callable", ("g", 42))):
            try:
                Gate(*args)
                fail("Gate accepted " + label)
            except TypeError:
                pass
            except Exception as err:
                fail("Gate refused " + label + " with " + _typeName(err) + ", not TypeError")

    guard("Gate", gateCheck)

    def doors():
        s = Stream()
        for d in DOORS:
            if not callable(_attr(s, d)):
                fail("stream has no door " + d)
        extra = [n for n in _names(s) if n not in DOORS]
        if extra:
            fail("a stream's attributes besides its doors are " + ",".join(extra) +
                 " (its state is reachable past the doors)")
        if _hasDict(s):
            fail("a stream instance has a __dict__ (its state is reachable past the doors)")
        before = _attr(s, "emit")
        shadowed = not _refused(lambda: setattr(s, "emit", lambda e: None))
        added = not _refused(lambda: setattr(s, _PROBE, 1))
        deleted = not _refused(lambda: delattr(s, "takeResidue"))
        if shadowed or added or deleted:
            fail("a stream instance accepts setting or deleting an attribute (a door can be shadowed)")
        if shadowed:
            _rawset(s, "emit", before)
        sm = s.sampleHere()
        if type(sm) is not dict:
            fail("sampleHere returns a " + _typeName(sm) + ", not a dict")
        elif ",".join(sorted(str(k) for k in sm)) != "gateCount,residue":
            fail("sampleHere carries " + ",".join(sorted(str(k) for k in sm)))
        elif type(sm["residue"]) is not list:
            fail("sampleHere's residue is not a list")
        if not _refused(lambda: Stream(1)):
            fail("Stream accepted an argument (it takes none)")

    guard("Stream doors", doors)

    def register():
        s = Stream()
        s.register(gate("a"))
        try:
            s.register(gate("a"))
            fail("a duplicate signature was accepted")
        except ValueError as err:
            if _str(err) != "Signature collision: 'a'":
                fail("collision message is " + show(_str(err)))
        except Exception as err:
            fail("a collision is " + _typeName(err) + ", not ValueError")
        if s.sampleHere()["gateCount"] != 1:
            fail("gateCount is not the registered count")
        look = types.SimpleNamespace(signature="b", transform=lambda e: None, shape=None)
        try:
            s.register(look)
            fail("register accepted an object that is not a Gate")
        except TypeError:
            pass
        except Exception as err:
            fail("register refused an object that is not a Gate with " + _typeName(err) + ", not TypeError")
        if s.sampleHere()["gateCount"] != 1:
            fail("a refused registration changed gateCount")

    guard("register", register)

    def emitAndTake():
        s = Stream()
        calls = []

        def a(*args, **kwargs):
            calls.append((len(args), len(kwargs)))
            return Event("b", {"v": args[0].data["v"]})

        s.register(gate("a", a))
        if s.emit(Event("a", {"v": 7})) is not None:
            fail("emit returned a value")
        if calls != [(1, 0)]:
            fail("a gate was called with " + show(calls) + " (positional, keyword) arguments, not the event alone")
        smp = s.sampleHere()
        if len(smp["residue"]) != 1 or _attr(smp["residue"][0], "type") != "b":
            fail("residue after a settled chain")
        smp["residue"].pop()
        if len(s.sampleHere()["residue"]) != 1:
            fail("a sample's list reached the stream")
        taken = s.takeResidue()
        if type(taken) is not list or len(taken) != 1 or _item(_attr(taken[0], "data"), "v") != 7:
            fail("takeResidue did not return the residue as a list")
        if len(s.sampleHere()["residue"]) != 0:
            fail("the stream still holds taken residue")
        if type(taken) is list:
            taken.append(Event("pushed"))
        if len(s.sampleHere()["residue"]) != 0:
            fail("a write to the taken list reached the stream")

    guard("emit and take", emitAndTake)

    def returns():
        s = Stream()
        s.register(gate("one", lambda e: Event("x")))
        s.register(gate("many", lambda e: [Event("x"), Event("y")]))
        s.register(gate("pair", lambda e: (Event("x"), Event("y"))))
        s.register(gate("none", lambda e: None))
        s.register(gate("empty", lambda e: []))
        s.register(gate("nothing", lambda e: ()))
        for t in ("one", "many", "pair", "none", "empty", "nothing"):
            s.emit(Event(t))
        if len(s.takeResidue()) != 5:
            fail("None / Event / list / tuple / empty returns do not settle as declared")
        c = Stream()
        c.register(gate("carry", lambda e: e.data["outs"]))
        c.emit(Event("carry", {"outs": [Event("x"), Event("y")]}))
        if len(c.takeResidue()) != 2:
            fail("a tuple of Events read from the event's data is not judged as a list")

    guard("returns", returns)

    def rej(label, s, entry, want):
        before = len(s.sampleHere()["residue"])
        r = _emitCatch(s, entry)
        if r is None:
            fail(label + ": settled, expected " + want["code"])
            return
        _add(p, label, rejectionProblems(r, want))
        if len(s.sampleHere()["residue"]) != before:
            fail(label + ": a rejection committed residue")

    def rejections():
        s = Stream()
        s.emit(Event("earlier"))
        s.register(gate("loop", lambda e: [Event("other"), Event("loop")]))
        rej("self-loop", s, Event("loop"), {"code": "SISO_SELF_LOOP", "eventId": 1, "eventType": "loop", "entry": True})

        c = Stream()
        c.register(gate("a", lambda e: Event("b")))
        c.register(gate("b", lambda e: Event("a")))
        rej("cycle", c, Event("a"), {"code": "SISO_CYCLE", "eventId": 3, "causeId": 2, "eventType": "a", "depth": 3})

        t = Stream()
        thrown = RuntimeError("the gate's own value")

        def b(e):
            raise thrown

        t.register(gate("a", lambda e: Event("b")))
        t.register(gate("b", b))
        rej("gate threw", t, Event("a"), {"code": "SISO_GATE_THREW", "eventId": 2, "causeId": 1, "eventType": "b", "cause": thrown})
        # ids are per propagation: the same entry names the same deliveries again
        rej("gate threw, again", t, Event("a"), {"code": "SISO_GATE_THREW", "eventId": 2, "causeId": 1})
        r = _emitCatch(t, Event("b"))
        if r is not None and (_attr(r, "__cause__") is not None or _attr(r, "__suppress_context__") is not True):
            fail("gate threw: the rejection is not raised from None (the gate's exception is reachable past .cause)")

        def raiser(exc):
            def g(e):
                raise exc
            return g

        def runaway(e):
            return runaway(e)

        x = Stream()
        x.register(gate("rec", raiser(RecursionError("probe"))))
        rej("a gate's RecursionError", x, Event("rec"), {"code": "SISO_GATE_THREW", "eventId": 1})
        x.register(gate("runaway", runaway))
        r = _emitCatch(x, Event("runaway"))
        if r is None or not isinstance(_attr(r, "cause"), RecursionError):
            fail("a gate that recursed without end: " + ("settled" if r is None else "raised " + _typeName(r) +
                 " " + show(_attr(r, "code"))) + ", expected SISO_GATE_THREW with its RecursionError")
        else:
            _add(p, "a gate that recursed without end", rejectionProblems(r, {"code": "SISO_GATE_THREW", "eventId": 1}))
        x.register(gate("mem", raiser(MemoryError("probe"))))
        rej("a gate's MemoryError", x, Event("mem"), {"code": "SISO_GATE_THREW", "eventId": 1})
        x.register(gate("badstr", raiser(_BadStr())))
        rej("a gate's exception whose __str__ raises", x, Event("badstr"), {"code": "SISO_GATE_THREW", "eventId": 1})
        x.register(gate("notstr", raiser(_NotStr())))
        rej("a gate's exception whose __str__ is not a str", x, Event("notstr"), {"code": "SISO_GATE_THREW", "eventId": 1})

        n = Stream()
        rej("entry not an Event", n, {"type": "x", "data": {}}, {"code": "SISO_NOT_EVENT", "noDelivery": True})
        rej("entry a look-alike", n, types.SimpleNamespace(type="x", data=Event("x").data),
            {"code": "SISO_NOT_EVENT", "noDelivery": True})
        rej("entry None", n, None, {"code": "SISO_NOT_EVENT", "noDelivery": True})
        rej("entry the Event class", n, Event, {"code": "SISO_NOT_EVENT", "noDelivery": True})
        try:
            forged = object.__new__(Event)
        except Exception:
            forged = _MISSING
        if forged is not _MISSING:
            rej("entry an Event built past its constructor", n, forged, {"code": "SISO_NOT_EVENT", "noDelivery": True})
            n.register(gate("forge", lambda e: forged))
            rej("output an Event built past its constructor", n, Event("forge"), {"code": "SISO_NOT_EVENT", "eventId": 1})
        n.register(gate("bad", lambda e: [Event("ok"), {"type": "fake"}]))
        rej("output not an Event", n, Event("bad"), {"code": "SISO_NOT_EVENT", "eventId": 1, "eventType": "bad"})
        n.register(gate("withnone", lambda e: [Event("ok"), None]))
        rej("output a list with a None member", n, Event("withnone"), {"code": "SISO_NOT_EVENT", "eventId": 1})

        class Liar(list):
            def __iter__(self):
                return iter([])

            def __len__(self):
                return 0

        class Pair(tuple):
            pass

        n.register(gate("liar", lambda e: Liar([Event("ok"), 42])))
        rej("output a list subclass whose __iter__ lies", n, Event("liar"), {"code": "SISO_NOT_EVENT", "eventId": 1})
        n.register(gate("tsub", lambda e: Pair((Event("ok"),))))
        rej("output a tuple subclass", n, Event("tsub"), {"code": "SISO_NOT_EVENT", "eventId": 1})
        for sig, label, value in (
            ("num", "a number", 42),
            ("f", "False", False),
            ("zero", "0", 0),
            ("text", "an empty str", ""),
            ("dict", "a dict", {"type": "x"}),
            ("set", "a set", {1}),
        ):
            n.register(gate(sig, (lambda v: lambda e: v)(value)))
            rej("output " + label, n, Event(sig), {"code": "SISO_NOT_EVENT", "eventId": 1})
        n.register(gate("fd", lambda e: e.data))
        rej("output a FrozenDict (its event's data)", n, Event("fd", {"k": 1}), {"code": "SISO_NOT_EVENT", "eventId": 1})

        def gen(e):
            yield Event("late")

        n.register(gate("gen", gen))
        rej("output a generator", n, Event("gen"), {"code": "SISO_NOT_EVENT", "eventId": 1})

        # what a gate returned is closed or released inside the stream's
        # protection: nothing but the judged rejection leaves, and no
        # coroutine is left unawaited
        async def coro(e):
            return Event("late")

        async def startedBody():
            try:
                await _Yield()
            finally:
                raise RuntimeError("released")

        def started(e):
            c_ = startedBody()
            c_.send(None)
            return c_

        def startedGen(e):
            def g():
                try:
                    yield 1
                finally:
                    raise RuntimeError("released")
            it = g()
            next(it)
            return it

        q = _Quiet()
        with q:
            n.register(gate("async", coro))
            rej("output a coroutine (an async gate)", n, Event("async"), {"code": "SISO_NOT_EVENT", "eventId": 1})
            n.register(gate("started", started))
            rej("output a started coroutine whose finally raises", n, Event("started"), {"code": "SISO_NOT_EVENT", "eventId": 1})
            n.register(gate("del", lambda e: _DelRaises()))
            rej("output an object whose __del__ raises", n, Event("del"), {"code": "SISO_NOT_EVENT", "eventId": 1})
            n.register(gate("sgen", startedGen))
            rej("output a started generator whose finally raises", n, Event("sgen"), {"code": "SISO_NOT_EVENT", "eventId": 1})
        if any("never awaited" in w for w in q.warned):
            fail("output a coroutine: the stream did not close it (RuntimeWarning: never awaited)")

        # members in order: the first that decides
        n.register(gate("second", lambda e: [42, Event("second")]))
        rej("list members judged in order", n, Event("second"), {"code": "SISO_NOT_EVENT", "eventId": 1})
        n.register(gate("ownfirst", lambda e: [Event("ownfirst"), 42]))
        rej("a self-loop judged before a later member", n, Event("ownfirst"), {"code": "SISO_SELF_LOOP", "eventId": 1})

        # a process-level exit is not a gate's return: it passes through
        xs = Stream()
        exitProbe = SystemExit("siso contract probe")

        def exits(e):
            raise exitProbe

        xs.register(gate("pre", lambda e: Event("exit")))
        xs.register(gate("exit", exits))
        got = None
        try:
            xs.emit(Event("pre"))
        except BaseException as other:  # the probe's own exit, caught here
            got = other
        if got is not exitProbe:
            fail("a process-level exit raised by a gate did not pass through emit unchanged (got " +
                 ("nothing" if got is None else _typeName(got)) + ")")
        if len(xs.sampleHere()["residue"]) != 0:
            fail("a process-level exit committed residue")
        xs.emit(Event("fine"))
        if len(xs.takeResidue()) != 1:
            fail("the stream is not usable after a process-level exit")

        u = Stream()

        def boom(e):
            raise RuntimeError("x")

        u.register(gate("boom", boom))
        _emitCatch(u, Event("boom"))
        u.emit(Event("fine"))
        if len(u.takeResidue()) != 1:
            fail("the stream is not usable after a rejection")

    guard("rejections", rejections)
    return p


class _Opaque:
    def __init__(self):
        self.v = 1


class _Sub(dict):
    pass


class _ListSub(list):
    pass


class _StrSub(str):
    pass


class _IntSub(int):
    pass


def checkReadonly(core):
    p = _coreProblems(core)
    if p:
        return p
    Event, Gate, Stream = core["Event"], core["Gate"], core["Stream"]
    fail = p.append

    def refuses(label, fn):
        if not _refused(fn):
            fail(label + " was accepted")

    try:
        # read-only from construction: before any stream sees it
        orig = {"v": 1, "list": [1]}
        born = Event("a", orig)
        spare = Event("p", {"o": {"k": 1}})
        refuses("writing a field of a new event", lambda: setattr(spare, "type", "z"))
        refuses("adding a field to a new event", lambda: setattr(spare, "extra", 1))
        refuses("deleting a field of a new event", lambda: delattr(spare, "data"))
        refuses("writing a new event's data", lambda: born.data.__setitem__("v", 2))
        refuses("deleting from a new event's data", lambda: born.data.__delitem__("v"))
        refuses("writing into a new event's nested data", lambda: born.data["list"].__setitem__(0, 0))
        refuses("appending to a new event's nested list", lambda: born.data["list"].append(2))
        refuses("writing an attribute of a new event's data", lambda: setattr(born.data, "extra", 1))
        refuses("writing a new event's default data", lambda: Event("t").data.__setitem__("x", 1))
        try:
            Event("q").type = "z"
        except AttributeError as err:
            want = "read-only: an event and its data cannot be assigned (field 'type')"
            if _str(err) != want:
                fail("assigning an event's field raised " + show(_str(err)) + ", not " + show(want))
        except Exception as err:
            fail("assigning an event's field raised " + _typeName(err) + ", not AttributeError")
        for m in ("update", "pop", "popitem", "setdefault", "clear", "__setitem__", "__delitem__", "__ior__"):
            if _attr(born.data, m) is not _MISSING:
                fail("an event's data has a mutating method '" + m + "'")
        if _fieldsOf(born) != "data,type":
            fail("a new event's fields are " + _fieldsOf(born))
        d = born.data
        if not isinstance(d, collections.abc.Mapping) or isinstance(d, dict):
            fail("an event's data is a " + _typeName(d) + ", not a frozen Mapping")
        if d is orig or _item(d, "list") is orig["list"]:
            fail("an event holds the object passed to Event (a new event's data is not its own)")
        if type(_item(d, "list")) is not tuple:
            fail("a list in an event's data reads back as a " + _typeName(_item(d, "list")) + ", not a tuple")
        orig["v"] = 2
        orig["list"][0] = 9
        orig["late"] = True
        if _item(d, "v") != 1 or _item(_item(d, "list"), 0) != 1 or _item(d, "late") is not _MISSING:
            fail("a write to the dict passed to Event reached the event")
        if orig != {"v": 2, "list": [9], "late": True}:
            fail("Event changed the caller's own dict")
        reads = (d.get("v") == 1 and d.get("zz") is None and sorted(d.keys()) == ["list", "v"]
                 and len(d) == 2 and "v" in d and sorted(iter(d)) == ["list", "v"]
                 and sorted(d.items()) == [("list", (1,)), ("v", 1)] and d == {"v": 1, "list": (1,)})
        if not reads:
            fail("reads (get, keys, items, len, in, iteration, ==) do not work on an event's data")
        if _item(d, "list") is not _item(d, "list"):
            fail("two reads of one field give different objects")
        inner = Event("i")
        carried = Event("o", {"inner": inner, "d": born.data, "n": None})
        if _item(carried.data, "inner") is not inner:
            fail("an event carried in data lost its identity")
        if _item(carried.data, "d") is not born.data:
            fail("read-only data passed on lost its identity")
        if _item(carried.data, "n") is not None:
            fail("None in new data is not None")
        shared = {"s": 1}
        lst = [1, 2]
        sc = Event("c", {"shared": shared, "again": shared, "l": lst, "l2": lst})
        if _item(sc.data, "shared") is not _item(sc.data, "again") or _item(sc.data, "l") is not _item(sc.data, "l2"):
            fail("sharing inside the data was not preserved")
        if not Event.isEvent(born):
            fail("Event.isEvent does not recognise a new event")
        try:
            type("Sub", (Event,), {})
            fail("a subclass of Event was accepted (Event is final)")
        except Exception:
            pass

        # plain data only, refused with the contract's message, never dropped
        cyc = {}
        cyc["self"] = cyc
        cyclic = [1]
        cyclic.append(cyclic)

        def gen():
            yield 1

        Point = collections.namedtuple("Point", "x y")
        refusals = [
            ("a set", {1}),
            ("a frozenset", frozenset({1})),
            ("bytes", b"x"),
            ("a bytearray", bytearray(b"x")),
            ("a complex", 1j),
            ("a function", lambda: 1),
            ("a class", int),
            ("a module", sys),
            ("a class instance", _Opaque()),
            ("an exception", RuntimeError("x")),
            ("a dict subclass", _Sub(k=1)),
            ("an OrderedDict", collections.OrderedDict(k=1)),
            ("a list subclass", _ListSub([1])),
            ("a namedtuple", Point(1, 2)),
            ("a str subclass", _StrSub("s")),
            ("an int subclass", _IntSub(1)),
            ("a generator", gen()),
            ("a MappingProxyType", types.MappingProxyType({"k": 1})),
            ("a non-str key", {1: "x"}),
            ("a tuple key", {("a",): "x"}),
            ("a cycle", cyc),
            ("a cyclic list", cyclic),
        ]
        for label, value in refusals:
            try:
                e = Event("t", {"x": value})
            except TypeError as err:
                if "not plain data" not in _str(err):
                    fail("Event refused " + label + " with " + show(_str(err)) + ", not naming \"not plain data\"")
                continue
            except Exception as err:
                fail("Event refused " + label + " with " + _typeName(err) + ", not TypeError")
                continue
            kept = _item(e.data, "x")
            dropped = ""
            if (isinstance(kept, collections.abc.Mapping) and isinstance(value, collections.abc.Mapping)
                    and len(kept) < len(value)):
                dropped = " (it was dropped: Drift 7)"
            fail("Event accepted " + label + " in data (not plain data)" + dropped)
        for value, want in (
            ({"a": [set()]}, "Event: data at data['a'][0] is not plain data (set)"),
            ({"a": [{1: 2}]}, "Event: data at data['a'][0] is not plain data (a key of type int)"),
            ({"c": cyc}, "Event: data at data['c']['self'] is not plain data (a cycle)"),
        ):
            try:
                Event("t", value)
            except Exception as err:
                if _str(err) != want:
                    fail("Event's refusal reads " + show(_str(err)) + ", not " + show(want))

        # stateless through the event: every class and object ordinary
        # access reaches from an event or the core's exports is immutable
        # or locked (SUBSTRATE: dunders, the metaclass and the standard
        # library are not judged)
        rs = Stream()

        def rboom(e):
            raise RuntimeError("r")

        rs.register(Gate("r", rboom))
        raised = _emitCatch(rs, Event("r"))
        roots = [(Event, "Event"), (Gate, "Gate"), (Stream, "Stream"), (type(born.data), "type(event.data)"),
                 (Gate("g").transform, "a gate's default transform"), (born, "a new event"),
                 (carried, "an event carrying events and data"), (sc, "an event with shared data")]
        if isinstance(raised, BaseException):
            roots.append((type(raised), "type(rejection)"))
        walked = []
        _walk(roots, problems=walked)
        for w in walked:
            fail(w)
        if isinstance(raised, BaseException):
            refuses("assigning a rejection's field", lambda: setattr(raised, "code", "X"))
            refuses("adding a field to a rejection", lambda: setattr(raised, "extra", 1))
            refuses("deleting a rejection's field", lambda: delattr(raised, "eventId"))
            if _attr(raised, "code") != "SISO_GATE_THREW":
                fail("a write to a rejection's field reached it")
            try:
                type("Sub", (type(raised),), {})
                fail("a subclass of Rejection was accepted (Rejection is final)")
            except Exception:
                pass

        # the count probe: a gate that counts through everything writable
        # its event reaches (class attributes, the ABC registry), from one
        # stream to the next; what it wrote is removed afterwards
        written = []
        registered = []
        probes = tuple(type("Probe" + str(i), (), {}) for i in range(4))

        def countGate(e):
            n = 0
            targets = []
            _walk([(e, "event")], targets=targets)
            for t in targets:
                try:
                    v = getattr(t, _COUNT, 0) + 1
                    setattr(t, _COUNT, v)
                    written.append(t)
                    n += v
                except Exception:
                    pass
                if isinstance(t, abc.ABCMeta):
                    # both t.register and the metaclass's own register
                    # (what super(type(t), t).register reaches): a lock on
                    # t.register alone is not enough (review, 13.2.0)
                    k = sum(1 for x in probes if issubclass(x, t))
                    for reg in (lambda c: t.register(c), lambda c: abc.ABCMeta.register(t, c)):
                        try:
                            reg(probes[min(k, len(probes) - 1)])
                            registered.append(t)
                            n += k
                            break
                        except Exception:
                            pass
            return Event("counted", {"n": n})

        def countRun():
            cs = Stream()
            cs.register(Gate("count", countGate))
            cs.emit(Event("count", {"e": Event("inner"), "l": [1]}))
            return _item(_attr(cs.takeResidue()[0], "data"), "n")

        try:
            countRun()
            if countRun() != 0:
                fail("a gate carried a count from one stream to another through what its event reaches")
        finally:
            for t in written:
                _unset(t, _COUNT)
            for t in registered:
                try:
                    t._abc_registry_clear()
                    t._abc_caches_clear()
                except Exception:
                    pass

        seen = []
        s = Stream()

        def a(e):
            seen.append(e)
            refuses("a gate writing its event's data", lambda: e.data.__setitem__("v", 2))
            refuses("a gate writing into nested data", lambda: e.data["list"].__setitem__(0, 0))
            refuses("a gate writing its event's field", lambda: setattr(e, "type", "z"))
            if e.data["list"] is not e.data["list"]:
                fail("two reads of one field give different objects")
            return Event("b", {"list": [1, 2], "v": e.data["v"], "n": None})

        s.register(Gate("a", a))
        s.emit(Event("a", {"v": 1, "list": [1, 2]}))
        if not seen:
            fail("the gate was not called")
            return p
        refuses("writing a field of an event a gate was given", lambda: setattr(seen[0], "type", "z"))
        if not Event.isEvent(seen[0]):
            fail("Event.isEvent does not recognise an event a gate was given")
        r = s.sampleHere()["residue"][0]
        if _item(r.data, "n") is not None:
            fail("None lost its identity")
        if not Event.isEvent(r):
            fail("Event.isEvent does not recognise a sampled event")
        refuses("writing a sampled event's data", lambda: r.data.__setitem__("v", 99))
        refuses("writing into a sampled event's nested data", lambda: r.data["list"].__setitem__(0, 9))
        refuses("writing a sampled event's field", lambda: setattr(r, "data", {}))
        if _item(s.sampleHere()["residue"][0].data, "v") != 1:
            fail("a sample's write reached the stream")
        lst2 = r.data["list"]
        if len(lst2) != 2 or lst2[1] != 2 or type(lst2) is not tuple:
            fail("len and indexing do not read a frozen list")
        if sum(x for x in lst2) != 3 or list(lst2) != [1, 2]:
            fail("iteration does not read a frozen list")
        if len(r.data) != 3 or sorted(r.data) != ["list", "n", "v"]:
            fail("len and iteration do not read frozen data")
        t = s.takeResidue()[0]
        refuses("writing a taken event's data", lambda: t.data.__setitem__("v", 5))
        more = []
        _walk([(seen[0], "an event a gate was given"), (r, "a sampled event"), (t, "a taken event")], problems=more)
        for w in more:
            fail(w)
    except Exception as err:
        fail("raised " + _typeName(err) + ": " + _str(err))
    return p


def checkOwnership(core):
    p = _coreProblems(core)
    if p:
        return p
    Event, Gate, Stream = core["Event"], core["Gate"], core["Stream"]
    fail = p.append
    try:
        s = Stream()
        s.emit(Event("x", {"v": 1, "n": [1], "e": Event("in", {"w": 1})}))
        a = s.sampleHere()["residue"][0]
        aData = _attr(a, "data")
        aE = _item(aData, "e")
        held = [a, aData, aE]
        # Python's rawset on the sampled copy (SUBSTRATE)
        _rawset(a, "type", "z")
        _rawset(a, "data", {"v": 7, "n": [9]})
        _rawset(aE, "type", "zz")
        b = s.sampleHere()["residue"][0]
        bData = _attr(b, "data")
        if _attr(b, "type") != "x":
            fail("a rawset on a sampled event reached the stream")
        if _item(bData, "v") != 1 or _item(_item(bData, "n"), 0) != 1:
            fail("a rawset on a sampled event's data reached the stream")
        if _isAny(b, held) or _isAny(bData, held):
            fail("two samples hand out the same object (what one sampler does to it reaches the stream)")
        bE = _item(bData, "e")
        if _isAny(bE, held) or _attr(bE, "type") != "in":
            fail("an event in a sampled event's data reached the stream (two samples share it)")
        if not _refused(lambda: bData.__setitem__("v", 2)):
            fail("a sampled copy accepts assignment")
        if not Event.isEvent(b):
            fail("Event.isEvent does not recognise a sampled copy")
        t = s.takeResidue()[0]
        tData = _attr(t, "data")
        if (_attr(t, "type") != "x" or _item(tData, "v") != 1 or _item(_item(tData, "n"), 0) != 1
                or _isAny(t, held) or _isAny(tData, held) or _isAny(_item(tData, "e"), held)
                or _attr(_item(tData, "e"), "type") != "in"):
            fail("a rawset on a sample reached the residue later taken")

        # an entry too deep to copy is refused as a rejection, never raised
        # raw (nothing else is raised): every copy is iterative, so Event
        # accepts every depth probed
        def deepDict(n):
            root = {}
            cur = root
            for _ in range(n):
                nxt = {}
                cur["x"] = nxt
                cur = nxt
            return root

        def deepList(n):
            root = []
            cur = root
            for _ in range(n):
                nxt = []
                cur.append(nxt)
                cur = nxt
            return root

        probes = [(str(n) + " deep", deepDict, n) for n in DEEP] + [(str(DEEP[-1]) + " deep in lists", deepList, DEEP[-1])]
        for label, build, n in probes:
            data = build(n)
            try:
                built = Event("deep", {"d": data})
            except Exception as err:
                fail("Event refused data " + label + ": " + _typeName(err))
                continue
            finally:
                del data
            ds = Stream()
            try:
                ds.emit(built)
            except Exception as raisedE:
                if not (isinstance(raisedE, Exception) and _attr(raisedE, "code") in CODES):
                    fail("an entry " + label + " made emit raise " + _typeName(raisedE) + ": " +
                         _str(raisedE)[:60] + ", not a rejection")
            del built, ds

        # entry takes the stream's own copy
        h = Stream()
        heldInner = Event("in", {"w": 1})
        heldEvent = Event("x", {"v": 1, "n": [1], "e": heldInner})
        heldData = heldEvent.data
        h.emit(heldEvent)
        _rawset(heldEvent, "type", "z")
        _rawset(heldEvent, "data", {"v": 7, "n": [9]})
        _rawset(heldInner, "type", "zz")
        # a take hands over the stream's own objects, so it shows what it held
        back = h.takeResidue()[0]
        backData = _attr(back, "data")
        if (back is heldEvent or backData is heldData or _attr(back, "type") != "x"
                or _item(backData, "v") != 1 or _item(_item(backData, "n"), 0) != 1):
            fail("the residue holds the entering code's own event (what it does to the event reached the residue)")
        if _item(backData, "e") is heldInner or _attr(_item(backData, "e"), "type") != "in":
            fail("an event in the entering code's own data reached the residue")
        h.emit(back)
        _rawset(back, "data", {"v": 8})
        again = h.takeResidue()[0]
        if again is back or _attr(again, "data") is backData or _item(_attr(again, "data"), "v") != 1:
            fail("taken residue entered again is the residue (not the stream's own copy)")

        # a gate that counts on its input by identity (a stamp it keeps)
        # sees each object once on each of two emits of one held event
        c = Stream()
        stamps = []

        def counter(e):
            n = 2 + sum(1 for x in stamps if x is e) + sum(1 for x in stamps if x is e.data)
            stamps.append(e)
            stamps.append(e.data)
            return Event("counted", {"n": n})

        c.register(Gate("count", counter))
        same = Event("count", {"k": 1})
        c.emit(same)
        c.emit(same)
        counts = [_item(_attr(x, "data"), "n") for x in c.takeResidue()]
        if counts != [2, 2]:
            fail("a gate carried a count from one emit to the next through a stamp on the entering code's own event (counts " +
                 show(counts) + ")")
    except Exception as err:
        fail("raised " + _typeName(err) + ": " + _str(err))
    return p
