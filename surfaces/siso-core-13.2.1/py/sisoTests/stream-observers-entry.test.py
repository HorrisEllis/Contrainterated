# stream-observers-entry.test.py -- every observer's batch is built before
# any observer is called (py-observer-seam ISOLATION); an entry the stream
# cannot copy is refused as SISO_NOT_EVENT, never raised raw (py-core-api:
# nothing else is raised); an observer's plain rejection copy keeps lists
# as tuples (THE REJECTION). Port of
# lua/sisoTests/stream-observers-entry.test.lua, case by case. The gates
# below are fixtures for the stream, not project gates.
#
# How the Lua cases map (py-core-api SUBSTRATE):
#   - Lua's rawset on taken residue becomes object.__setattr__ on the
#     FrozenDict's storage slot.
#   - Lua's unbuildable batch (a cause whose __pairs raises) becomes an
#     exception that is also a Mapping whose iteration raises.
#   - "an entry Event accepts but the stream cannot copy": Lua probes the
#     depth window where Event accepts and the recursive copy overflows.
#     Python's copies are iterative, so no depth is too deep (the case
#     probes 100,000 levels and expects them to settle, as Lua's "no depth
#     in the window overflowed" branch allows); Python's own entries the
#     stream cannot copy are an Event built past its constructor
#     (object.__new__(Event), fields unset) and an Event whose data was
#     rawset to something that is not plain data: both are SISO_NOT_EVENT
#     with no delivery and nothing committed.
#   - kit.data's array and null marks vanish (a list is a tuple, null is
#     None; siso.kit is not this phase's): the marks case pins that a list
#     in a cause reaches the observer as a tuple, an empty one as (), and
#     None as None, and that the copy is a FrozenDict.
#   - "hands out no table through getmetatable" becomes: everything in the
#     copy is a FrozenDict, a tuple or a leaf (no class but the core's
#     locked FrozenDict).
# No case is left out.
# Runnable from any directory: python3 py/sisoTests/stream-observers-entry.test.py
import sys

sys.dont_write_bytecode = True

import collections.abc  # noqa: E402
import os  # noqa: E402
import types  # noqa: E402

HERE = os.path.dirname(os.path.abspath(__file__))
sys.path.insert(0, os.path.normpath(os.path.join(HERE, "..", "code")))

from siso import harness as H  # noqa: E402
from siso.contracts import core_api as API  # noqa: E402
from siso.contracts import observer_seam as SEAM  # noqa: E402
from siso.core.event import Event  # noqa: E402
from siso.core.freeze import FrozenDict  # noqa: E402
from siso.core.gate import Gate  # noqa: E402
from siso.core.stream import Stream  # noqa: E402

core = {"Event": Event, "Gate": Gate, "Stream": Stream}
test, assert_, assertEqual = H.test, H.assert_, H.assertEqual


def noProblems(lst, label=None):
    assert_(len(lst) == 0, ((label + ": ") if label else "") + "; ".join(lst))


def rawsetItems(fd, **changes):
    d = dict(fd.items())
    d.update(changes)
    object.__setattr__(fd, "_m", types.MappingProxyType(d))


def raiser(exc):
    def g(e):
        raise exc
    return g


def deepDict(depth):
    root = {}
    cur = root
    for _ in range(depth):
        nxt = {}
        cur["x"] = nxt
        cur = nxt
    return root


# ── the contracts on the real core ──────────────

test("core-api: ownership holds on the core (entry copy, deep entries)", lambda: noProblems(API.checkOwnership(core)))


def t_seam():
    noProblems(SEAM.checkOwnership(core), "ownership")
    noProblems(SEAM.checkSeam(core), "seam")


test("observer-seam: ownership and seam hold on the core", t_seam)

# ── batches built before any observer is called ──


def t_taker():
    for takerFirst in (True, False):
        s = Stream()
        s.register(Gate("a", lambda e: Event("b", {"v": e.data["v"], "list": [1]})))
        seen = []

        def taker(b, s=s):
            for e in s.takeResidue():
                rawsetItems(e.data, v="TAKER WROTE", list=("TAKER WROTE",))

        def reader(b, seen=seen):
            seen.append(b)

        if takerFirst:
            s.attach(taker)
            s.attach(reader)
        else:
            s.attach(reader)
            s.attach(taker)
        s.emit(Event("a", {"v": 1}))
        label = "taker first" if takerFirst else "reader first"
        assertEqual(seen[0]["records"][0]["data"]["v"], 1, label + ": entry record")
        assertEqual(seen[0]["records"][1]["data"]["v"], 1, label + ": settled record")
        assertEqual(seen[0]["records"][1]["data"]["list"][0], 1, label + ": nested data")


test("an observer that takes the residue and writes to it reaches no other observer (both orders)", t_taker)


def t_raising_observer():
    s = Stream()
    got = []

    def fails(b):
        raise RuntimeError("first observer fails")

    s.attach(fails)
    s.attach(lambda b: got.append(b["outcome"]))
    s.emit(Event("x"))
    assertEqual(",".join(got), "settled")


test("an observer that raises still leaves every other observer its batch", t_raising_observer)


class Unreadable(Exception, collections.abc.Mapping):
    def __getitem__(self, k):
        raise RuntimeError("cannot be read")

    def __iter__(self):
        raise RuntimeError("cannot be read")

    def __len__(self):
        raise RuntimeError("cannot be read")


def t_unbuildable():
    s = Stream()
    s.register(Gate("a", raiser(Unreadable("unreadable"))))
    calls = []
    s.attach(lambda b: calls.append(1))
    try:
        s.emit(Event("a"))
        H.fail("the gate's raise did not reject")
    except Exception as r:
        assertEqual(getattr(r, "code", None), "SISO_GATE_THREW")
    assertEqual(len(calls), 0, "the observer whose batch could not be built was called")


test("a batch that cannot be built fails its observer alone: the rejection is raised all the same", t_unbuildable)

# ── an entry the stream cannot copy ─────────────


def t_cannot_copy():
    # no depth is too deep for the iterative copy: 100,000 levels settle
    built = Event("deep", {"d": deepDict(100000)})
    s = Stream()
    s.emit(built)
    assertEqual(len(s.takeResidue()), 1, "an entry 100,000 deep is copied and settles")
    del built

    forged = object.__new__(Event)
    rawData = Event("raw")
    object.__setattr__(rawData, "data", {"s": {1, 2}})
    for label, entry in (("an Event built past its constructor", forged),
                         ("an Event whose data was rawset to a set", rawData)):
        s = Stream()
        s.emit(Event("earlier"))
        seen = []
        s.attach(lambda b, seen=seen: seen.append(b))
        try:
            s.emit(entry)
            H.fail(label + ": settled")
        except Exception as r:
            assert_(type(r).__name__ == "Rejection", label + ": emit raised " + type(r).__name__ + ", not a rejection")
            noProblems(API.rejectionProblems(r, {"code": "SISO_NOT_EVENT", "noDelivery": True}), label)
        assertEqual(len(s.sampleHere()["residue"]), 1, label + ": a refused entry committed residue")
        assert_(seen and seen[0]["outcome"] == "rejected" and len(seen[0]["records"]) == 0, label + ": the observer's batch")


test("an entry Event accepts but the stream cannot copy is SISO_NOT_EVENT, no delivery, nothing committed",
     t_cannot_copy)


def t_shallow_after():
    s = Stream()
    try:
        s.emit(object.__new__(Event))
    except Exception:
        pass
    s.emit(Event("fine", {"v": 1}))
    r = s.takeResidue()
    assertEqual(r[-1].type, "fine")
    assertEqual(r[-1].data["v"], 1)


test("a shallow entry is unaffected: copied, settled, the stream usable after a refused deep one", t_shallow_after)

# ── plain rejection copies keep string marks ────


class Rows(Exception):
    def __init__(self):
        super().__init__("rows")
        self.rows = []
        self.list = [1, 2]
        self.n = None


def t_marks():
    s = Stream()
    s.register(Gate("a", raiser(Rows())))
    shown = []
    s.attach(lambda b: shown.append(b["rejection"]))
    try:
        s.emit(Event("a"))
    except Exception as err:
        raised = err
    why = SEAM.rejectionCopyProblem(shown[0], raised, Event)
    assert_(why is None, str(why))
    c = shown[0]["cause"]
    assertEqual(c["rows"], (), "an empty list in the cause reaches the observer as ()")
    assertEqual(c["list"], (1, 2), "a list in the cause reaches the observer as a tuple")
    assert_(c["n"] is None, "None in the cause is None")
    assert_(type(shown[0]) is FrozenDict, "the copy itself is a FrozenDict")


test("an observer's rejection keeps string marks: an array in a cause stays an array", t_marks)


class IoError(Exception):
    kind = "io"


def t_no_class():
    inner = IoError("inner")
    outer = IoError("outer")
    outer.inner = inner
    s = Stream()
    s.register(Gate("a", raiser(outer)))
    shown = []
    s.attach(lambda b: shown.append(b["rejection"]))
    try:
        s.emit(Event("a"))
    except Exception:
        pass
    stack = [shown[0]]
    seen = set()
    while stack:
        v = stack.pop()
        if id(v) in seen:
            continue
        seen.add(id(v))
        t = type(v)
        assert_(t in (FrozenDict, tuple, str, int, float, bool, type(None)), "an object in the copy is a " + t.__name__)
        if t is FrozenDict:
            stack.extend(v.values())
        elif t is tuple:
            stack.extend(v)
    assert_(type(shown[0]["cause"]["inner"]) is FrozenDict, "a nested error object is a FrozenDict of its own fields")


test("an observer's rejection hands out no table through getmetatable, at any depth", t_no_class)

H.finish("stream-observers-entry")
