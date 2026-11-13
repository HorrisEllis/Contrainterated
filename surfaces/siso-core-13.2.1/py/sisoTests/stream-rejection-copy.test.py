# stream-rejection-copy.test.py -- each observer is shown its own read-only
# copy of the rejection (py-observer-seam THE REJECTION), while the
# entering code receives the very object raised, `cause` included. Port of
# lua/sisoTests/stream-rejection-copy.test.lua, case by case. Runs the
# observer-seam checks on the real core, then pins each code, the copies'
# independence, and that a copy is built inside its observer's protected
# call. The gates below are fixtures for the stream, not project gates.
#
# How the Lua cases map (py-observer-seam SUBSTRATE):
#   - The copy is a FrozenDict: assigning to it is refused by the language
#     (no item assignment, TypeError); Lua's rawset becomes
#     object.__setattr__ on its storage slot.
#   - Lua's thrown table { "the gate's own", nested = {...}, message = ... }
#     becomes an exception object with those as its own attributes; its
#     copy is a FrozenDict of them (vars), lists as tuples.
#   - "a non-table cause is carried as it is": a Python gate can only raise
#     an exception object, so its copy is always a FrozenDict of its own
#     attributes; the leaves in it (a str attribute) are carried as they
#     are, and the exception's text reaches the observer through the
#     copy's "message".
#   - Lua's poisoned cause (a __pairs that raises) becomes Python's own: an
#     exception that is also a Mapping whose iteration raises, so its plain
#     copy cannot be built.
# Added for the Python instance (py-observer-seam 13.2.0, independent
# review): the cause is snapshotted once per rejection, with or without
# observers, and each observer gets a fresh copy of that snapshot: the
# gate's code that reading the cause runs runs once for 0, 1 or 3
# observers, every copy is equal and none is another's; a snapshot that
# cannot be taken fails every observer alone; a process-level exit raised
# while it is taken passes through emit identically with or without
# observers.
# No case is left out.
# Runnable from any directory: python3 py/sisoTests/stream-rejection-copy.test.py
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

NO_ITEMS = "does not support item assignment"


def noProblems(lst, label=None):
    assert_(len(lst) == 0, ((label + ": ") if label else "") + "; ".join(lst))


def refuses(label, fn, needle=NO_ITEMS):
    try:
        fn()
    except Exception as err:
        assert_(needle in str(err), label + ": raised " + type(err).__name__ + ": " + str(err))
        return
    H.fail(label + " was accepted")


def setItem(c, k, v):
    c[k] = v


def rawsetItems(fd, **changes):
    d = dict(fd.items())
    d.update(changes)
    object.__setattr__(fd, "_m", types.MappingProxyType(d))


def raiser(exc):
    def g(e):
        raise exc
    return g


def watched(s, entry, n):
    """Emit `entry` on `s` with `n` recording observers attached; -> the raised
    rejection (None when it settled) and each observer's batches."""
    seen = []
    for _ in range(n):
        into = []
        seen.append(into)
        s.attach((lambda into: lambda b: into.append(b))(into))
    try:
        s.emit(entry)
        return None, seen
    except Exception as raised:
        return raised, seen


# ── the contract on the real core ──────────────

test("observer-seam: checkSeam holds on the core", lambda: noProblems(SEAM.checkSeam(core)))
test("observer-seam: checkOwnership holds on the core (records and the rejection)",
     lambda: noProblems(SEAM.checkOwnership(core)))

# ── every code: a copy for the observer, the object itself for the host ──


def selfLoop():
    s = Stream()
    s.register(Gate("fx_loop", lambda e: Event("fx_loop")))
    return s, Event("fx_loop")


def cycle():
    s = Stream()
    s.register(Gate("fx_a", lambda e: Event("fx_b")))
    s.register(Gate("fx_b", lambda e: Event("fx_a")))
    return s, Event("fx_a")


def threw():
    s = Stream()
    s.register(Gate("fx_boom", raiser(RuntimeError("boom"))))
    return s, Event("fx_boom")


def notEvent():
    s = Stream()
    s.register(Gate("fx_bad", lambda e: 42))
    return s, Event("fx_bad")


def refusedEntry():
    return Stream(), {"type": "not", "data": {}}


cases = [("SISO_SELF_LOOP", selfLoop), ("SISO_CYCLE", cycle), ("SISO_GATE_THREW", threw),
         ("SISO_NOT_EVENT", notEvent), ("SISO_NOT_EVENT", refusedEntry)]


def t_each_code():
    for i, (code, build) in enumerate(cases, 1):
        where = code + " (case " + str(i) + ")"
        s, entry = build()
        raised, seen = watched(s, entry, 2)
        assert_(raised is not None, where + ": settled")
        noProblems(API.rejectionProblems(raised, {"code": code}), where)
        assertEqual(str(raised), raised.message, where + ": str of the raised rejection")
        assertEqual(len(seen[0]), 1, where + ": observer 1 batches")
        assertEqual(len(seen[1]), 1, where + ": observer 2 batches")
        a, b = seen[0][0]["rejection"], seen[1][0]["rejection"]
        why = SEAM.rejectionCopyProblem(a, raised, Event) or SEAM.rejectionCopyProblem(b, raised, Event)
        assert_(why is None, where + ": " + str(why))
        assert_(a is not b, where + ": the two observers share one copy")
        refuses(where + ": assigning to a copy", lambda: setItem(a, "code", "X"))
        refuses(where + ": an attribute on a copy", lambda: setattr(a, "code", "X"), "read-only")
        assertEqual(a["message"], raised.message, where + ": the copy's message is read by indexing")


test("each code: two observers each get their own read-only copy; the host gets the rejection itself", t_each_code)


class ProbeError(Exception):
    def __init__(self):
        super().__init__("bad")
        self.first = "the gate's own"
        self.nested = [1]
        self.message = "bad"


def t_cause():
    thrown = ProbeError()
    s = Stream()
    s.register(Gate("fx_throw", raiser(thrown)))
    raised, seen = watched(s, Event("fx_throw"), 2)
    assert_(raised.cause is thrown, "the host receives the very object raised as cause")
    a, b = seen[0][0]["rejection"], seen[1][0]["rejection"]
    assert_(a["cause"] is not thrown and b["cause"] is not thrown, "a copy's cause is not the raised object")
    assert_(a["cause"] is not b["cause"], "two copies share one cause")
    assert_(a["cause"]["nested"] is not thrown.nested, "a copy's nested cause is its own")
    assertEqual(a["cause"]["first"], "the gate's own")
    assertEqual(a["cause"]["nested"][0], 1)
    refuses("assigning into a copy's cause", lambda: setItem(a["cause"], "nested", (9,)))
    refuses("assigning into a copy's nested cause", lambda: setItem(a["cause"]["nested"], 0, 9))
    rawsetItems(a, code="CHANGED")
    rawsetItems(a["cause"], first="CHANGED")
    assertEqual(raised.code, "SISO_GATE_THREW", "a rawset on a copy reached the raised rejection")
    assertEqual(thrown.first, "the gate's own", "a rawset on a copy reached what the gate raised")
    assertEqual(b["code"], "SISO_GATE_THREW", "a rawset on one copy reached another")
    assertEqual(b["cause"]["first"], "the gate's own", "a rawset on one copy's cause reached another")


test("SISO_GATE_THREW: the host's cause is exactly what the gate raised; each copy's cause is its own", t_cause)


def t_leaf_cause():
    exc = RuntimeError("plain string")
    exc.code = "plain string"
    s = Stream()
    s.register(Gate("fx_str", raiser(exc)))
    raised, seen = watched(s, Event("fx_str"), 1)
    assert_(raised.cause is exc, "the host's cause is the exception itself")
    shown = seen[0][0]["rejection"]
    assertEqual(shown["cause"]["code"], "plain string", "a leaf in the cause is carried as it is")
    assertEqual(len(shown["cause"]), 1, "only the cause's own attributes (args is not carried)")
    assert_("plain string" in shown["message"], "the exception's text reaches the observer in the message")


test("a non-table cause is carried as it is", t_leaf_cause)


def t_settled_and_bare():
    s = Stream()
    raised, seen = watched(s, Event("fx_free"), 1)
    assertEqual(raised, None)
    assertEqual(seen[0][0]["outcome"], "settled")
    assertEqual(seen[0][0]["rejection"], None)
    t = Stream()
    t.register(Gate("fx_boom", raiser(RuntimeError("boom"))))
    try:
        t.emit(Event("fx_boom"))
        H.fail("settled")
    except Exception as r:
        noProblems(API.rejectionProblems(r, {"code": "SISO_GATE_THREW", "eventId": 1}))


test("a settled batch carries no rejection; with no observer the rejection is raised all the same", t_settled_and_bare)

# ── the copy is built inside the observer's protected call ──


class Poisoned(Exception, collections.abc.Mapping):
    """An exception that is also a Mapping whose reads raise: its plain copy
    (its items, read through iteration) cannot be built."""

    def __getitem__(self, k):
        raise RuntimeError("poisoned read")

    def __iter__(self):
        raise RuntimeError("poisoned iteration")

    def __len__(self):
        raise RuntimeError("poisoned len")


def t_cannot_build():
    poisoned = Poisoned("poisoned")

    def run(n):
        s = Stream()
        s.register(Gate("fx_poison", raiser(poisoned)))
        called = []
        for _ in range(n):
            s.attach(lambda b: called.append(1))
        try:
            s.emit(Event("fx_poison"))
            r = None
        except Exception as err:
            r = err
        calledThen = len(called)
        s.emit(Event("fx_after"))
        return r, calledThen, len(s.takeResidue())

    r0, _, _ = run(0)
    r2, called, left = run(2)
    assert_(r0 is not None and r2 is not None, "the poisoned gate settled")
    assertEqual(r2.code, r0.code)
    assertEqual(r2.code, "SISO_GATE_THREW")
    assert_(r2.cause is poisoned, "the host still receives the very object raised")
    assertEqual(called, 0, "an observer whose batch could not be built is not called")
    assertEqual(left, 1, "the stream is usable afterwards and committed nothing from the rejection")


test("a copy that cannot be built fails that observer alone: emit raises exactly as without observers", t_cannot_build)

# ── the cause is snapshotted once per rejection (Python, 13.2.0) ──


def t_snapshot_once():
    reads = []

    class Counting(Exception):
        @property
        def __dict__(self):
            reads.append(1)
            return {"n": len(reads), "nested": [1, {"k": "v"}]}

    runs = {}
    for count in (0, 1, 3):
        del reads[:]
        s = Stream()
        s.register(Gate("fx_c", raiser(Counting("counted"))))
        raised, seen = watched(s, Event("fx_c"), count)
        assertEqual(raised.code, "SISO_GATE_THREW", str(count) + " observers")
        runs[count] = len(reads)
        copies = [b[0]["rejection"] for b in seen]
        for c in copies:
            assert_(type(c["cause"]) is FrozenDict and type(c["cause"]["nested"]) is tuple,
                    str(count) + " observers: the copy is frozen plain data")
            refuses(str(count) + " observers: assigning into a copy's cause", lambda: setItem(c["cause"], "n", 2))
            assertEqual(c["cause"]["n"], 1, str(count) + " observers: the snapshot read the cause once")
            assert_(c["cause"] == copies[0]["cause"], str(count) + " observers: every copy is equal")
        for i in range(len(copies)):
            for j in range(i + 1, len(copies)):
                assert_(copies[i]["cause"] is not copies[j]["cause"]
                        and copies[i]["cause"]["nested"] is not copies[j]["cause"]["nested"],
                        "two observers share a snapshot's object")
    assertEqual(runs, {0: 1, 1: 1, 3: 1}, "the cause is read once per rejection, whatever the observers")


test("the cause is snapshotted once per rejection: the gate's code runs once for 0, 1 or 3 observers", t_snapshot_once)


def t_snapshot_fails():
    def run(n):
        s = Stream()
        s.register(Gate("fx_poison", raiser(Poisoned("poisoned"))))
        called = []
        for _ in range(n):
            s.attach(lambda b: called.append(1))
        try:
            s.emit(Event("fx_poison"))
            r = None
        except Exception as err:
            r = err
        return r, len(called)

    for n in (1, 3):
        r, called = run(n)
        assertEqual(getattr(r, "code", None), "SISO_GATE_THREW", str(n) + " observers")
        assertEqual(called, 0, str(n) + " observers: every observer's batch fails alone when the snapshot cannot be taken")
    assertEqual(str(run(3)[0]), str(run(0)[0]), "the host's rejection is the same with or without observers")


test("a snapshot that cannot be taken fails every observer alone; the host's rejection is unchanged", t_snapshot_fails)


def t_snapshot_exit():
    probe = SystemExit("snapshot probe")

    class Exits(Exception):
        @property
        def __dict__(self):
            raise probe

    for n in (0, 2):
        s = Stream()
        s.emit(Event("kept"))
        s.register(Gate("fx_exit", raiser(Exits("exits"))))
        called = []
        for _ in range(n):
            s.attach(lambda b: called.append(1))
        got = None
        try:
            s.emit(Event("fx_exit"))
        except BaseException as other:  # the probe's own exit, caught here
            got = other
        assert_(got is probe, str(n) + " observers: the exit did not pass through emit unchanged (" + H.show(got) + ")")
        assertEqual(len(called), 0, str(n) + " observers: no observer runs after a process-level exit")
        assertEqual(len(s.sampleHere()["residue"]), 1, str(n) + " observers: nothing committed")
        s.emit(Event("fine"))
        assertEqual(len(s.takeResidue()), 2, str(n) + " observers: the stream stays usable")


test("a process-level exit raised while the snapshot is taken passes through, with or without observers",
     t_snapshot_exit)

H.finish("stream-rejection-copy")
