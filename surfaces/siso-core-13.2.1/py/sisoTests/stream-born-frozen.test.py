# stream-born-frozen.test.py -- an event is read-only from the moment Event
# builds it (py-core-api EVENT / READ-ONLY). Port of
# lua/sisoTests/stream-born-frozen.test.lua, case by case. Runs the
# core-api read-only check on the real core, then pins what it leaves open:
# the refusals, defaults, identities kept, and that the stream passes a
# born-frozen event through untouched. The gates below are fixtures for
# the stream, not project gates.
#
# How the Lua cases map (py-core-api SUBSTRATE):
#   - Lua refuses every write with "read-only: ...". Python refuses an
#     attribute write on an event with that AttributeError; a write into
#     its data is refused by the language itself (FrozenDict and tuple
#     support no item assignment: TypeError), so the refusal checked for
#     items is "does not support item assignment".
#   - freeze.isFrozen becomes the exact frozen types: an Event, a
#     FrozenDict, a tuple for a list.
#   - "extra arguments attach nothing": Python refuses them (arity,
#     TypeError), never drops them.
#   - Lua's data marks and "readonly:" / __readonly sentinels vanish (None
#     is null, a tuple is a list): the identity cases keep frozen data and
#     a carried event; None keeps its identity as any None does; a None as
#     the data itself gives an empty FrozenDict (no data), where Lua keeps
#     the sentinel table.
#   - "the proxy's own metatable is never handed out" becomes "the class
#     reached is the core's locked FrozenDict".
#   - "a cycle terminates": Python refuses a cycle (plain data is acyclic;
#     a tuple cannot hold itself), with the contract's message.
#   - R3's `rawset` is Python's object.__setattr__ on the slots: pinned as
#     not refused (the substrate concession), as in Lua.
# No case is left out.
# Runnable from any directory: python3 py/sisoTests/stream-born-frozen.test.py
import sys

sys.dont_write_bytecode = True

import os  # noqa: E402

HERE = os.path.dirname(os.path.abspath(__file__))
sys.path.insert(0, os.path.normpath(os.path.join(HERE, "..", "code")))

from siso import harness as H  # noqa: E402
from siso.contracts import core_api as API  # noqa: E402
from siso.core.event import Event  # noqa: E402
from siso.core.freeze import FrozenDict  # noqa: E402
from siso.core.gate import Gate  # noqa: E402
from siso.core.stream import Stream  # noqa: E402

core = {"Event": Event, "Gate": Gate, "Stream": Stream}
test, assert_, assertEqual = H.test, H.assert_, H.assertEqual

READ_ONLY = "read-only"
NO_ITEMS = "does not support item assignment"


def refuses(label, fn, needle=READ_ONLY):
    try:
        fn()
    except Exception as err:
        assert_(needle in str(err), label + ": raised " + type(err).__name__ + ": " + str(err))
        return
    H.fail(label + " was accepted")


def fieldsOf(o):
    names = set()
    try:
        names.update(object.__getattribute__(o, "__dict__"))
    except Exception:
        pass
    for c in type(o).__mro__:
        for n in c.__dict__.get("__slots__", ()):
            try:
                object.__getattribute__(o, n)
                names.add(n)
            except Exception:
                pass
    return ",".join(sorted(names))


def isFrozen(v):
    return type(v) in (Event, FrozenDict, tuple)


def setItem(c, k, v):
    c[k] = v


# ── the contract on the real core ──────────────

def t_readonly():
    p = API.checkReadonly(core)
    assert_(not p, "; ".join(p))


def t_doors():
    p = API.checkDoors(core)
    assert_(not p, "; ".join(p))


test("core-api read-only holds on the core (from construction)", t_readonly)
test("core-api doors still hold on the core", t_doors)

# ── what Event builds ──────────────────────────


def t_every_depth():
    e = Event("t", {"v": 1, "list": [1, 2], "m": {"inner": {"x": 5}}})
    refuses("the type", lambda: setattr(e, "type", "z"))
    refuses("the data", lambda: setattr(e, "data", {}))
    refuses("a new field", lambda: setattr(e, "extra", 1))
    refuses("deleting the type", lambda: delattr(e, "type"))
    refuses("an attribute of the data", lambda: setattr(e.data, "v", 2))
    refuses("a data field", lambda: setItem(e.data, "v", 2), NO_ITEMS)
    refuses("a new data key", lambda: setItem(e.data, "added", 1), NO_ITEMS)
    refuses("a list member", lambda: setItem(e.data["list"], 0, 0), NO_ITEMS)
    refuses("deep data", lambda: setItem(e.data["m"]["inner"], "x", 0), NO_ITEMS)
    assertEqual(fieldsOf(e), "data,type")
    assertEqual(e.type + ":" + str(e.data["v"]) + ":" + str(e.data["list"][0]) + ":" + str(e.data["m"]["inner"]["x"]),
                "t:1:1:5", "it reads back unchanged")
    assert_(isFrozen(e) and isFrozen(e.data) and isFrozen(e.data["list"]), "the event and its data are frozen")
    assert_(Event.isEvent(e), "Event.isEvent recognises it")


test("a new event refuses assignment at every depth, with the read-only refusal", t_every_depth)


def t_defaults():
    d = Event("t")
    assertEqual(",".join(d.data), "")
    assert_(type(d.data) is FrozenDict, "default data is a FrozenDict")
    refuses("default data", lambda: setItem(d.data, "x", 1), NO_ITEMS)
    H.assertThrows(lambda: Event("t", {"v": 1}, {"attempt": 1}, "more"), "TypeError", "extra arguments")
    x = Event("t", {"v": 1})
    assertEqual(fieldsOf(x), "data,type")
    assertEqual(",".join(x.data), "v")


test("default data is an empty frozen table; extra arguments attach nothing", t_defaults)


def t_as_it_stands():
    orig = {"v": 1, "list": [1], "m": {"k": "a"}}
    e = Event("t", orig)
    orig["v"] = 2
    orig["list"][0] = 9
    orig["m"]["k"] = "b"
    orig["late"] = True
    assertEqual(e.data["v"], 1)
    assertEqual(e.data["list"][0], 1)
    assertEqual(e.data["m"]["k"], "a")
    assertEqual(e.data.get("late"), None)
    assert_(type(orig) is dict and type(orig["list"]) is list, "the caller's dict stays its own")
    assertEqual(",".join(sorted(orig)), "late,list,m,v", "the caller's dict carries nothing new")


test("data is taken as it stands: later writes to the passed table do not reach the event", t_as_it_stands)


def t_identity():
    a = Event("a", {"v": 1})
    passed = Event("b", a.data)
    assert_(passed.data is a.data, "frozen data passed on is the same FrozenDict")
    carried = Event("c", {"inner": a, "d": a.data, "n": None, "list": [None]})
    assert_(carried.data["inner"] is a, "an event in data is that event")
    assert_(carried.data["d"] is a.data, "frozen data nested is that data")
    assert_(carried.data["n"] is None, "None nested is None")
    assert_(carried.data["list"][0] is None, "None in a list is None")
    s = Event("s", None).data
    assert_(type(s) is FrozenDict and len(s) == 0, "None as the data itself gives an empty FrozenDict")


test("read-only data keeps its identity: frozen data, a carried event, a __readonly sentinel", t_identity)


def t_marks_sharing_cycle():
    shared = [1]
    e = Event("t", {"list": [1, 2], "plain": {}, "a": shared, "b": shared})
    assert_(type(e.data["list"]) is tuple, "a list reads back as a tuple (its own mark)")
    assert_(type(e.data["plain"]) is FrozenDict, "a nested dict is the core's FrozenDict")
    H.assertThrows(lambda: setattr(type(e.data["plain"]), "x", 1), "locked", "the class reached is locked")
    assert_(e.data["a"] is e.data["b"], "a list reached twice is one frozen tuple")
    cyclic = {"name": "c"}
    cyclic["self"] = cyclic
    try:
        Event("cyc", cyclic)
        H.fail("a cycle was accepted")
    except TypeError as err:
        assertEqual(str(err), "Event: data at data['self'] is not plain data (a cycle)")


test("marks survive and sharing is kept; a cycle terminates (not plain data)", t_marks_sharing_cycle)

# ── the stream with born-frozen events ─────────


def t_entry_copy():
    # OWNERSHIP: entry copies, so the gate is given the stream's own event,
    # equal in value; inside the propagation a gate's return is shared with
    # the stream and keeps its identity.
    entered = Event("fx_in", {"v": 1, "list": [1]})
    got = {}

    def fn(e):
        got["given"] = e
        got["returned"] = Event("fx_out", {"v": e.data["v"]})
        return got["returned"]

    s = Stream()
    s.register(Gate("fx_in", fn))
    s.emit(entered)
    given = got["given"]
    assert_(given is not entered, "the gate is given the stream's own copy, not the entering code's event")
    assert_(given.data is not entered.data and given.data["list"] is not entered.data["list"],
            "the copy shares no container with the entering code's event")
    assertEqual(given.type + ":" + str(given.data["v"]) + ":" + str(given.data["list"][0]), "fx_in:1:1",
                "the copy reads the same")
    assert_(Event.isEvent(given) and isFrozen(given.data), "the copy is a frozen event")
    out = s.takeResidue()[0]
    assert_(out is got["returned"], "the residue is the very event the gate built")


test("entry takes the stream's own copy of a born-frozen event; a returned one passes untouched", t_entry_copy)


def t_in_gate():
    s = Stream()
    got = {}

    def build(e):
        out = Event("fx_out", {"v": 1})
        try:
            setItem(out.data, "v", 2)
            got["inGate"] = True
        except TypeError:
            got["inGate"] = False
        try:
            out.type = "z"
            got["field"] = True
        except AttributeError:
            got["field"] = False
        return out

    s.register(Gate("fx_build", build))
    s.emit(Event("fx_build"))
    assertEqual(got["inGate"], False, "a write to a just-built event's data is refused in the gate")
    assertEqual(got["field"], False, "a write to a just-built event's field is refused in the gate")
    assertEqual(s.takeResidue()[0].data["v"], 1)


test("an event a gate builds is read-only inside the gate, before it is returned", t_in_gate)


def t_writer():
    def writesItem(e):
        e.data["v"] = 2

    def writesField(e):
        e.type = "z"

    for label, fn, needle in (("an item", writesItem, NO_ITEMS), ("a field", writesField, READ_ONLY)):
        s = Stream()
        s.register(Gate("fx_writer", fn))
        try:
            s.emit(Event("fx_writer", {"v": 1}))
            H.fail(label + ": a writing gate settled")
        except Exception as r:
            p = API.rejectionProblems(r, {"code": "SISO_GATE_THREW", "eventId": 1, "eventType": "fx_writer",
                                          "entry": True})
            assert_(not p, label + ": " + "; ".join(p))
            assert_(needle in str(r.cause), label + ": the cause is the refusal: " + str(r.cause))
        assertEqual(len(s.sampleHere()["residue"]), 0)


test("a gate writing its born-frozen input rejects as a throw, and commits nothing", t_writer)


def t_rawset():
    e = Event("t", {"v": 1})
    object.__setattr__(e, "type", "z")
    assertEqual(e.type, "z", "Python's rawset bypasses the refusal (substrate concession, Lua's R3)")
    object.__setattr__(e, "data", {"v": 2})
    assertEqual(e.data["v"], 2, "a rawset can put anything in a slot")


test("R3 concession: rawset on a born-frozen event is not refused (pinned so a change is seen)", t_rawset)

H.finish("stream-born-frozen")
