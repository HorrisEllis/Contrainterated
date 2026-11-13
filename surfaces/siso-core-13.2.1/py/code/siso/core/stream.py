"""siso.core.stream -- Stream, the place where events propagate.

Contracts: py-core-api (the doors, ids, rejections, ownership) and
py-observer-seam (what an observer is shown). Core 13.0.0. Port of
lua/code/core/stream.lua.

  from siso.core.stream import Stream
  s = Stream()            no gates, no residue, no observers; it takes no
                          argument (Python's arity check refuses one)
  s.register(gate)        building; a Gate (else TypeError); a duplicate
                          signature raises ValueError("Signature collision: '<sig>'")
  s.emit(event)           entry; one propagation until it settles, returns
                          None, or raises a Rejection
  s.takeResidue()         take; -> the residue as a list, stream emptied
  s.sampleHere()          sample; -> {"residue": <the sampler's own frozen
                          copies>, "gateCount": <registered gates>}
  s.attach(fn) / s.detach(fn)
                          observe; attaching a value that is not callable
                          (TypeError), an attached observer or detaching
                          one that is not attached (ValueError) raises

The stream's state (the gate table, the residue, the observers) lives in
closures the six doors share. The instance has exactly the six slots
(no __dict__), refuses setting or deleting an attribute (no door can be
shadowed), refuses __init__ a second time, and its class is locked
(Lua's R5 thins to the introspection named in py-core-api SUBSTRATE:
door.__closure__, gc, frames).

Registration reads the gate's signature and transform once, through the
Gate's slots, and keeps them; the gate object itself is never handed to
anything. A transform is called with the event as its one positional
argument.

Delivery is an iterative FIFO queue (breadth-first): a gate's outputs join
the back of the queue, so no call-stack depth is spent and chain length is
bounded by acyclicity alone. The order is an implementation choice (K1),
not semantics. Each queue entry carries its cause id and depth beside the
event (K4), in parallel lists local to emit; none of them is ever put on
the event.

Ids are per propagation: each emit numbers its deliveries 1, 2, ... in a
local, so nothing is counted across propagations and no id outlives the
propagation except in a rejection or an observer's batch.

A gate's return is judged before anything it returned is queued:
  None                consumed
  an Event            one follow-up
  a list or a tuple   exact types, read once into the stream's own list,
                      member by member in order: the first member that is
                      not an Event (SISO_NOT_EVENT) or is the gate's own
                      type (SISO_SELF_LOOP) decides; empty: consumed
  anything else       SISO_NOT_EVENT (a subclass of list or tuple, a dict,
                      a FrozenDict, a generator, a coroutine ...)
An Event whose fields are unset (built past its constructor) is
SISO_NOT_EVENT. Nothing a gate returns is dropped or repaired (Drift 7).
X1 (SPEC.md) applied: the stream queues exactly the members judge read
(a list subclass's __iter__ or __len__ is never asked), and recognises
an Event by its exact class, never by what the value answers.
Judging runs no code of the value judged.

Release (py-core-api SUBSTRATE): what a gate returned is released inside
the stream's protection right after it is judged; a returned coroutine or
generator is closed there (no "never awaited" warning), and an exception
from closing it is swallowed: the judged rejection stands. CPython reports
an exception raised in __del__ through sys.unraisablehook, never as a
raise out of emit.

Read-only events: Event makes every event read-only from construction, so
an event a gate returns is queued as it is, keeping its identity (inside a
propagation a gate shares its input with the stream).

Ownership (py-core-api OWNERSHIP, py-observer-seam RECORD data and THE
REJECTION): entry takes the stream's own copy of the event
(freeze.copy: a fresh Event, FrozenDict and tuple at every depth), so
nothing the entering code holds is in the residue. A take hands the
residue over. A sample's residue holds freeze.copy of each event (one memo
per sample); each observer's records carry freeze.copy of the data (one
memo per observer), and each observer's rejection is a FrozenDict of the
raised rejection's fields. No copy of data is made for observers when none
is attached.

The rejection's cause (py-observer-seam THE REJECTION, 13.2.0) is
snapshotted once per rejection, when the propagation is rejected, whether
or not an observer is attached: freeze.plain reads it into the core's own
values, under protection, so code of the gate's objects that the reading
runs (a __dict__ property, a foreign Mapping, a key's __repr__) runs once
per rejection, never once per observer. Each observer's `cause` is then a
fresh copy (freeze.copy) of that snapshot, which runs no code of the
gate's, so every observer's copy is equal. If the snapshot cannot be
taken, every observer's batch fails alone and the host's rejection is
unchanged; a process-level exit raised while it is taken passes through
emit, with or without observers.

Rejections (K5) commit nothing: residue is gathered in a local list and
joins the stream's only when the propagation settles. An entry the stream
cannot copy is SISO_NOT_EVENT ("an Event too deep to copy", or "an Event
whose fields are unset"); nothing else is raised, except a process-level
exit (a BaseException that is not an Exception) raised by a gate or an
observer, which passes through emit unchanged with nothing committed by
the propagation it interrupted (py-core-api SUBSTRATE).

Observers (py-observer-seam): the observers attached when the entry
arrives are each called once, after the propagation ended (settled: after
the residue is committed; rejected: just before the rejection is raised).
Every observer's batch is built, each under its own protection, before
any observer is called: a batch that cannot be built fails that observer
alone, an observer that raises fails alone, and emit returns or raises
exactly as with no observer. emit calls the observers from its own frame:
its only argument is the event, and no helper that calls an observer or a
gate is given the observers, the records or the rejection. emit raises
the rejection `from None`, so the gate's exception is reachable as
`.cause` only.
"""

import types

from siso.core.freeze import Event, LockedClass, copy, fieldsOf, lock, plain, typeName, _mkFD
from siso.core.gate import Gate, readGate
from siso.core.rejection import FIELDS, Rejection, describe, fieldOf

_typeof = type
_CLOSE = (types.CoroutineType, types.GeneratorType)
_selfLoop = Rejection.selfLoop
_cycle = Rejection.cycle
_gateThrew = Rejection.gateThrew
_notEvent = Rejection.notEvent
_UNSNAPPED = object()  # a cause whose snapshot could not be taken

DOORS = ("register", "emit", "takeResidue", "sampleHere", "attach", "detach")


def _judge(result, signature, delivery):
    """-> (outputs, None): the list of Events to queue, exactly the members
    read; or (None, rejection)."""
    if result is None:
        return (), None
    t = _typeof(result)
    if t is Event:
        try:
            out = fieldsOf(result)[0]
        except AttributeError:
            return None, _notEvent(delivery, signature, "an Event whose fields are unset")
        if _typeof(out) is str and out == signature:
            return None, _selfLoop(delivery, signature)
        return (result,), None
    if t is list or t is tuple:
        members = list(result)
        n = 0
        for m in members:
            n += 1
            if _typeof(m) is not Event:
                return None, _notEvent(delivery, signature, "a list whose member " + str(n) + " is " + describe(m))
            try:
                out = fieldsOf(m)[0]
            except AttributeError:
                return None, _notEvent(delivery, signature,
                                       "a list whose member " + str(n) + " is an Event whose fields are unset")
            if _typeof(out) is str and out == signature:
                return None, _selfLoop(delivery, signature)
        return members, None
    return None, _notEvent(delivery, signature, describe(result))


def _batch(outcome, rejection, snapshot, records):
    """One observer's batch: its own dicts and list, its own frozen copy of
    each record's data (one memo), and its own frozen copy of the
    rejection's fields, `cause` a fresh copy of the rejection's snapshot.
    Runs no code of a gate's."""
    if rejection is not None and snapshot is _UNSNAPPED:
        raise ValueError("the rejection's cause could not be snapshotted")
    memo = {}
    own = []
    for eventId, causeId, eventType, claimed, data in records:
        own.append({"eventId": eventId, "causeId": causeId, "eventType": eventType, "claimed": claimed,
                    "data": copy(data, memo)})
    shown = None
    if rejection is not None:
        fields = {}
        for k in FIELDS:
            v = fieldOf(rejection, k)
            if v is not None:
                fields[k] = copy(snapshot, {}) if k == "cause" else v
        shown = _mkFD(fields)
    return {"outcome": outcome, "rejection": shown, "records": own}


def _refuse(self, k, v=None):
    raise AttributeError("a stream's doors cannot be assigned (field '" + (k if _typeof(k) is str else "?") + "')")


class Stream(metaclass=LockedClass):
    __slots__ = DOORS

    def __init__(self):
        try:
            _SLOTS["emit"].__get__(self)
        except AttributeError:
            pass
        else:
            raise AttributeError("a stream is built once")
        gates = {}
        residue = []
        attached = []

        def register(gate):
            if not issubclass(_typeof(gate), Gate):
                raise TypeError("register: a gate is a Gate, got " + typeName(gate))
            try:
                signature, transform = readGate(gate)
            except AttributeError:
                raise TypeError("register: a gate is a Gate, got a Gate whose fields are unset") from None
            if _typeof(signature) is not str or not callable(transform):
                raise TypeError("register: a gate is a Gate, got a Gate whose fields were rewritten")
            if signature in gates:
                raise ValueError("Signature collision: '" + signature + "'")
            gates[signature] = transform

        def emit(event):
            observers = tuple(attached)
            records = [] if observers else None
            rejection = None
            settled = []
            if _typeof(event) is not Event:
                rejection = _notEvent(None, None, describe(event))
            else:
                try:
                    current = copy(event)
                except AttributeError:
                    rejection = _notEvent(None, None, "an Event whose fields are unset")
                except Exception:
                    rejection = _notEvent(None, None, "an Event too deep to copy")
            if rejection is None:
                gateCount = len(gates)
                queue = [current]
                causes = [None]
                depths = [1]
                head = 0
                lastId = 0
                current = None
                while head < len(queue):
                    current = queue[head]
                    causeId = causes[head]
                    depth = depths[head]
                    queue[head] = None
                    head += 1
                    lastId += 1
                    eventType, data = fieldsOf(current)
                    transform = gates.get(eventType) if _typeof(eventType) is str else None
                    if records is not None:
                        records.append((lastId, causeId, eventType, transform is not None, data))
                    data = None
                    if transform is None:
                        settled.append(current)
                        continue
                    if depth > gateCount:
                        rejection = _cycle((lastId, causeId, eventType, depth), gateCount)
                        break
                    try:
                        result = transform(current)
                    except Exception as exc:
                        rejection = _gateThrew((lastId, causeId, eventType, depth), eventType, exc)
                        break
                    outputs, rejection = _judge(result, eventType, (lastId, causeId, eventType, depth))
                    if _typeof(result) in _CLOSE:
                        try:
                            result.close()
                        except Exception:
                            pass
                    result = None
                    if rejection is not None:
                        break
                    for out in outputs:
                        queue.append(out)
                        causes.append(lastId)
                        depths.append(depth + 1)
                    outputs = out = None
                queue = causes = depths = current = transform = None
            snapshot = None
            if rejection is not None:
                try:
                    snapshot = plain(fieldOf(rejection, "cause"))
                except Exception:
                    snapshot = _UNSNAPPED
            if rejection is None:
                residue.extend(settled)
            settled = None
            if observers:
                outcome = "settled" if rejection is None else "rejected"
                batches = []
                for _ in observers:
                    try:
                        batches.append(_batch(outcome, rejection, snapshot, records))
                    except Exception:
                        batches.append(None)
                records = None
                for i in range(len(observers)):
                    b = batches[i]
                    batches[i] = None
                    if b is not None:
                        try:
                            observers[i](b)
                        except Exception:
                            pass
                    b = None
                observers = batches = None
            snapshot = None
            if rejection is not None:
                raise rejection from None

        def takeResidue():
            nonlocal residue
            taken = residue
            residue = []
            return taken

        def sampleHere():
            memo = {}
            return {"residue": [copy(e, memo) for e in residue], "gateCount": len(gates)}

        def attach(observer):
            if not callable(observer):
                raise TypeError("attach: an observer is a function, got " + typeName(observer))
            for o in attached:
                if o is observer:
                    raise ValueError("attach: this observer is already attached")
            attached.append(observer)

        def detach(observer):
            for i in range(len(attached)):
                if attached[i] is observer:
                    del attached[i]
                    return
            raise ValueError("detach: this observer is not attached")

        for k, fn in (("register", register), ("emit", emit), ("takeResidue", takeResidue),
                      ("sampleHere", sampleHere), ("attach", attach), ("detach", detach)):
            _SLOTS[k].__set__(self, fn)

    __setattr__ = _refuse
    __delattr__ = _refuse

    def __repr__(self):
        return "<siso.core Stream>"


_SLOTS = {k: Stream.__dict__[k] for k in DOORS}
lock(Stream)

__all__ = ["Stream"]
