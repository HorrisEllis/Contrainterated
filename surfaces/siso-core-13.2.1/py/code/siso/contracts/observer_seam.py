"""contract: observer-seam -- what the stream shows an observer (core 13,
Python instance). Port of lua/code/contracts/observer_seam.lua.

Between: stream -> observer. Only a contracts phase changes this file.

THE DOOR
  stream.attach(observer) and stream.detach(observer) (core-api). An
  observer is any callable, compared by identity (`is`). Attaching a
  value that is not callable raises
    TypeError("attach: an observer is a function, got <type name>")
  attaching one that is attached raises
    ValueError("attach: this observer is already attached")
  and detaching one that is not attached raises
    ValueError("detach: this observer is not attached").
  Attaching or detaching changes nothing the stream does: not the
  residue, not a rejection, not what a gate receives.

ONE CALL PER PROPAGATION, AFTER IT HAS ENDED
  The stream calls each observer attached when the entry arrives once
  per propagation, as observer(batch), when the propagation is over:
    settled   after its residue is committed;
    rejected  after nothing was committed, just before the rejection is
              raised.
  No observer runs while a propagation is in progress, so an observer
  that calls a door (emit, register, takeResidue, attach, ...) makes the
  next crossing; it cannot touch the propagation it is shown.
  A refused entry (SISO_NOT_EVENT at the entry door) is a propagation
  with no deliveries: its batch has no records. Records are collected
  only when an observer is attached.

THE BATCH
  a dict, the observer's own (plain and mutable), with exactly the keys
    "outcome"    "settled" | "rejected"
    "rejection"  the observer's own copy of the rejection emit raises
                 (rejected only; None when settled; see THE REJECTION)
    "records"    a list of records, the deliveries in delivery order
  Each observer receives its own batch dict, records list and record
  dicts, so one observer cannot change what another is shown.

THE REJECTION (core 13; 12 handed every observer the raised object itself)
  A plain, frozen copy of the rejection emit raises, the observer's own
  (core 13.0.0): a FrozenDict (core-api) with a key for each field the
  rejection carries (those that are not None: code and message always;
  eventId, causeId and eventType when there is a delivery; depth; cause)
  with equal values. `cause` is copied plain, at every depth:
    str, int, float, bool, None (exact types)  as they are
    an Event                   a fresh Event (the observer's own)
    a FrozenDict, or a dict    a FrozenDict of its items (a key that is
    or any other Mapping       not a str, int, float, bool or None
                               becomes repr(key))
    a list or a tuple          a tuple of its members
    any other object (an       a FrozenDict of its own attributes,
    exception included)        vars(obj) where it has a __dict__ (else
                               empty): nothing inherited, no method, no
                               class; an exception's args are not carried,
                               the rejection's `message` carries its
                               rendering
    an object met again on its own path (a cycle) becomes None.
  The cause is snapshotted once, when the rejection is made, whether or
  not an observer is attached, into the core's own plain values (reading
  containers through the base types' own methods, dict.items and
  list.copy). Where reading runs an object's own code (a __dict__
  property, a foreign Mapping, a key's __repr__) it runs once per
  rejection, under protection, the same for any number of observers; if
  the snapshot cannot be taken, every observer's batch fails alone and
  the host's rejection is unchanged. Each observer then receives its own
  fresh copy of that snapshot, so every observer's copy is equal and
  building them runs no code of the gate's (independent review, 13.2.0:
  copying per observer let the gate's code count the observers). No
  object in the copy is one the host's rejection or the gate's exception
  holds, and nothing in it is an object but a fresh Event: no class
  reached from it is the host's or the gate's. Assignment raises at every
  depth, and nothing done to it -- object.__setattr__ past the refusal
  included -- reaches the rejection the entering code receives, what the
  gate raised, or another observer's copy (core-api OWNERSHIP). str() is
  not promised on the copy; read its "message".

A RECORD
  a dict with exactly the keys
    "eventId"    1, 2, ... in delivery order (core-api IDS), contiguous
    "causeId"    the eventId of the delivery whose gate returned this
                 event; None for the entry delivery
    "eventType"  the event's type
    "claimed"    True when a gate claimed the event, False when it
                 settled as residue
    "data"       a frozen copy of the event's data as the gate received
                 it, the observer's own (one memo per observer, so data
                 passed on along a chain is copied once for that
                 observer): nothing done to it reaches the stream's
                 residue or another observer (core-api OWNERSHIP)
  In a rejected propagation the records end with the delivery the
  rejection names: the one whose gate returned its own type or a
  non-Event (SISO_SELF_LOOP, SISO_NOT_EVENT), the one claimed too deep
  (SISO_CYCLE), or the one whose gate raised (SISO_GATE_THREW). A
  returned event that caused the rejection is never delivered, so it has
  no record.

ISOLATION
  An observer that raises an Exception is the observer's own failure.
  The stream calls observers in protected mode: emit still returns or
  raises exactly as it would with no observer, and every other observer
  still receives its batch. Building an observer's batch (its copies
  included) is part of that observer's call: if it fails, that observer
  fails alone. Every observer's batch is built before any observer is
  called, so nothing an observer does -- taking the residue and writing
  to what it took included -- reaches what another observer is shown.
  The order in which observers are called carries no meaning.
  emit calls the observers from its own frame, and never passes the
  observers list, the records or the rejection as arguments to a helper
  that calls an observer or a gate: an observer that reads the frames
  below it (sys._getframe(1)...) finds none of them among their
  arguments (the PHP review's debug_backtrace finding, carried here).

What an observer stores, numbers or labels is its own (observer-side
state, R2). The stream holds no stream id, lineage or level.

SUBSTRATE (Python)
  - Lua's "getmetatable answers false or a string" on the copy becomes
    "nothing in the copy but str, int, float, bool, None, tuples,
    FrozenDicts and fresh Events", so the only classes reachable from it
    are the core's, which are locked (core-api). Lua's string marks and
    "readonly:" sentinels vanish.
  - Lua's `rawset` probes become object.__setattr__ (Python's rawset) on
    what a copy holds, and identity: a copy that is truly the observer's
    own shares no object with anything else, so neither reaches anyone.
  - Lua's metatable tamper probe becomes a class tamper probe: an
    observer writes attributes (`kind`, `missing`, `__str__`) on every
    class reachable from its copy that accepts them, and on every object
    in it; the host's rejection, what the gate raised and a later
    stream's rejection must be unchanged. What the probe wrote is
    removed afterwards; it never writes a standard-library class.
  - The batch and its records are plain dicts and a list, the observer's
    own (as in Lua); only the data in a record and the rejection copy are
    frozen.
  - Frames: an observer reaches every frame below it, emit's included,
    with sys._getframe(n) or with no import at all through a generator's
    gi_frame.f_back, and f_locals shows (and in 3.13 writes) every local
    there (core-api SUBSTRATE "Frames", ledger Y2: the debug-library
    counterpart, convention: named, not defended). The contract asks the
    thinner thing it can hold: none of them among the frames' arguments.
  - A process-level exit (a BaseException that is not an Exception)
    raised by an observer is not the observer's failure: it passes
    through emit unchanged, as one raised by a gate does (core-api).
  - Depth: the observer's copies are iterative; an entry 50,000 deep
    with an observer attached raises nothing but a rejection.

batchProblems(batch) judges a batch's shape; rejectionCopyProblem(copy,
raised, Event=None) judges one observer's rejection against the one
raised (any runner may use them; with the Event class given, fresh
Events inside the copy are allowed and walked). checkSeam(core) and
checkOwnership(core) run this contract against core = {"Event": ...,
"Gate": ..., "Stream": ...} and return a list of problems (empty when it
holds). No check leaves global state changed.
"""

import collections.abc
import sys

from siso.contracts.core_api import isSubstrate

BATCH_FIELDS = ("outcome", "rejection", "records")
RECORD_FIELDS = ("eventId", "causeId", "eventType", "claimed", "data")
REJECTION_FIELDS = ("code", "message", "eventId", "causeId", "eventType", "depth", "cause")

_PRIMITIVES = (type(None), bool, int, float, str)


class _Missing:
    __slots__ = ()

    def __repr__(self):
        return "<missing>"


_MISSING = _Missing()


def _str(v):
    try:
        s = str(v)
        if isinstance(s, str):
            return s
    except Exception:
        pass
    return "<" + type(v).__name__ + ">"


def _show(v):
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


def _field(v, k):
    """A field of a mapping or an object; None when absent."""
    if isinstance(v, collections.abc.Mapping):
        x = _item(v, k)
    else:
        x = _attr(v, k)
    return None if x is _MISSING else x


def _strict(a, b):
    if a is b:
        return True
    if type(a) is not type(b):
        return False
    try:
        return bool(a == b)
    except Exception:
        return False


def _refused(fn):
    try:
        fn()
    except Exception:
        return True
    return False


def _rawset(o, k, v):
    try:
        object.__setattr__(o, k, v)
        return True
    except Exception:
        return False


def _isAny(o, objs):
    return any(o is x for x in objs)


def _substrate(cls):
    """The standard library's own class (core-api's rule: never judged, never written)."""
    return isSubstrate(cls)


def _ownVars(o):
    try:
        d = object.__getattribute__(o, "__dict__")
    except Exception:
        return {}
    return d if isinstance(d, dict) else {}


# ── batches ─────────────────────────────────────────────────────────

def batchProblems(batch):
    """batchProblems(batch) -> list of problems: the batch's own shape, and
    the records' ids and causes consistent with one propagation."""
    if not isinstance(batch, dict):
        return ["a batch is a dict, got " + _typeName(batch)]
    p = []
    for k in batch:
        if k not in BATCH_FIELDS:
            p.append("batch: unexpected field " + _show(k))
    for k in BATCH_FIELDS:
        if k not in batch:
            p.append("batch: missing field '" + k + "'")
    outcome = batch.get("outcome")
    rejection = batch.get("rejection")
    if outcome != "settled" and outcome != "rejected":
        p.append("batch: outcome is " + _show(outcome))
    if outcome == "settled" and rejection is not None:
        p.append("batch: a settled batch carries a rejection")
    if outcome == "rejected" and not isinstance(rejection, collections.abc.Mapping):
        p.append("batch: a rejected batch carries no rejection")
    records = batch.get("records")
    if type(records) is not list:
        p.append("batch: records is not a list")
        return p
    for i, r in enumerate(records):
        n = i + 1
        where = "record " + str(n)
        if not isinstance(r, dict):
            p.append(where + ": not a dict")
            continue
        for k in r:
            if k not in RECORD_FIELDS:
                p.append(where + ": unexpected field " + _show(k))
        for k in RECORD_FIELDS:
            if k not in r:
                p.append(where + ": missing field '" + k + "'")
        if not _strict(r.get("eventId"), n):
            p.append(where + ": eventId is " + _show(r.get("eventId")) + ", expected " + str(n))
        cause = r.get("causeId")
        if n == 1:
            if cause is not None:
                p.append(where + ": the entry delivery has a cause")
        elif not _isInt(cause) or cause < 1 or cause >= n:
            p.append(where + ": causeId " + _show(cause) + " is not an earlier delivery")
        elif not isinstance(records[cause - 1], dict) or records[cause - 1].get("claimed") is not True:
            p.append(where + ": its cause was not claimed by a gate")
        if type(r.get("eventType")) is not str:
            p.append(where + ": eventType is not a str")
        if type(r.get("claimed")) is not bool:
            p.append(where + ": claimed is not a bool")
        if not isinstance(r.get("data"), collections.abc.Mapping):
            p.append(where + ": data is not a mapping")
    if outcome == "rejected" and isinstance(rejection, collections.abc.Mapping):
        last = records[-1] if records else None
        eventId = _item(rejection, "eventId")
        if eventId is None or eventId is _MISSING:
            if records:
                p.append("batch: a refused entry has records")
        elif not isinstance(last, dict) or not _strict(last.get("eventId"), eventId):
            p.append("batch: the records do not end with the delivery the rejection names")
    return p


# ── the observer's rejection ────────────────────────────────────────

def _objectsIn(v):
    """Every object (not a primitive) reachable from v by fields, items and attributes; kept alive."""
    out = []
    ids = set()
    stack = [v]
    while stack:
        x = stack.pop()
        if type(x) in _PRIMITIVES or id(x) in ids:
            continue
        ids.add(id(x))
        out.append(x)
        if isinstance(x, BaseException):
            for k in REJECTION_FIELDS:
                y = _attr(x, k)
                if y is not _MISSING:
                    stack.append(y)
        if isinstance(x, (list, tuple, set, frozenset)):
            try:
                stack.extend(list(x))
            except Exception:
                pass
        elif isinstance(x, collections.abc.Mapping):
            try:
                for k, y in list(x.items()):
                    stack.append(k)
                    stack.append(y)
            except Exception:
                pass
        stack.extend(_ownVars(x).values())
        stack.extend(_slotValues(x))
    return out


def _slotValues(o):
    """The values an object holds in __slots__ (an Event's type and data, say)."""
    out = []
    for c in type(o).__mro__:
        slots = c.__dict__.get("__slots__", ())
        if isinstance(slots, str):
            slots = (slots,)
        try:
            slots = tuple(slots)
        except Exception:
            continue
        for n in slots:
            if not isinstance(n, str) or n in ("__dict__", "__weakref__"):
                continue
            stored = "_" + c.__name__.lstrip("_") + n if n.startswith("__") and not n.endswith("__") else n
            try:
                out.append(object.__getattribute__(o, stored))
            except Exception:
                pass
    return out


def _notPlainCopy(v, raised, Event, path):
    """The first value in v (v included) that is not plain: one of the raised
    objects, or anything but a primitive, a tuple, a mapping that refuses
    assignment or (with Event given) a fresh Event."""
    raisedIds = {id(x) for x in raised}
    seen = []
    seenIds = set()
    stack = [(v, path)]
    while stack:
        x, where = stack.pop()
        if type(x) in _PRIMITIVES:
            continue
        if id(x) in raisedIds:
            return where + " is the raised object itself, not a copy"
        if id(x) in seenIds:
            continue
        seenIds.add(id(x))
        seen.append(x)
        t = type(x)
        if t is tuple:
            for i, y in enumerate(x):
                stack.append((y, where + "[" + str(i) + "]"))
            continue
        if Event is not None and _isEventOf(Event, x):
            stack.append((_attr(x, "data"), where + ".data"))
            continue
        if isinstance(x, collections.abc.Mapping) and not isinstance(x, dict):
            if hasattr(t, "__setitem__") or hasattr(t, "__delitem__"):
                return where + " is a " + t.__name__ + " that accepts assignment"
            if not _refused(lambda: setattr(x, "sisoContractProbe", 1)):
                try:
                    object.__delattr__(x, "sisoContractProbe")
                except Exception:
                    pass
                return where + " is a " + t.__name__ + " that accepts attributes"
            try:
                items = list(x.items())
            except Exception:
                return where + " cannot be read"
            for k, y in items:
                if type(k) not in _PRIMITIVES:
                    return where + " has a key that is not plain (" + _typeName(k) + ")"
                stack.append((y, where + "[" + _show(k) + "]"))
            continue
        if isinstance(x, (dict, list, set, bytearray)):
            return where + " is a " + t.__name__ + " (it accepts assignment), not the observer's frozen copy"
        return where + " is an object of class " + t.__name__ + ", not plain data"
    return None


def _isEventOf(Event, x):
    try:
        return bool(Event.isEvent(x))
    except Exception:
        return False


def _same(a, b, Event=None, depth=0, onPath=None):
    """a (from the raised rejection) and b (from the copy) equal by value:
    primitives strictly, sequences as tuples, mappings by items, Events by
    type and data, any other object by its own attributes (vars); an object
    met again on its own path (a cycle) matches None."""
    if depth > 100:
        return False
    if type(a) in _PRIMITIVES:
        return _strict(a, b)
    onPath = set() if onPath is None else onPath
    if id(a) in onPath:
        return b is None
    if Event is not None and _isEventOf(Event, a):
        return (_isEventOf(Event, b) and _strict(_attr(a, "type"), _attr(b, "type"))
                and _same(_attr(a, "data"), _attr(b, "data"), Event, depth + 1, onPath))
    onPath.add(id(a))
    try:
        return _sameContainer(a, b, Event, depth, onPath)
    finally:
        onPath.discard(id(a))


def _sameContainer(a, b, Event, depth, onPath):
    if isinstance(a, (list, tuple)):
        if type(b) is not tuple or len(a) != len(b):
            return False
        return all(_same(x, y, Event, depth + 1, onPath) for x, y in zip(list(a), b))
    if isinstance(a, collections.abc.Mapping):
        av = dict(a.items())
    else:
        av = _ownVars(a)
    if not isinstance(b, collections.abc.Mapping):
        return False
    try:
        bk = list(b.keys())
    except Exception:
        return False
    if len(bk) != len(av):
        return False
    for k, x in av.items():
        key = k if type(k) in _PRIMITIVES else repr(k)
        y = _item(b, key)
        if y is _MISSING or not _same(x, y, Event, depth + 1, onPath):
            return False
    return True


def rejectionCopyProblem(copy, raised, Event=None):
    """rejectionCopyProblem(copy, raised, Event=None) -> None when `copy` is an
    observer's own copy of the rejection `raised` (THE REJECTION), else a
    str naming what is wrong: the very object, an object of the raised
    one's at any depth, anything but a primitive, a tuple, a frozen mapping
    or (Event given) a fresh Event, a field missing, added or unequal (a
    `cause` compared by its own attributes), or a copy that accepts
    assignment."""
    try:
        if copy is raised:
            return "the batch's rejection is the very object raised, not the observer's copy"
        why = _notPlainCopy(copy, _objectsIn(raised), Event, "the batch's rejection")
        if why is not None:
            return why
        if not isinstance(copy, collections.abc.Mapping):
            return "the batch's rejection is not a mapping, got " + _typeName(copy)
        fields = {}
        for k in REJECTION_FIELDS:
            v = _attr(raised, k)
            if v is not _MISSING and v is not None:
                fields[k] = v
        for k, v in fields.items():
            x = _item(copy, k)
            if x is _MISSING or not _same(v, x, Event):
                return "the batch's rejection differs from the one raised at '" + k + "'"
        for k in list(copy.keys()):
            if k not in fields:
                return "the batch's rejection has a field the raised one lacks: " + _show(k)

        def write():
            copy["code"] = _item(copy, "code")

        if not _refused(write):
            return "the batch's rejection accepts assignment"
        return None
    except Exception as err:
        return "judging the batch's rejection raised " + _typeName(err) + ": " + _str(err)


# ── running the contract against an implementation ──────────────────

def _coreProblems(core):
    if not isinstance(core, collections.abc.Mapping):
        return ["core is a mapping {'Event', 'Gate', 'Stream'}, got " + _typeName(core)]
    return ["core['" + k + "'] is not callable" for k in ("Event", "Gate", "Stream") if not callable(core.get(k))]


def _emitCatch(s, e):
    try:
        s.emit(e)
        return None
    except Exception as err:
        return err


def checkSeam(core):
    p = _coreProblems(core)
    if p:
        return p
    Event, Gate, Stream = core["Event"], core["Gate"], core["Stream"]
    fail = p.append

    def addAll(label, lst):
        for s in lst:
            fail(label + ": " + s)

    def recorder(into):
        return lambda b: into.append(b)

    def types_(b):
        return " ".join(_str(r.get("eventType")) + ("+" if r.get("claimed") else "-") + "<" + _show(r.get("causeId"))
                        for r in b.get("records", []) if isinstance(r, dict))

    def raises(fn):
        try:
            fn()
        except Exception:
            return True
        return False

    try:
        # a settled propagation: one batch, after the residue is committed
        s = Stream()
        got = []
        residueSeen = []
        s.register(Gate("root", lambda e: [Event("a"), Event("b")]))
        s.register(Gate("a", lambda e: Event("a1")))

        def first(b):
            got.append(b)
            residueSeen.append(len(s.sampleHere()["residue"]))

        s.attach(first)
        s.emit(Event("root", {"k": 1}))
        if len(got) != 1:
            fail("a settled propagation gave " + str(len(got)) + " batches")
        else:
            b = got[0]
            addAll("settled", batchProblems(b))
            if not isinstance(b, dict):
                return p
            if b.get("outcome") != "settled":
                fail("settled: outcome " + _show(b.get("outcome")))
            records = b.get("records") or []
            if len(records) != 4:
                fail("settled: " + str(len(records)) + " records, expected 4: " + types_(b))
            if not records or _item(records[0].get("data"), "k") != 1:
                fail("settled: the entry record does not carry its data")
            if residueSeen != [2]:
                fail("settled: the observer ran before the residue was committed")
        # the next propagation on the same stream numbers its own deliveries
        s.emit(Event("root"))
        if len(got) == 2:
            addAll("second propagation", batchProblems(got[1]))

        # a rejected propagation: the batch carries the observer's own copy
        # of the rejection raised (THE REJECTION)
        r = Stream()
        rb = []

        def boom(e):
            raise RuntimeError("boom")

        r.register(Gate("a", lambda e: Event("b")))
        r.register(Gate("b", boom))
        r.attach(recorder(rb))
        raised = _emitCatch(r, Event("a"))
        if raised is None:
            fail("rejected: the gate's raise did not reject")
        if len(rb) != 1:
            fail("rejected: " + str(len(rb)) + " batches")
        elif isinstance(rb[0], dict):
            addAll("rejected", batchProblems(rb[0]))
            why = rejectionCopyProblem(rb[0].get("rejection"), raised, Event)
            if why:
                fail("rejected: " + why)
            if len(rb[0].get("records") or []) != 2:
                fail("rejected: " + str(len(rb[0].get("records") or [])) + " records, expected 2")

        # a refused entry: a batch with no records
        e = Stream()
        eb = []
        e.attach(recorder(eb))
        _emitCatch(e, "not an event")
        if len(eb) != 1 or not isinstance(eb[0], dict) or eb[0].get("records") != [] or eb[0].get("outcome") != "rejected":
            fail("refused entry: expected one rejected batch with no records")

        # an observer that raises changes nothing, and others still see the batch
        t = Stream()
        tb = []
        t.register(Gate("x", lambda e: Event("y")))

        def failing(b):
            raise RuntimeError("observer failure")

        def wrongArity():
            pass

        t.attach(failing)
        t.attach(wrongArity)
        t.attach(recorder(tb))
        errT = _emitCatch(t, Event("x"))
        if errT is not None:
            fail("a raising observer made emit raise: " + _typeName(errT) + ": " + _str(errT))
        if len(t.sampleHere()["residue"]) != 1:
            fail("a raising observer changed the residue")
        if len(tb) != 1:
            fail("a raising observer kept another from its batch")

        # a process-level exit raised by an observer passes through
        xs = Stream()
        exitProbe = SystemExit("siso contract probe")

        def exits(b):
            raise exitProbe

        xs.attach(exits)
        gotExit = None
        try:
            xs.emit(Event("q"))
        except BaseException as other:  # the probe's own exit, caught here
            gotExit = other
        if gotExit is not exitProbe:
            fail("a process-level exit raised by an observer did not pass through emit unchanged (got " +
                 ("nothing" if gotExit is None else _typeName(gotExit)) + ")")

        # an observer that calls doors makes the next crossing; the
        # propagation it saw is untouched
        u = Stream()
        fired = []
        u.register(Gate("a", lambda e: Event("b")))

        def crossing(b):
            if fired:
                return
            fired.append(True)
            u.register(Gate("b", lambda e: Event("late")))
            u.emit(Event("other"))

        u.attach(crossing)
        errU = _emitCatch(u, Event("a"))
        types2 = sorted(_str(_attr(ev, "type")) for ev in u.takeResidue())
        if errU is not None or types2 != ["b", "other"]:
            fail("an observer's crossings reached the propagation it was shown: " +
                 ("it was rejected (" + _show(_attr(errU, "code")) + ")" if errU is not None
                  else "residue " + ",".join(types2)))

        # each observer gets its own batch
        v = Stream()
        seen2 = []

        def scribble(b):
            b["records"].pop()
            b["outcome"] = "x"

        v.attach(scribble)
        v.attach(lambda b: seen2.append(b))
        v.emit(Event("q"))
        if (len(seen2) != 1 or not isinstance(seen2[0], dict) or seen2[0].get("outcome") != "settled"
                or len(seen2[0].get("records") or []) != 1):
            fail("one observer's writes to its batch reached another observer")

        # detach: no more batches; misuse raises, with the contract's message
        w = Stream()
        wb = []
        f = recorder(wb)

        def misuse(label, fn, kind, message):
            try:
                fn()
                fail(label + " did not raise")
            except kind as err:
                if _str(err) != message:
                    fail(label + ": message " + _show(_str(err)) + ", not " + _show(message))
            except Exception as err:
                fail(label + ": " + _typeName(err) + ", not " + kind.__name__)

        misuse("attaching a value that is not callable", lambda: w.attach(42), TypeError,
               "attach: an observer is a function, got int")
        w.attach(f)
        misuse("attaching an attached observer", lambda: w.attach(f), ValueError,
               "attach: this observer is already attached")
        w.detach(f)
        w.emit(Event("z"))
        if wb:
            fail("a detached observer was called")
        misuse("detaching an observer that is not attached", lambda: w.detach(f), ValueError,
               "detach: this observer is not attached")
        if not raises(lambda: w.detach(lambda b: None)):
            fail("detaching an observer never attached did not raise")

        # attaching changes nothing: same entry, same residue and rejection
        def run(withObserver):
            x = Stream()
            x.register(Gate("a", lambda e: [Event("b"), Event("c")]))
            x.register(Gate("b", lambda e: Event("a")))
            if withObserver:
                x.attach(lambda b: None)
            rr = _emitCatch(x, Event("a"))
            if rr is None:
                return "settled"
            return _show(_attr(rr, "code")) + "@" + _show(_attr(rr, "eventId")) + "/" + _show(_attr(rr, "depth"))

        if run(False) != run(True):
            fail("attaching an observer changed a rejection")
    except Exception as err:
        fail("raised " + _typeName(err) + ": " + _str(err))
    return p


class _ProbeError(Exception):
    """What the probes' gates raise: an error object with fields of its own."""

    kind = "io"

    def __init__(self, first="the gate's own"):
        super().__init__("probe")
        self.first = first
        self.nested = [1]
        self.msg = "m"


def checkOwnership(core):
    p = _coreProblems(core)
    if p:
        return p
    Event, Gate, Stream = core["Event"], core["Gate"], core["Stream"]
    fail = p.append
    try:
        # both attach orders, since the order observers are called in is free
        for writerFirst in (True, False):
            s = Stream()
            seen = []
            touched = []

            def writer(b):
                d = b["records"][0]["data"]

                def w():
                    d["v"] = 5

                if not _refused(w):
                    fail("an observer's record data accepts assignment")
                touched.append(d)
                inner = _item(d, "e")
                if inner is not _MISSING:
                    touched.append(inner)
                    _rawset(inner, "type", "OBSERVER WROTE")
                    _rawset(inner, "data", {"w": 9})
                n = _item(d, "n")
                if isinstance(n, list):
                    n[0] = 9

            def reader(b):
                seen.append(b["records"][0]["data"])

            if writerFirst:
                s.attach(writer)
                s.attach(reader)
            else:
                s.attach(reader)
                s.attach(writer)
            s.emit(Event("x", {"v": 1, "n": [1], "e": Event("in", {"w": 1})}))
            sd = seen[0] if seen else _MISSING
            se = _item(sd, "e")
            if (_item(sd, "v") != 1 or _item(_item(sd, "n"), 0) != 1 or _isAny(sd, touched)
                    or _isAny(se, touched) or _attr(se, "type") != "in" or _item(_attr(se, "data"), "w") != 1):
                fail("an observer's write or rawset reached another observer's record")
            rd = _attr(s.takeResidue()[0], "data")
            re_ = _item(rd, "e")
            if (_item(rd, "v") != 1 or _item(_item(rd, "n"), 0) != 1 or _isAny(rd, touched)
                    or _isAny(re_, touched) or _attr(re_, "type") != "in" or _item(_attr(re_, "data"), "w") != 1):
                fail("an observer's write or rawset reached the residue")

        # the rejection (THE REJECTION): each observer's copy is its own;
        # nothing done to it reaches the raised one or another observer
        for writerFirst in (True, False):
            s = Stream()
            thrown = _ProbeError()

            def raiser(e):
                raise thrown

            s.register(Gate("a", raiser))
            seen = []

            def writer(b):
                r = b["rejection"]

                def w():
                    r["code"] = "CHANGED"

                if not _refused(w):
                    fail("an observer's rejection accepts assignment")
                if isinstance(r, dict):
                    r["eventId"] = 99
                else:
                    _rawset(r, "code", "CHANGED")
                    _rawset(r, "eventId", 99)
                cause = _field(r, "cause")
                if isinstance(cause, dict):
                    cause["first"] = "CHANGED"
                elif cause is not None and not isinstance(cause, collections.abc.Mapping):
                    _rawset(cause, "first", "CHANGED")
                nested = _field(cause, "nested")
                if isinstance(nested, list):
                    nested[0] = 9

            def reader(b):
                seen.append(b["rejection"])

            if writerFirst:
                s.attach(writer)
                s.attach(reader)
            else:
                s.attach(reader)
                s.attach(writer)
            raised = _emitCatch(s, Event("a"))
            if raised is None:
                fail("rejection ownership: the gate's raise did not reject")
                continue
            if _attr(raised, "code") != "SISO_GATE_THREW" or _attr(raised, "eventId") != 1:
                fail("an observer's write or rawset reached the rejection emit raised")
            if _attr(raised, "cause") is not thrown or thrown.first != "the gate's own" or thrown.nested[0] != 1:
                fail("an observer's write or rawset reached what the gate raised")
            sr = seen[0] if seen else None
            cause = _field(sr, "cause")
            if (_field(sr, "code") != "SISO_GATE_THREW" or _field(sr, "eventId") != 1
                    or _field(cause, "first") != "the gate's own" or _item(_field(cause, "nested"), 0) != 1):
                fail("an observer's write or rawset on its rejection reached another observer's")

        # observers are shown batches built before any is called: one that
        # takes the residue and writes to it reaches no other (both orders)
        for takerFirst in (True, False):
            s = Stream()
            seenV = []
            touchedT = []

            def taker(b):
                for ev in s.takeResidue():
                    d = _attr(ev, "data")
                    touchedT.append(d)
                    if isinstance(d, dict):
                        d["v"] = "TAKER WROTE"
                    _rawset(ev, "data", {"v": "TAKER WROTE"})

            def reader(b):
                seenV.append(b["records"][0]["data"])

            if takerFirst:
                s.attach(taker)
                s.attach(reader)
            else:
                s.attach(reader)
                s.attach(taker)
            s.emit(Event("x", {"v": 1}))
            sv = seenV[0] if seenV else _MISSING
            if _item(sv, "v") != 1 or _isAny(sv, touchedT):
                fail("an observer that took the residue and wrote to it reached another observer's records (" +
                     ("taker first" if takerFirst else "reader first") + ")")

        # nothing the core or the gate holds is reachable from an observer's
        # rejection: an observer that writes to every class (and object) its
        # copy reaches changes neither the host's rejection, what the gate
        # raised, nor a later stream's rejection
        class IoError(Exception):
            kind = "io"

            def __init__(self):
                super().__init__("io failure")
                self.msg = "m"

        saved = []

        def tamper(b):
            stack = [b.get("rejection")]
            seenT = set()
            while stack:
                o = stack.pop()
                if type(o) in _PRIMITIVES or id(o) in seenT:
                    continue
                seenT.add(id(o))
                if isinstance(o, (tuple, list)):
                    stack.extend(o)
                    continue
                if isinstance(o, collections.abc.Mapping):
                    try:
                        stack.extend(v for _, v in list(o.items()))
                    except Exception:
                        pass
                else:
                    stack.extend(_ownVars(o).values())
                    if _rawset(o, "missing", "OBSERVER WROTE"):
                        saved.append((o, "missing", False, None, False))
                cls = type(o)
                if _substrate(cls) or issubclass(cls, type):
                    continue
                for name, value in (("kind", "OBSERVER WROTE"), ("missing", "OBSERVER WROTE"),
                                    ("__str__", lambda self: "OBSERVER WROTE")):
                    had = name in cls.__dict__
                    old = cls.__dict__.get(name)
                    try:
                        setattr(cls, name, value)
                        saved.append((cls, name, had, old, True))
                    except Exception:
                        pass

        def restore():
            for target, name, had, old, isClass in reversed(saved):
                try:
                    if had:
                        type.__setattr__(target, name, old)
                    elif isClass:
                        type.__delattr__(target, name)
                    else:
                        object.__delattr__(target, name)
                except Exception:
                    pass
            saved.clear()

        try:
            m = Stream()

            def ioGate(e):
                raise IoError()

            m.register(Gate("a", ioGate))
            m.attach(tamper)
            raisedM = _emitCatch(m, Event("a"))
            if (raisedM is None or _str(raisedM) != _attr(raisedM, "message")
                    or _attr(raisedM, "missing") is not _MISSING or _attr(raisedM, "code") != "SISO_GATE_THREW"):
                fail("an observer reached the host's rejection through its copy's classes")
            else:
                causeM = _attr(raisedM, "cause")
                if (not isinstance(causeM, IoError) or causeM.kind != "io" or causeM.msg != "m"
                        or _attr(causeM, "missing") is not _MISSING or _str(causeM) != "io failure"):
                    fail("an observer reached what the gate raised through its copy's classes")
            later = Stream()

            def lateBoom(e):
                raise RuntimeError("boom")

            later.register(Gate("a", lateBoom))
            raisedL = _emitCatch(later, Event("a"))
            if (raisedL is None or _str(raisedL) != _attr(raisedL, "message")
                    or _attr(raisedL, "missing") is not _MISSING):
                fail("an observer's write through its copy's classes reached a later stream's rejection")
        finally:
            restore()
        m2 = Stream()
        m2.register(Gate("a", ioGate))
        shown = []
        m2.attach(lambda b: shown.append(b.get("rejection")))
        raised2 = _emitCatch(m2, Event("a"))
        sh = shown[0] if shown else None
        why = rejectionCopyProblem(sh, raised2, Event)
        if why:
            fail("an error object raised by a gate: " + why)
        shCause = _field(sh, "cause")
        if isinstance(shCause, collections.abc.Mapping):
            if _item(shCause, "msg") != "m":
                fail("an observer's copy lost the cause's own field")
            if _item(shCause, "kind") is not _MISSING:
                fail("an observer's copy carries the cause's inherited field")

        # frames: an observer that reads the frames below it finds neither
        # the host's rejection nor the observers list among their arguments
        stop = sys._getframe()
        found = []

        def peeker(b):
            f = sys._getframe(1)
            while f is not None and f is not stop:
                co = f.f_code
                n = co.co_argcount + co.co_kwonlyargcount
                n += 1 if co.co_flags & 0x04 else 0  # *args
                n += 1 if co.co_flags & 0x08 else 0  # **kwargs
                try:
                    loc = f.f_locals
                    for name in co.co_varnames[:n]:
                        if name in loc:
                            found.append((co.co_name, name, loc[name]))
                except Exception:
                    pass
                f = f.f_back

        fs = Stream()

        def fboom(e):
            raise RuntimeError("frames")

        fs.register(Gate("a", fboom))
        other = lambda b: None  # noqa: E731
        fs.attach(peeker)
        fs.attach(other)
        raisedF = _emitCatch(fs, Event("a"))
        for fname, aname, value in found:
            if raisedF is not None and value is raisedF:
                fail("an observer found the host's rejection among the arguments of a frame below it (" +
                     fname + "(" + aname + "), sys._getframe)")
            if isinstance(value, (list, tuple, set, frozenset)):
                try:
                    holds = any(x is peeker or x is other for x in list(value))
                except Exception:
                    holds = False
                if holds:
                    fail("an observer found the observers list among the arguments of a frame below it (" +
                         fname + "(" + aname + "), sys._getframe)")
        del found[:]

        # an entry 50,000 deep with an observer attached raises nothing but
        # a rejection (the observer's copies are iterative)
        deep = {}
        cur = deep
        for _ in range(50000):
            nxt = {}
            cur["x"] = nxt
            cur = nxt
        try:
            built = Event("deep", {"d": deep})
        except Exception as err:
            built = None
            fail("Event refused data 50000 deep: " + _typeName(err))
        del deep, cur
        if built is not None:
            ds = Stream()
            db = []
            ds.attach(lambda b: db.append(len(b.get("records") or [])))
            errD = _emitCatch(ds, built)
            if errD is not None and _attr(errD, "code") not in ("SISO_SELF_LOOP", "SISO_CYCLE", "SISO_GATE_THREW",
                                                                 "SISO_NOT_EVENT"):
                fail("an entry 50000 deep with an observer attached made emit raise " + _typeName(errD))
            del built, ds

        # the cause is snapshotted once per rejection, whatever the number
        # of observers: a gate's code that runs while the cause is read
        # (here a __dict__ property) runs the same number of times with 0,
        # 1 or 3 observers attached, and every observer's copy is equal
        # (independent review, 13.2.0)
        reads = []

        class Counting(Exception):
            @property
            def __dict__(self):
                reads.append(1)
                return {"n": len(reads)}

        runs = {}
        for count in (0, 1, 3):
            del reads[:]
            cs = Stream()
            cs.register(Gate("c", lambda e: (_ for _ in ()).throw(Counting("x"))))
            seenC = []
            for _ in range(count):
                cs.attach(lambda b, into=seenC: into.append(b.get("rejection")))
            _emitCatch(cs, Event("c"))
            runs[count] = len(reads)
            copies = [c.get("cause") if c is not None else None for c in seenC]
            if any(not _same(c, copies[0], Event) for c in copies):
                fail("observers of one rejection were shown unequal copies of its cause")
        if len(set(runs.values())) != 1:
            fail("the gate's code ran a different number of times with 0, 1 and 3 observers attached "
                 "(" + ", ".join(str(k) + ": " + str(v) for k, v in sorted(runs.items())) +
                 "): attaching observers changed what the propagation did")
    except Exception as err:
        fail("raised " + _typeName(err) + ": " + _str(err))
    return p
