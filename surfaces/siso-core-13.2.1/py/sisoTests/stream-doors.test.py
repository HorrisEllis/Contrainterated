# stream-doors.test.py -- the core's doors against py-core-api and
# py-observer-seam. Port of lua/sisoTests/stream-doors.test.lua, case by
# case. Runs both contracts' checks on the real core, then pins what the
# checks leave open. The gates below are fixtures for the stream, not
# project gates.
#
# How the Lua cases map (py-core-api SUBSTRATE):
#   - "each builder carries exactly its code's fields": a Python rejection
#     carries every field, None when its code does not carry it; the
#     fields that are not None are the Lua table's keys.
#   - "a gate's raised value is kept exactly, even nil": Python cannot
#     raise None. Its own attempt (`raise None`) raises TypeError inside
#     the gate, and that TypeError is the cause, kept exactly; a raised
#     exception object is kept by identity.
#   - Holes and named keys cannot exist in a Python list; their place is
#     taken by Python's own attempts at the same violation: a list with a
#     None where an Event should be ("a hole", "a list starting at 2"), a
#     list subclass carrying an extra attribute ("a named key"), a tuple
#     subclass, a dict keyed by position. "A member's self-loop before a
#     named key" becomes a self-loop before a later None member.
#   - Lua's misuse of attach/detach "raises a string"; Python raises the
#     contract's TypeError / ValueError with its messages.
#   - s.eventCount is AttributeError (no counter), not nil.
# No case is left out.
# Runnable from any directory: python3 py/sisoTests/stream-doors.test.py
import sys

sys.dont_write_bytecode = True

import os  # noqa: E402

HERE = os.path.dirname(os.path.abspath(__file__))
sys.path.insert(0, os.path.normpath(os.path.join(HERE, "..", "code")))

from siso import harness as H  # noqa: E402
from siso.contracts import core_api as API  # noqa: E402
from siso.contracts import observer_seam as SEAM  # noqa: E402
from siso.core.event import Event  # noqa: E402
from siso.core.gate import Gate  # noqa: E402
from siso.core.rejection import Rejection  # noqa: E402
from siso.core.stream import Stream  # noqa: E402

core = {"Event": Event, "Gate": Gate, "Stream": Stream}
test, assert_, assertEqual = H.test, H.assert_, H.assertEqual


def joined(lst):
    return "; ".join(lst)


def noProblems(lst, label=None):
    assert_(len(lst) == 0, ((label + ": ") if label else "") + joined(lst))


def keysOf(m):
    return ",".join(sorted(str(k) for k in m))


def carried(r):
    return ",".join(sorted(k for k in API.REJECTION_FIELDS if getattr(r, k) is not None))


def emitCatch(s, e):
    try:
        s.emit(e)
    except Exception as err:
        return err
    return None


def oneGate(sig, ret):
    s = Stream()
    s.register(Gate(sig, lambda e: ret))
    return s


def raiser(exc):
    def g(e):
        raise exc
    return g


# ── the contracts' own checks ──────────────────

test("core-api: checkDoors holds on the real core", lambda: noProblems(API.checkDoors(core)))
test("observer-seam: checkSeam holds on the real core", lambda: noProblems(SEAM.checkSeam(core)))

# ── rejections (siso.core.rejection) ───────────


def t_builders():
    d = (3, 2, "t", 3)
    cases = [
        (Rejection.selfLoop(d, "t"), "causeId,code,eventId,eventType,message"),
        (Rejection.cycle(d, 2), "causeId,code,depth,eventId,eventType,message"),
        (Rejection.gateThrew(d, "t", RuntimeError("x")), "cause,causeId,code,eventId,eventType,message"),
        (Rejection.notEvent(d, "t", "a str"), "causeId,code,eventId,eventType,message"),
        (Rejection.notEvent(None, None, "None"), "code,message"),
    ]
    for i, (r, want) in enumerate(cases, 1):
        assertEqual(carried(r), want, "case " + str(i))
        noProblems(API.rejectionProblems(r), "case " + str(i))
        assertEqual(str(r), r.message, "case " + str(i) + " str")
        assert_(Rejection.isRejection(r), "case " + str(i) + " Rejection.isRejection")
    assert_(not Rejection.isRejection({"code": "SISO_CYCLE", "message": "m"}), "a plain dict is not a rejection")
    assert_(not Rejection.isRejection(RuntimeError("m")), "another exception is not a rejection")
    H.assertThrows(lambda: Rejection("SISO_CYCLE", "m"), "only the stream", "Rejection built from outside")


test("rejection: each builder carries exactly its code's fields", t_builders)


def t_raised_kept():
    def raisesNone(e):
        raise None  # Python's own attempt at Lua's error(nil)

    s = Stream()
    s.register(Gate("raises_nil", raisesNone))
    r = emitCatch(s, Event("raises_nil"))
    assertEqual(r.code, "SISO_GATE_THREW")
    assert_(isinstance(r.cause, TypeError), "the attempt's TypeError is the cause: " + H.show(r.cause))
    noProblems(API.rejectionProblems(r))
    exc = KeyError("own")
    s.register(Gate("raises_own", raiser(exc)))
    r2 = emitCatch(s, Event("raises_own"))
    assert_(r2.cause is exc, "a raised exception is kept by identity")
    noProblems(API.rejectionProblems(r2, {"code": "SISO_GATE_THREW", "cause": exc}))


test("rejection: a gate's raised value is kept exactly, even nil", t_raised_kept)

# ── SISO_SELF_LOOP carries causeId ─────────────


def t_self_loop_ids():
    s = Stream()
    s.register(Gate("fx_head", lambda e: [Event("fx_side"), Event("fx_loop")]))
    s.register(Gate("fx_loop", lambda e: Event("fx_loop")))
    r = emitCatch(s, Event("fx_head"))
    noProblems(API.rejectionProblems(r, {"code": "SISO_SELF_LOOP", "eventId": 3, "causeId": 1,
                                         "eventType": "fx_loop"}))


test("SISO_SELF_LOOP names the returning delivery and its cause", t_self_loop_ids)

# ── SISO_NOT_EVENT: the entry door ─────────────


def t_entry_refused():
    delivered = []
    s = Stream()
    s.register(Gate("x", lambda e: delivered.append(1)))
    for v in ("x", 42, False, True, {}, {"type": "x", "data": {}}, Gate("x"), [Event("x")], Event):
        r = emitCatch(s, v)
        noProblems(API.rejectionProblems(r, {"code": "SISO_NOT_EVENT", "noDelivery": True}), type(v).__name__)
    assert_(not delivered, "a refused entry reached a gate")
    assertEqual(len(s.sampleHere()["residue"]), 0)


test("SISO_NOT_EVENT at the entry: nothing is delivered", t_entry_refused)

# ── SISO_NOT_EVENT: gate returns ───────────────


class Named(list):
    """Python's own attempt at a list with a named key: a list carrying an attribute."""


class Pair(tuple):
    pass


def t_not_event_returns():
    named = Named([Event("fx_out")])
    named.extra = Event("fx_out")
    cases = [
        ("false", False),
        ("true", True),
        ("a number", 0),
        ("a string", "fx_out"),
        ("a function", lambda: None),
        ("a plain dict shaped like an event", {"type": "fx_out", "data": {}}),
        ("a list with a named key (a list subclass carrying an attribute)", named),
        ("a list with a hole (a None between Events)", [Event("fx_out"), None, Event("fx_out")]),
        ("a list starting at 2 (a None in its first place)", [None, Event("fx_out")]),
        ("a dict keyed by position", {0: Event("fx_out")}),
        ("a tuple subclass", Pair((Event("fx_out"),))),
        ("a list with a plain-dict member", [Event("fx_out"), {"type": "fx_out", "data": {}}]),
        ("a list with a string member", [Event("fx_out"), "fx_out"]),
        ("a list with a false member", [False]),
        ("a list of lists", [[Event("fx_out")]]),
        ("a set of Events", {Event("fx_out")}),
    ]
    for label, value in cases:
        s = Stream()
        s.emit(Event("before"))
        before = s.sampleHere()["residue"][0]
        s.register(Gate("fx_feed", lambda e: Event("fx_bad")))
        s.register(Gate("fx_bad", (lambda v: lambda e: v)(value)))
        r = emitCatch(s, Event("fx_feed"))
        noProblems(API.rejectionProblems(r, {"code": "SISO_NOT_EVENT", "eventId": 2, "causeId": 1,
                                             "eventType": "fx_bad"}), label)
        residue = s.sampleHere()["residue"]
        # OWNERSHIP: a sample is the receiver's own copy, so compare values
        assert_(len(residue) == 1 and residue[0].type == before.type and keysOf(residue[0].data) == keysOf(before.data),
                label + ": the residue changed")


test("SISO_NOT_EVENT for every return that is not None, an Event or a list", t_not_event_returns)


def t_accepted():
    cases = [
        ("None", None, ""),
        ("an Event", Event("fx_a"), "fx_a"),
        ("an empty list", [], ""),
        ("an empty tuple", (), ""),
        ("a list", [Event("fx_a"), Event("fx_b")], "fx_a,fx_b"),
        ("a tuple", (Event("fx_a"), Event("fx_b")), "fx_a,fx_b"),
    ]
    for label, value, want in cases:
        s = oneGate("fx_g", value)
        s.emit(Event("fx_g"))
        assertEqual(",".join(sorted(e.type for e in s.takeResidue())), want, label)

    def silent(e):
        pass

    s = Stream()
    s.register(Gate("fx_silent", silent))
    s.emit(Event("fx_silent"))
    assertEqual(len(s.takeResidue()), 0, "nothing returned")


test("accepted returns: None, nothing, an Event, an empty list, a list", t_accepted)


def t_order():
    r1 = emitCatch(oneGate("fx_g", [Event("fx_ok"), 42, Event("fx_g")]), Event("fx_g"))
    assertEqual(r1.code, "SISO_NOT_EVENT", "non-Event before the self-loop")
    r2 = emitCatch(oneGate("fx_g", [Event("fx_ok"), Event("fx_g"), 42]), Event("fx_g"))
    assertEqual(r2.code, "SISO_SELF_LOOP", "self-loop before the non-Event")
    r3 = emitCatch(oneGate("fx_g", [Event("fx_g"), None]), Event("fx_g"))
    assertEqual(r3.code, "SISO_SELF_LOOP", "a member's self-loop before a later None member")


test("a list is judged in list order: the first bad member decides", t_order)

# ── ids are per propagation ────────────────────


def t_ids():
    s = Stream()
    s.register(Gate("fx_a", lambda e: Event("fx_b")))
    s.register(Gate("fx_b", raiser(RuntimeError("boom"))))
    first = emitCatch(s, Event("fx_a"))
    s.emit(Event("other"))
    s.emit(Event("other"))
    again = emitCatch(s, Event("fx_a"))
    assertEqual(first.eventId, 2)
    assertEqual(again.eventId, 2, "nothing counted across propagations")
    assert_(not hasattr(s, "eventCount"), "no counter on the stream")


test("ids restart at 1 in each propagation, rejected ones included", t_ids)

# ── a rejected propagation leaves the stream as it was ──


def t_as_before():
    s = Stream()
    s.emit(Event("kept", {"v": 1}))
    before = s.sampleHere()["residue"]
    s.register(Gate("fx_root", lambda e: [Event("fx_leftover"), Event("fx_x")]))
    s.register(Gate("fx_x", lambda e: Event("fx_y")))
    s.register(Gate("fx_y", lambda e: Event("fx_x")))
    batches = []
    s.attach(lambda b: batches.append(b))
    r = emitCatch(s, Event("fx_root"))
    assertEqual(r.code, "SISO_CYCLE")
    after = s.sampleHere()
    assertEqual(len(after["residue"]), 1)
    a0, b0 = after["residue"][0], before[0]
    assert_(a0.type == b0.type and a0.data["v"] == b0.data["v"] and keysOf(a0.data) == keysOf(b0.data),
            "the same residue event")
    assertEqual(after["gateCount"], 3)
    s.emit(Event("next"))
    assertEqual(len(batches), 2, "the observer stays attached")
    assertEqual(batches[1]["records"][0]["eventId"], 1)


test("a rejection leaves residue, gates and observers exactly as before", t_as_before)

# ── the observe door ───────────────────────────


def t_records():
    s = Stream()
    given = []

    def root(e):
        given.append(e.data)
        return [Event("fx_mid"), Event("fx_out", {"k": 2})]

    s.register(Gate("fx_root", root))
    s.register(Gate("fx_mid", lambda e: Event("fx_end")))
    got = []
    s.attach(lambda batch: got.append(batch))
    s.emit(Event("fx_root", {"k": 1}))
    b = got[0]
    noProblems(SEAM.batchProblems(b))
    assertEqual(keysOf(b), "outcome,records,rejection")
    assertEqual(b["rejection"], None, "a settled batch carries no rejection")
    shown = " ".join(str(r["eventId"]) + ":" + str(r["causeId"]) + ":" + r["eventType"] + ":" + str(r["claimed"])
                     for r in b["records"])
    assertEqual(shown, "1:None:fx_root:True 2:1:fx_mid:True 3:1:fx_out:False 4:2:fx_end:False")
    # OWNERSHIP: an observer record is the receiver's own copy, so compare values
    assert_(b["records"][0]["data"]["k"] == given[0]["k"] and keysOf(b["records"][0]["data"]) == keysOf(given[0]),
            "data is the data the gate received")
    assertEqual(b["records"][2]["data"]["k"], 2)


test("observer: records carry ids, causes, types, claims and the data received", t_records)


def t_rejected_batch():
    cases = [
        ("self-loop", lambda e: Event("fx_bad"), "SISO_SELF_LOOP"),
        ("not an Event", lambda e: 7, "SISO_NOT_EVENT"),
        ("threw", raiser(RuntimeError("x")), "SISO_GATE_THREW"),
    ]
    for label, fn, code in cases:
        s = Stream()
        s.emit(Event("kept"))
        s.register(Gate("fx_feed", lambda e: [Event("fx_side"), Event("fx_bad")]))
        s.register(Gate("fx_bad", fn))
        seen = {}

        def obs(batch, seen=seen, s=s):
            seen["b"] = batch
            seen["residue"] = len(s.sampleHere()["residue"])

        s.attach(obs)
        r = emitCatch(s, Event("fx_feed"))
        assertEqual(r.code, code, label)
        b = seen["b"]
        noProblems(SEAM.batchProblems(b), label)
        # THE REJECTION: the observer's own read-only copy
        why = SEAM.rejectionCopyProblem(b["rejection"], r, Event)
        assert_(why is None, label + ": " + str(why))
        assertEqual(len(b["records"]), 3, label + ": records")
        assertEqual(b["records"][2]["eventType"], "fx_bad", label)
        assertEqual(seen["residue"], 1, label + ": nothing committed when the observer ran")


test("observer: a rejected batch ends with the named delivery and runs before the raise", t_rejected_batch)


def t_refused_batch():
    s = Stream()
    got = []
    s.attach(lambda b: got.append(b))
    r = emitCatch(s, {"type": "x", "data": {}})
    assertEqual(got[0]["outcome"], "rejected")
    why = SEAM.rejectionCopyProblem(got[0]["rejection"], r, Event)
    assert_(why is None, "refused entry: " + str(why))
    assertEqual(len(got[0]["records"]), 0)


test("observer: a refused entry's batch carries the rejection and no records", t_refused_batch)


def t_own_records():
    def scribble(b):
        b["records"][0]["eventType"] = "changed"
        b["records"][0]["claimed"] = "x"

    s = Stream()
    seen = []
    s.attach(scribble)
    s.attach(lambda b: seen.append(b))
    s.emit(Event("q"))
    # the order observers are called in carries no meaning, so run both orders
    t = Stream()
    seen2 = []
    t.attach(lambda b: seen2.append(b))
    t.attach(scribble)
    t.emit(Event("q"))
    assertEqual(seen[0]["records"][0]["eventType"], "q")
    assertEqual(seen[0]["records"][0]["claimed"], False)
    assertEqual(seen2[0]["records"][0]["eventType"], "q")


test("observer: one observer's writes to its records reach no other", t_own_records)


def t_raising_observer():
    s = Stream()
    s.register(Gate("fx_t", raiser(RuntimeError("gate failure"))))

    def fails(b):
        raise RuntimeError("observer failure")

    s.attach(fails)
    r = emitCatch(s, Event("fx_t"))
    assertEqual(r.code, "SISO_GATE_THREW")
    assertEqual("gate failure" in str(r.cause), True)


test("observer: a raising observer leaves a rejection exactly as raised", t_raising_observer)


def t_late_observer():
    s = Stream()
    late = []

    def lateObserver(b):
        late.append(b["records"][0]["eventType"])

    added = []

    def adder(b):
        if not added:
            added.append(1)
            s.attach(lateObserver)

    s.attach(adder)
    s.emit(Event("first"))
    s.emit(Event("second"))
    assertEqual(",".join(late), "second")


test("observer: an observer attached by another sees only later propagations", t_late_observer)


def t_misuse():
    s = Stream()
    try:
        s.attach({})
        H.fail("attaching a dict did not raise")
    except TypeError as err:
        assertEqual(str(err), "attach: an observer is a function, got dict", "attaching a dict")

    def f(b):
        pass

    s.attach(f)
    try:
        s.attach(f)
        H.fail("attaching twice did not raise")
    except ValueError as err:
        assertEqual(str(err), "attach: this observer is already attached", "attaching twice")
    s.detach(f)
    try:
        s.detach(f)
        H.fail("detaching twice did not raise")
    except ValueError as err:
        assertEqual(str(err), "detach: this observer is not attached", "detaching twice")


test("observer: attach takes a function; misuse raises a string", t_misuse)

H.finish("stream-doors")
