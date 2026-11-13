"""contract-fake-core -- a throwaway implementation that exists only so the
contract tests can show each contract check passing on something that
honours the contract, and failing on each departure it names. Port of
lua/sisoTests/contract-fake-core.lua.

It is NOT the core and NOT a reference for it: little attention to cost,
not checked against the reference. The stream phases build
py/code/siso/core/ from the contracts and the Lua reference, never from
this.

The file name has a hyphen, so a test loads it by path (importlib):

    fake = loadFake()                      # see the contract tests
    core = fake.new()                      # honours core-api and observer-seam
    core = fake.new("skipNonEvents")       # 11.1's X1
    # {"Event": ..., "Gate": ..., "Stream": ...}

Each call builds a fresh set of classes, so a switch never leaks from one
core to another. One switch per core.

Switches from the reference's history (the Lua fake's), each reproducing
one departure:
  skipNonEvents     non-Event list members are skipped (11.1 X1)
  acceptAnyEntry    the entry door takes any value (11.1)
  countIds          ids continue across propagations (11.1 R1)
  sampleCount       sampleHere carries eventCount (11.1 R1)
  noFreeze          events are plain mutable objects and nothing is copied
                    (11.1 R3)
  observeDuring     observers are called per delivery, during the
                    propagation (11.1's observer)
  unprotected       an observer's raise escapes emit
  sharedBatch       every observer gets the same batch dict
  shareFrozen       samples and observer records hand out the stream's own
                    objects instead of copies (12.0's X6)
  writableAtBirth   Event builds a plain mutable object holding the
                    caller's dict; only the stream copies and freezes it
                    (12.0)
  sharedRejection   every observer is shown the very rejection emit raises
                    (12.0's C-2)
  tableMeta         the Event class is left unlocked, a channel for state
                    (12.0.1 and 13.0.0-rc1's shared metatable)
  entryAlias        entry keeps the entering code's own event (13.0.0-rc1)
  richRejection     an observer's rejection copy is an object of the
                    host's Rejection class (left unlocked) and its cause an
                    object of the cause's class (13.0.0-rc1)
Switches for Python departures a check exists to catch:
  anyData           Event takes sets, functions, class instances, builtin
                    subclasses and non-str keys into data as they are
  dropOddKeys       Event silently drops non-str keys and turns builtin
                    subclasses into plain containers (Drift 7)
  openStream        the stream's state is on instance attributes (Lua's R5)
  shadowableStream  the stream instance accepts assignment (a door can be
                    shadowed)
  unlockedExports   Gate and Stream are left unlocked, Event.isEvent and
                    the default transform are plain functions
  openRejection     the Rejection class is left unlocked and its fields
                    accept assignment
  openStorage       FrozenDict keeps a plain dict under an ordinary name
  abcRegister       FrozenDict is an ABC whose register() is left open (a
                    state channel through issubclass)
  duckEvent         Event.isEvent answers by attributes (type and data), so
                    a look-alike passes (X1's Python counterpart)
  listSubclass      a returned list or tuple subclass is read through its
                    own __iter__ (X1's lying __len counterpart)
  acceptCoroutine   a returned coroutine or generator is taken as
                    "consumed" (Drift 7)
  unclosedCoroutine a returned coroutine is rejected but never closed
  unsafeMessage     a gate's exception is rendered with str() outside
                    protection
  catchBase         gates and observers are protected with BaseException,
                    so a process-level exit becomes a rejection or is
                    swallowed
  frameArgs         observers are called from a helper that takes the
                    observers and the rejection as arguments
  recursiveEntry    the entry copy recurses, outside protection
  shallowRejection  an observer's rejection copy holds the very exception
                    the gate raised as its cause
  abcData           FrozenDict is an ABC (a Mapping subclass) with its own
                    register() locked: ABCMeta.register(FrozenDict, c), what
                    super(type(T), T).register reaches, still writes its
                    registry (review, 13.2.0)
  copyPerObserver   the rejection's cause is read once per observer
                    instead of snapshotted once per rejection, so the gate's
                    code counts the observers (review, 13.2.0)

Test-only liberties: object.__setattr__ inside its own constructors, a
recursive plain copy of a rejection's cause.
"""

import abc
import collections
import collections.abc
import inspect
import types

SWITCHES = (
    "skipNonEvents", "acceptAnyEntry", "countIds", "sampleCount", "noFreeze", "observeDuring",
    "unprotected", "sharedBatch", "shareFrozen", "writableAtBirth", "sharedRejection", "tableMeta",
    "entryAlias", "richRejection",
    "anyData", "dropOddKeys", "openStream", "shadowableStream", "unlockedExports", "openRejection",
    "openStorage", "abcRegister", "duckEvent", "listSubclass", "acceptCoroutine", "unclosedCoroutine",
    "unsafeMessage", "catchBase", "frameArgs", "recursiveEntry", "shallowRejection",
    "abcData", "copyPerObserver",
)

DOORS = ("register", "emit", "takeResidue", "sampleHere", "attach", "detach")
FIELDS = ("code", "message", "eventId", "causeId", "eventType", "depth", "cause")
_LEAF = (str, int, float, bool, type(None))
_LOCK = "__sisoLocked__"


def _dunder(name):
    return name.startswith("__") and name.endswith("__")


def _lock(cls):
    type.__setattr__(cls, _LOCK, True)


def _metaclasses(registerOpen):
    class Locked(type):
        def __setattr__(cls, k, v):
            if cls.__dict__.get(_LOCK):
                raise TypeError("the core's classes are locked: cannot set '" + str(k) + "'")
            type.__setattr__(cls, k, v)

        def __delattr__(cls, k):
            if cls.__dict__.get(_LOCK):
                raise TypeError("the core's classes are locked: cannot delete '" + str(k) + "'")
            type.__delattr__(cls, k)

    class Refuse(metaclass=Locked):
        __slots__ = ()

        def __call__(self, *args, **kwargs):
            raise TypeError("a core class takes no registration")

    _lock(Refuse)

    attrs = {
        "__module__": __name__,
        "__setattr__": Locked.__setattr__,
        "__delattr__": Locked.__delattr__,
    }
    if not registerOpen:
        attrs["register"] = Refuse()
    LockedABC = type("LockedABC", (abc.ABCMeta,), attrs)
    return Locked, LockedABC


def _lockedCallable(Locked, fn):
    class Fn(metaclass=Locked):
        __slots__ = ()

        def __call__(self, *args, **kwargs):
            return fn(*args, **kwargs)

    _lock(Fn)
    return Fn()


def new(switch=None):
    if switch is not None and switch not in SWITCHES:
        raise ValueError("contract-fake-core: no switch " + repr(switch))
    on = (lambda name: switch == name)
    Locked, LockedABC = _metaclasses(on("abcRegister"))
    asABC = on("abcData") or on("abcRegister")
    lockCore = not on("unlockedExports")

    # ── FrozenDict ──────────────────────────────────────────────────
    store = "_store" if on("openStorage") else "_m"

    def fdGet(self, k):
        return object.__getattribute__(self, store)[k]

    def fdIter(self):
        return iter(object.__getattribute__(self, store))

    def fdLen(self):
        return len(object.__getattribute__(self, store))

    def fdRepr(self):
        return "FrozenDict(" + ", ".join(repr(k) for k in object.__getattribute__(self, store)) + ")"

    def refuseSet(self, k, v):
        raise AttributeError("read-only: an event and its data cannot be assigned (field '" + str(k) + "')")

    def refuseDel(self, k):
        raise AttributeError("read-only: an event and its data cannot be assigned (field '" + str(k) + "')")

    def final(cls, **kwargs):
        raise TypeError(cls.__mro__[1].__name__ + " is final")

    def fdNew(cls, data=None):
        return freeze({} if data is None else data, True, {})

    def fdContains(self, k):
        try:
            return k in object.__getattribute__(self, store)
        except TypeError:
            return False

    def fdEq(self, other):
        if not isinstance(other, collections.abc.Mapping):
            return NotImplemented
        return dict(object.__getattribute__(self, store)) == dict(other.items())

    def delegate(name):
        """A read method as the storage's own builtin method: a locked descriptor, no Python function on the class."""
        def get(self, obj, cls=None):
            if obj is None:
                return self
            return getattr(object.__getattribute__(obj, store), name)

        D = Locked("FrozenDict." + name, (), {"__module__": __name__, "__slots__": (), "__get__": get})
        _lock(D)
        return D()

    fdAttrs = {
        "__module__": __name__,
        "__slots__": (store,),
        "__new__": fdNew,
        "__getitem__": fdGet,
        "__iter__": fdIter,
        "__len__": fdLen,
        "__repr__": fdRepr,
        "__setattr__": refuseSet,
        "__delattr__": refuseDel,
        "__init_subclass__": classmethod(final),
    }
    if asABC:
        # abcData / abcRegister: an ABC reachable from every event
        FrozenDict = LockedABC("FrozenDict", (collections.abc.Mapping,), fdAttrs)
    else:
        # the Mapping protocol itself, registered with Mapping from outside
        fdAttrs.update({"__contains__": fdContains, "__eq__": fdEq, "__hash__": None,
                        "get": delegate("get"), "keys": delegate("keys"),
                        "values": delegate("values"), "items": delegate("items")})
        FrozenDict = Locked("FrozenDict", (), fdAttrs)
        collections.abc.Mapping.register(FrozenDict)
    _lock(FrozenDict)

    def mkFD(d):
        o = object.__new__(FrozenDict)
        object.__setattr__(o, store, d if on("openStorage") else types.MappingProxyType(d))
        return o

    EMPTY = mkFD({})

    # ── Event ───────────────────────────────────────────────────────
    def eventNew(cls, type, data=None):  # noqa: A002 (the contract's name)
        if builtinType(type) is not str:
            raise TypeError("Event: type is not a str (" + builtinType(type).__name__ + ")")
        if data is None:
            frozen = EMPTY
        elif builtinType(data) is dict or builtinType(data) is FrozenDict or (on("dropOddKeys") and isinstance(data, dict)):
            frozen = freeze(data, True, {})
        elif on("anyData") and isinstance(data, collections.abc.Mapping):
            frozen = freeze(data, True, {})
        else:
            raise TypeError("Event: data is not a mapping (" + builtinType(data).__name__ + ")")
        return mkEvent(type, frozen)

    def eventRepr(self):
        try:
            return "Event(" + repr(object.__getattribute__(self, "type")) + ")"
        except Exception:
            return "Event(<unset>)"

    def isEventHonest(v):
        return builtinType(v) is Event

    def isEventDuck(v):
        try:
            return hasattr(v, "type") and hasattr(v, "data")
        except Exception:
            return False

    if on("unlockedExports"):
        isEventAttr = staticmethod(isEventHonest)
    elif on("duckEvent"):
        isEventAttr = _lockedCallable(Locked, isEventDuck)
    else:
        isEventAttr = _lockedCallable(Locked, isEventHonest)

    Event = Locked("Event", (), {
        "__module__": __name__,
        "__slots__": ("type", "data"),
        "__new__": eventNew,
        "__repr__": eventRepr,
        "__setattr__": refuseSet,
        "__delattr__": refuseDel,
        "__init_subclass__": classmethod(final),
        "isEvent": isEventAttr,
    })
    if not on("tableMeta"):
        _lock(Event)

    def mkEvent(t, d):
        e = object.__new__(Event)
        object.__setattr__(e, "type", t)
        object.__setattr__(e, "data", d)
        return e

    # noFreeze / writableAtBirth: a plain mutable event holding what it is given
    class LooseEvent:
        def __init__(self, type, data=None):  # noqa: A002
            self.type = type
            self.data = {} if data is None else data

        @staticmethod
        def isEvent(v):
            return builtinType(v) is LooseEvent or builtinType(v) is Event

    loose = on("noFreeze") or on("writableAtBirth")
    EventOut = LooseEvent if loose else Event

    def isEv(v):
        if on("duckEvent"):
            return isEventDuck(v)
        return builtinType(v) is Event or (loose and builtinType(v) is LooseEvent)

    # ── freezing: an iterative deep copy into frozen values ─────────
    class Refuse(Exception):
        """What is not plain data; freeze adds the path."""

    def classify(v, keepCore):
        t = builtinType(v)
        if t in _LEAF:
            return None, v
        if t is dict:
            return "map", keyed(v.items())
        if t is Event or (loose and t is LooseEvent):
            if keepCore and t is Event:
                return None, v
            return "event", (("data", v.data),)
        if t is FrozenDict:
            if keepCore:
                return None, v
            return "map", tuple(v.items())
        if t is list or t is tuple:
            return "seq", tuple(enumerate(v))
        if on("anyData"):
            return None, v
        if on("dropOddKeys") and isinstance(v, dict):
            return "map", keyed(dict.items(v))
        if on("dropOddKeys") and isinstance(v, (list, tuple)):
            return "seq", tuple(enumerate(list(v)))
        if on("duckEvent") and isEventDuck(v):
            return "event", (("data", v.data),)
        raise Refuse(t.__name__)

    def keyed(items):
        items = tuple(items)
        for k, _ in items:
            if builtinType(k) is not str:
                break
        else:
            return items
        out = []
        for k, x in items:
            if builtinType(k) is not str:
                if on("anyData"):
                    out.append((k, x))
                    continue
                if on("dropOddKeys"):
                    continue
                raise Refuse("a key of type " + builtinType(k).__name__)
            out.append((k, x))
        return tuple(out)

    def build(orig, kind, items, out):
        if kind == "map":
            return mkFD({k: v for (k, _), v in zip(items, out)})
        if kind == "seq":
            return tuple(out)
        return mkEvent(orig.type, out[0])

    def pathOf(rootPath, frames, last):
        """The path to a child, rendered only when a refusal names it (so depth costs no memory)."""
        out = [rootPath]
        steps = [(frames[i - 1][1], frames[i][5]) for i in range(1, len(frames))]
        if last is not None:
            steps.append(last)
        for kind, key in steps:
            out.append(".data" if kind == "event" else "[" + repr(key) + "]")
        return "".join(out)

    def refused(path, what):
        return TypeError("Event: data at " + path + " is not plain data (" + what + ")")

    def freeze(root, keepCore, memo, rootPath="data"):
        try:
            kind, payload = classify(root, keepCore)
        except Refuse as r:
            raise refused(rootPath, str(r)) from None
        if kind is None:
            return payload
        if id(root) in memo:
            return memo[id(root)][1]
        frames = [[root, kind, payload, 0, [], None]]
        onPath = {id(root)}
        while True:
            f = frames[-1]
            orig, kind, items, i, out, _ = f
            if i < len(items):
                f[3] = i + 1
                key, child = items[i]
                hit = memo.get(id(child))
                if hit is not None:
                    out.append(hit[1])
                    continue
                try:
                    ck, cp = classify(child, keepCore)
                except Refuse as r:
                    raise refused(pathOf(rootPath, frames, (kind, key)), str(r)) from None
                if ck is None:
                    out.append(cp)
                    continue
                if id(child) in onPath:
                    raise refused(pathOf(rootPath, frames, (kind, key)), "a cycle")
                onPath.add(id(child))
                frames.append([child, ck, cp, 0, [], key])
                continue
            frames.pop()
            onPath.discard(id(orig))
            built = build(orig, kind, items, out)
            memo[id(orig)] = (orig, built)
            if not frames:
                return built
            frames[-1][4].append(built)

    def copyRecursive(v):
        if builtinType(v) is Event:
            return mkEvent(v.type, copyRecursive(v.data))
        if builtinType(v) is FrozenDict:
            return mkFD({k: copyRecursive(x) for k, x in v.items()})
        if builtinType(v) is tuple:
            return tuple(copyRecursive(x) for x in v)
        return v

    def own(v, memo):
        """The receiver's own frozen copy."""
        if on("shareFrozen") or on("noFreeze"):
            return v
        return freeze(v, False, memo)

    # ── Gate ────────────────────────────────────────────────────────
    def noTransformFn(event=None):
        return None

    noTransform = noTransformFn if on("unlockedExports") else _lockedCallable(Locked, noTransformFn)

    def gateInit(self, signature, transform=None, shape=None):
        if builtinType(signature) is not str:
            raise TypeError("Gate: signature is not a str (" + builtinType(signature).__name__ + ")")
        if transform is None:
            transform = noTransform
        elif not callable(transform):
            raise TypeError("Gate: transform is not callable (" + builtinType(transform).__name__ + ")")
        object.__setattr__(self, "signature", signature)
        object.__setattr__(self, "transform", transform)
        object.__setattr__(self, "shape", shape)

    def gateRefuse(self, k, v=None):
        raise AttributeError("a gate is frozen ('" + str(k) + "')")

    Gate = Locked("Gate", (), {
        "__module__": __name__,
        "__slots__": ("signature", "transform", "shape"),
        "__init__": gateInit,
        "__setattr__": gateRefuse,
        "__delattr__": gateRefuse,
    })
    if lockCore:
        _lock(Gate)

    # ── Rejection ───────────────────────────────────────────────────
    openRej = on("openRejection") or on("richRejection")

    def rejInit(self, code, message, eventId=None, causeId=None, eventType=None, depth=None, cause=None):
        Exception.__init__(self, message)
        for k, v in zip(FIELDS, (code, message, eventId, causeId, eventType, depth, cause)):
            object.__setattr__(self, k, v)

    def rejSet(self, k, v):
        if _dunder(str(k)):
            Exception.__setattr__(self, k, v)
            return
        raise AttributeError("read-only: a rejection's fields are set once ('" + str(k) + "')")

    def rejDel(self, k):
        if _dunder(str(k)):
            Exception.__delattr__(self, k)
            return
        raise AttributeError("read-only: a rejection's fields are set once ('" + str(k) + "')")

    rejAttrs = {"__module__": __name__, "__init__": rejInit, "__init_subclass__": classmethod(final)}
    if not on("openRejection"):
        rejAttrs["__setattr__"] = rejSet
        rejAttrs["__delattr__"] = rejDel
    Rejection = Locked("Rejection", (Exception,), rejAttrs)
    if not openRej:
        _lock(Rejection)

    def describe(v):
        if v is None:
            return "None"
        t = builtinType(v)
        if t in (bool, int, float):
            return "the " + t.__name__ + " " + repr(v)
        if t is str:
            return "a str"
        return "a " + t.__name__ + " that is not an Event"

    def safeStr(exc):
        if on("unsafeMessage"):
            return str(exc)
        try:
            s = str(exc)
            if builtinType(s) is str:
                return s
        except Exception:
            pass
        return "<" + builtinType(exc).__name__ + ">"

    def notEvent(delivery, sig, what):
        if delivery is None:
            return Rejection("SISO_NOT_EVENT", "Not an Event: the entry door was given " + what)
        eventId, causeId, t, _ = delivery
        return Rejection("SISO_NOT_EVENT", "Not an Event: gate '" + sig + "' returned " + what +
                         " (a gate returns None, an Event or a list of Events)", eventId, causeId, t)

    def selfLoop(delivery, sig):
        eventId, causeId, t, _ = delivery
        return Rejection("SISO_SELF_LOOP", "Self-loop: gate '" + sig + "' returned its own type", eventId, causeId, t)

    # ── a rejection's plain copy (THE REJECTION) ────────────────────
    def plainCopy(v, memo, onPath):
        t = builtinType(v)
        if t in _LEAF:
            return v
        if isEv(v):
            return freeze(v, False, memo)
        if id(v) in onPath:
            return None
        onPath.add(id(v))
        try:
            if isinstance(v, collections.abc.Mapping):
                items = list(dict.items(v)) if isinstance(v, dict) else list(v.items())
                return mkFD({(k if builtinType(k) in _LEAF else repr(k)): plainCopy(x, memo, onPath) for k, x in items})
            if isinstance(v, list):
                return tuple(plainCopy(x, memo, onPath) for x in list.copy(v))
            if isinstance(v, tuple):
                return tuple(plainCopy(x, memo, onPath) for x in tuple.__getitem__(v, slice(None)))
            try:
                attrs = dict(object.__getattribute__(v, "__dict__"))
            except Exception:
                attrs = {}
            return mkFD({k: plainCopy(x, memo, onPath) for k, x in attrs.items()})
        finally:
            onPath.discard(id(v))

    def richCopy(v):
        if isinstance(v, BaseException):
            c = builtinType(v).__new__(builtinType(v))
            c.__dict__.update(vars(v))
            return c
        return v

    def rejectionFor(rej):
        if on("sharedRejection"):
            return rej
        fields = {k: getattr(rej, k) for k in FIELDS if getattr(rej, k) is not None}
        if on("richRejection"):
            return Rejection(rej.code, rej.message, rej.eventId, rej.causeId, rej.eventType, rej.depth,
                             richCopy(rej.cause))
        if on("shallowRejection"):
            return mkFD(fields)
        return plainCopy(fields, {}, set())

    perObserver = (on("copyPerObserver") or on("sharedRejection") or on("richRejection")
                   or on("shallowRejection"))

    def snapshotOf(rej):
        """The cause read once per rejection, observers or not; None when copies are made per observer."""
        if rej is None or perObserver:
            return None
        try:
            return ("ok", rejectionFor(rej))
        except Exception:
            return ("failed", None)

    def rejectionCopy(rej, snap):
        if snap is None:
            return rejectionFor(rej)
        if snap[0] == "failed":
            raise RuntimeError("the rejection's snapshot could not be taken")
        return freeze(snap[1], False, {})  # a fresh copy of core values: no gate code runs

    def batchFor(outcome, rej, records, snap):
        memo = {}
        recs = []
        for r in records:
            recs.append({"eventId": r["eventId"], "causeId": r["causeId"], "eventType": r["eventType"],
                         "claimed": r["claimed"], "data": own(r["data"], memo)})
        return {"outcome": outcome, "rejection": None if rej is None else rejectionCopy(rej, snap), "records": recs}

    guardKind = BaseException if on("catchBase") else Exception

    def notifyHelper(observers, outcome, rejection, records, snap):
        """frameArgs: observers called from a helper that takes them as arguments."""
        batches = [batchFor(outcome, rejection, records, snap) for _ in observers]
        for o, b in zip(observers, batches):
            try:
                o(b)
            except Exception:
                pass

    # ── Stream ──────────────────────────────────────────────────────
    def streamInit(self):
        gates = {}
        st = {"n": 0, "count": 0}
        residue = []
        observers = []

        def register(g):
            if not isinstance(g, Gate):
                raise TypeError("register: a gate is a Gate, got " + builtinType(g).__name__)
            if g.signature in gates:
                raise ValueError("Signature collision: '" + g.signature + "'")
            gates[g.signature] = g
            st["n"] += 1

        def entryCopy(event):
            if on("entryAlias") or on("noFreeze"):
                try:
                    event.type, event.data
                except Exception:
                    return None, "an Event whose fields are unset"
                return event, None
            if on("recursiveEntry"):
                return copyRecursive(event), None
            try:
                return freeze(event, False, {}), None
            except AttributeError:
                return None, "an Event whose fields are unset"
            except Exception:
                return None, "an Event too deep to copy"

        def judge(result, sig, delivery):
            if result is None:
                return [], None
            if isEv(result):
                try:
                    t = result.type
                except Exception:
                    return None, notEvent(delivery, sig, "an Event whose fields are unset")
                if t == sig:
                    return None, selfLoop(delivery, sig)
                return [result], None
            listy = builtinType(result) in (list, tuple)
            if on("listSubclass"):
                listy = isinstance(result, (list, tuple))
            if listy:
                members = [m for m in result] if on("listSubclass") else list(result)
                outs = []
                for i, m in enumerate(members, 1):
                    if not isEv(m):
                        if on("skipNonEvents"):
                            continue
                        return None, notEvent(delivery, sig, "a list whose member " + str(i) + " is " + describe(m))
                    try:
                        t = m.type
                    except Exception:
                        return None, notEvent(delivery, sig, "a list whose member " + str(i) + " is an Event whose fields are unset")
                    if t == sig:
                        return None, selfLoop(delivery, sig)
                    outs.append(m)
                return outs, None
            if on("acceptCoroutine") and (inspect.iscoroutine(result) or inspect.isgenerator(result)):
                try:
                    result.close()
                except Exception:
                    pass
                return [], None
            if on("skipNonEvents") and isinstance(result, collections.abc.Mapping):
                return [], None
            return None, notEvent(delivery, sig, describe(result))

        def release(result):
            if inspect.iscoroutine(result) and not on("unclosedCoroutine"):
                try:
                    result.close()
                except Exception:
                    pass

        def freezeOut(o):
            if builtinType(o) is LooseEvent and not on("noFreeze"):
                return freeze(o, False, {})
            return o

        def duringBatch(rec):
            # observeDuring: a batch per delivery, during the propagation
            for o in list(observers):
                try:
                    o({"outcome": "settled", "rejection": None, "records": [dict(rec, data=own(rec["data"], {}))]})
                except Exception:
                    pass

        def run(event, keep):
            records = []
            if not on("acceptAnyEntry") and not isEv(event):
                return notEvent(None, None, describe(event)), records, None
            if on("acceptAnyEntry") and not isEv(event):
                get = (lambda k: event.get(k)) if isinstance(event, dict) else (lambda k: getattr(event, k, None))
                data = get("data")
                entered = mkEvent(get("type"), freeze(data, True, {}) if isinstance(data, (dict, FrozenDict)) else EMPTY)
            else:
                entered, why = entryCopy(event)
                if why is not None:
                    return notEvent(None, None, why), records, None
            gateCount = st["n"]
            base = st["count"] if on("countIds") else 0
            queue = collections.deque([(entered, None, 1)])
            settled = []
            lastId = 0
            while queue:
                cur, causeId, depth = queue.popleft()
                lastId += 1
                eventId = base + lastId
                if on("countIds"):
                    st["count"] = eventId
                t = cur.type
                g = gates.get(t) if builtinType(t) is str else None
                if keep or on("observeDuring"):
                    rec = {"eventId": eventId, "causeId": causeId, "eventType": t, "claimed": g is not None, "data": cur.data}
                    records.append(rec)
                    if on("observeDuring"):
                        duringBatch(rec)
                if g is None:
                    settled.append(cur)
                    continue
                delivery = (eventId, causeId, t, depth)
                if depth > gateCount:
                    return Rejection("SISO_CYCLE", "Cycle: '" + str(t) + "' claimed at depth " + str(depth) + " with " +
                                     str(gateCount) + " gates registered — a type repeated on this causal chain",
                                     eventId, causeId, t, depth), records, None
                try:
                    result = g.transform(cur)
                except guardKind as exc:
                    return Rejection("SISO_GATE_THREW", "Gate '" + g.signature + "' threw: " + safeStr(exc),
                                     eventId, causeId, t, None, exc), records, None
                outputs, rejection = judge(result, g.signature, delivery)
                release(result)
                result = None
                if rejection is not None:
                    return rejection, records, None
                for o in outputs:
                    queue.append((freezeOut(o), eventId, depth + 1))
            return None, records, settled

        def emit(event):
            current = tuple(observers)
            rejection, records, settled = run(event, len(current) > 0)
            if rejection is None:
                residue.extend(settled)
                outcome = "settled"
            else:
                outcome = "rejected"
            snap = snapshotOf(rejection)  # once per rejection, whether or not an observer is attached
            if not on("observeDuring"):
                if on("frameArgs"):
                    notifyHelper(current, outcome, rejection, records, snap)
                else:
                    batches = []
                    shared = None
                    for _ in current:
                        if on("sharedBatch"):
                            if shared is None:
                                shared = batchFor(outcome, rejection, records, snap)
                            batches.append(shared)
                            continue
                        try:
                            batches.append(batchFor(outcome, rejection, records, snap))
                        except Exception:
                            batches.append(None)
                    for i in range(len(current)):
                        if batches[i] is None:
                            continue
                        if on("unprotected"):
                            current[i](batches[i])
                            continue
                        try:
                            current[i](batches[i])
                        except guardKind:
                            pass
            if rejection is not None:
                raise rejection from None

        def takeResidue():
            nonlocal residue
            taken = residue
            residue = []
            return taken

        def sampleHere():
            memo = {}
            s = {"residue": [own(e, memo) for e in residue], "gateCount": st["n"]}
            if on("sampleCount"):
                s["eventCount"] = st["count"]
            return s

        def attach(o):
            if not callable(o):
                raise TypeError("attach: an observer is a function, got " + builtinType(o).__name__)
            if any(x is o for x in observers):
                raise ValueError("attach: this observer is already attached")
            observers.append(o)

        def detach(o):
            for i, x in enumerate(observers):
                if x is o:
                    del observers[i]
                    return
            raise ValueError("detach: this observer is not attached")

        doors = {"register": register, "emit": emit, "takeResidue": takeResidue, "sampleHere": sampleHere,
                 "attach": attach, "detach": detach}
        if on("openStream"):
            self.__dict__.update(doors)
            self.__dict__.update(gates=gates, observers=observers)
        else:
            for k, fn in doors.items():
                object.__setattr__(self, k, fn)

    def streamRefuse(self, k, v=None):
        raise AttributeError("a stream's doors cannot be assigned ('" + str(k) + "')")

    streamAttrs = {"__module__": __name__, "__init__": streamInit}
    if not on("openStream"):
        streamAttrs["__slots__"] = DOORS
    if not on("openStream") and not on("shadowableStream"):
        streamAttrs["__setattr__"] = streamRefuse
        streamAttrs["__delattr__"] = streamRefuse
    Stream = Locked("Stream", (), streamAttrs)
    if lockCore:
        _lock(Stream)

    return {"Event": EventOut, "Gate": Gate, "Stream": Stream}


builtinType = type
