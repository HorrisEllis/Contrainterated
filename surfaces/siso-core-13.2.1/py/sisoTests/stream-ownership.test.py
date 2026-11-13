# stream-ownership.test.py -- samples and observer records are their
# receiver's own frozen copies (py-core-api OWNERSHIP, py-observer-seam
# RECORD data). Port of lua/sisoTests/stream-ownership.test.lua, case by
# case. Runs the core-api ownership, doors and read-only checks and the
# observer-seam ownership and seam checks on the real core, then pins what
# they leave open, then measures what the copies cost (printed, never
# asserted). The gates below are fixtures for the stream, not project
# gates.
#
# How the Lua cases map (py-core-api SUBSTRATE):
#   - Lua's rawset becomes Python's: object.__setattr__ on an Event's slots
#     and on a FrozenDict's storage slot (replacing its MappingProxyType). A
#     tuple cannot be written at all.
#   - "no proxy is shared" becomes "no container (Event, FrozenDict, tuple)
#     is shared"; the empty tuple, an immutable singleton of the language,
#     is left out of the count, as Lua leaves out sentinels.
#   - Marks and __readonly sentinels vanish: the marks case pins that a
#     tuple stays a tuple, None stays None and a mapping is a FrozenDict
#     through copies, a copy of a copy included.
#   - "a cycle terminates": the stream cannot hold a cycle of plain data;
#     Python's own attempt is a gate that rawsets a cyclic dict into the
#     event it returns: a sample of it is refused (TypeError, "a cycle"),
#     it never loops.
#   - The copy-counting case (Lua swaps package.loaded) loads a private copy
#     of siso/core/stream.py by path (sys.modules untouched) whose copy is
#     counted, or is the identity for the sharing arm of the cost case.
# No case is left out.
# Runnable from any directory: python3 py/sisoTests/stream-ownership.test.py
import sys

sys.dont_write_bytecode = True

import gc  # noqa: E402
import importlib.util  # noqa: E402
import os  # noqa: E402
import time  # noqa: E402
import types  # noqa: E402

HERE = os.path.dirname(os.path.abspath(__file__))
CODE = os.path.normpath(os.path.join(HERE, "..", "code"))
sys.path.insert(0, CODE)

from siso import harness as H  # noqa: E402
from siso.contracts import core_api as API  # noqa: E402
from siso.contracts import observer_seam as SEAM  # noqa: E402
from siso.core import freeze  # noqa: E402
from siso.core.event import Event  # noqa: E402
from siso.core.freeze import FrozenDict  # noqa: E402
from siso.core.gate import Gate  # noqa: E402
from siso.core.stream import Stream  # noqa: E402

core = {"Event": Event, "Gate": Gate, "Stream": Stream}
test, assert_, assertEqual = H.test, H.assert_, H.assertEqual

NO_ITEMS = "does not support item assignment"


def joined(lst):
    return "; ".join(lst)


def noProblems(lst, label=None):
    assert_(len(lst) == 0, ((label + ": ") if label else "") + joined(lst))


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
    """Python's rawset on a FrozenDict: its storage slot replaced, past the refusal."""
    d = dict(fd.items())
    d.update(changes)
    object.__setattr__(fd, "_m", types.MappingProxyType(d))


def containersOf(v):
    """Every container (Event, FrozenDict, non-empty tuple) reachable from v, by id."""
    out = {}
    stack = [v]
    while stack:
        x = stack.pop()
        t = type(x)
        if id(x) in out:
            continue
        if t is Event:
            out[id(x)] = x
            stack.append(x.data)
        elif t is FrozenDict:
            out[id(x)] = x
            stack.extend(x.values())
        elif t is tuple and x:
            out[id(x)] = x
            stack.extend(x)
        elif t is list:
            stack.extend(x)
    return out


def shared(a, b):
    sa = containersOf(a)
    return sum(1 for k in containersOf(b) if k in sa)


# ── the contracts on the real core ─────────────

test("core-api ownership holds on the core", lambda: noProblems(API.checkOwnership(core)))


def t_doors_readonly():
    noProblems(API.checkDoors(core), "doors")
    noProblems(API.checkReadonly(core), "readonly")


test("core-api doors and read-only still hold on the core", t_doors_readonly)


def t_seam():
    noProblems(SEAM.checkOwnership(core), "ownership")
    noProblems(SEAM.checkSeam(core), "seam")


test("observer-seam ownership and seam hold on the core", t_seam)

# ── samples ────────────────────────────────────


def deep():
    return {"v": 1, "a": {"b": {"c": {"d": {"e": {"leaf": 1, "list": [1, 2, 3]}}}}}}


def t_deep():
    s = Stream()
    s.emit(Event("fx_deep", deep()))
    a = s.sampleHere()["residue"][0]
    e = a.data["a"]["b"]["c"]["d"]["e"]
    rawsetItems(e, leaf=9, added=1, list=(1, 9, 3))
    rawsetItems(a.data["a"]["b"], c="gone")
    object.__setattr__(a, "data", {})
    b = s.sampleHere()["residue"][0]
    eb = b.data["a"]["b"]["c"]["d"]["e"]
    assertEqual(eb["leaf"], 1, "deep field")
    assertEqual(eb["list"][1], 2, "deep list member")
    assertEqual(eb.get("added"), None, "deep added key")
    assertEqual(len(eb["list"]), 3)
    t = s.takeResidue()[0]
    assertEqual(t.data["a"]["b"]["c"]["d"]["e"]["leaf"], 1, "the taken residue is untouched")
    assertEqual(shared(b, t), 0, "a sample shares no container with the residue it copies")
    refuses("a deep write into a copy", lambda: setItem(eb["list"], 0, 0))


test("deep nesting: a rawset at any depth of a sample reaches nothing; no proxy is shared", t_deep)


def t_reads():
    s = Stream()
    s.emit(Event("fx_read", {"list": [3, 4, 5], "m": {"k": 1}, "s": "x"}))
    r = s.sampleHere()["residue"][0]
    assert_(Event.isEvent(r), "Event.isEvent recognises a copied event")
    assert_(type(r.data) is FrozenDict and type(r.data["m"]) is FrozenDict, "a copy is frozen")
    assert_(r.data is r.data and r.data["m"] is r.data["m"], "two reads give the same object")
    assertEqual(len(r.data["list"]), 3)
    assertEqual(sum(x for x in r.data["list"]), 12)
    assertEqual(",".join(sorted(r.data)), "list,m,s")
    refuses("a copied event's type", lambda: setattr(r, "type", "z"), "read-only")
    refuses("a copied event's field", lambda: setItem(r.data, "s", "y"))
    refuses("a copied event's new key", lambda: setItem(r.data, "w", 1))


test("copies read like the original: indexing, #, ipairs, pairs; the same field twice is the same value", t_reads)


def t_marks():
    s = Stream()
    s.register(Gate("fx_mark", lambda e: [Event("fx_out", {"list": [1, None], "n": None, "plain": {}}),
                                          Event("fx_bare", None)]))
    s.emit(Event("fx_mark"))
    byType = {e.type: e for e in s.sampleHere()["residue"]}
    d = byType["fx_out"].data
    assert_(type(d["list"]) is tuple, "a list stays a tuple through a copy")
    assert_(type(byType["fx_out"]) is Event, "a copied event is an Event")
    assert_(type(d) is FrozenDict and type(d["plain"]) is FrozenDict, "a copied mapping is a FrozenDict")
    assert_(d["n"] is None and d["list"][1] is None, "None stays None")
    assert_(type(byType["fx_bare"].data) is FrozenDict and len(byType["fx_bare"].data) == 0, "None as data: empty")
    again = freeze.copy(d)
    assert_(type(again["list"]) is tuple and type(again) is FrozenDict and again["n"] is None,
            "a copy of a copy keeps tuples, FrozenDicts and None")
    assertEqual(shared(again, d), 0, "a copy of a copy shares nothing with it")


test("marks and __readonly sentinels go through copies; no metatable stays none", t_marks)


def t_two_samples():
    s = Stream()
    s.emit(Event("fx_x", {"v": 1, "n": [1]}))
    s.emit(Event("fx_y", {"w": {"k": "a"}}))
    a = s.sampleHere()["residue"]
    b = s.sampleHere()["residue"]
    assertEqual(len(a), 2)
    for i in range(2):
        assert_(a[i] is not b[i], "event " + str(i + 1) + " is a separate copy in each sample")
        assertEqual(shared(a[i], b[i]), 0, "event " + str(i + 1) + ": the samples share no container")
    for e in a:
        object.__setattr__(e, "type", "z")
        rawsetItems(e.data, **{k: "changed" for k in e.data})
    assertEqual(",".join(sorted(e.type for e in b)), "fx_x,fx_y", "the other sample's events")
    for e in s.sampleHere()["residue"]:
        if e.type == "fx_x":
            assertEqual(e.data["n"][0], 1)
        else:
            assertEqual(e.data["w"]["k"], "a")


test("two samples are independent", t_two_samples)


def t_sharing_in_sample():
    s = Stream()
    s.register(Gate("fx_fan", lambda e: [Event("fx_l", e.data), Event("fx_r", e.data)]))
    s.emit(Event("fx_fan", {"list": [1]}))
    byType = {e.type: e for e in s.sampleHere()["residue"]}
    assert_(byType["fx_l"].data is byType["fx_r"].data, "data shared in the residue is one copy in one sample")
    held = {e.type: e for e in s.takeResidue()}
    assert_(byType["fx_l"].data is not held["fx_l"].data, "the sample's data is not the stream's")
    assertEqual(shared(byType["fx_l"], held["fx_l"]), 0)

    # Python's own attempt at a cycle in the residue: a gate rawsets a
    # cyclic dict into the event it returns; a sample refuses it, never loops
    cyclic = {"name": "c"}
    cyclic["self"] = cyclic

    def rawCycle(e):
        out = Event("fx_cycle")
        object.__setattr__(out, "data", cyclic)
        return out

    c = Stream()
    c.register(Gate("fx_make", rawCycle))
    c.emit(Event("fx_make"))
    H.assertThrows(lambda: c.sampleHere(), "a cycle", "a sample of a rawset cycle")


test("sharing inside one sample is kept, never with the stream; a cycle terminates", t_sharing_in_sample)


def t_take_reenter():
    # OWNERSHIP: entry copies, so the taker's handle never reaches the
    # residue, taken residue entered again included
    s = Stream()
    s.emit(Event("fx_kept", {"v": 1, "n": [1]}))
    before = s.sampleHere()["residue"][0]
    taken = s.takeResidue()[0]
    assertEqual(len(s.sampleHere()["residue"]), 0, "the stream holds none of what it handed over")
    s.emit(taken)
    rawsetItems(taken.data, v=7, n=(9,))
    again = s.takeResidue()[0]
    assert_(again is not taken, "re-entered residue is the stream's own copy")
    assertEqual(shared(again, taken), 0, "the copy shares no container with the taker's event")
    assertEqual(again.data["v"], 1, "a rawset on the taker's event did not reach the residue")
    assertEqual(again.data["n"][0], 1)
    assertEqual(before.data["v"], 1)


test("take hands over the stream's own events; entering them again takes a fresh copy", t_take_reenter)


def t_entering_own():
    held = Event("fx_held", deep())
    s = Stream()
    s.emit(held)
    object.__setattr__(held, "type", "z")
    rawsetItems(held.data["a"]["b"]["c"]["d"]["e"], leaf=9, list=(9, 2, 3))
    r = s.takeResidue()[0]
    assertEqual(r.type, "fx_held")
    assertEqual(r.data["a"]["b"]["c"]["d"]["e"]["leaf"], 1)
    assertEqual(r.data["a"]["b"]["c"]["d"]["e"]["list"][0], 1)
    assertEqual(shared(r, held), 0, "the residue shares no container with the entering code's event")


test("the entering code's own event never reaches the residue, at any depth", t_entering_own)

# ── observers ──────────────────────────────────


def t_observer_copies():
    s = Stream()
    got = {}

    def root(e):
        got["given"] = e.data
        return Event("fx_next", e.data)

    s.register(Gate("fx_root", root))
    A, B = [], []
    s.attach(lambda b: A.append(b))
    s.attach(lambda b: B.append(b))
    s.emit(Event("fx_root", {"v": 1, "n": [1]}))
    held = s.sampleHere()["residue"][0]
    taken = s.takeResidue()[0]
    given = got["given"]
    a, b = A[0], B[0]
    assert_(taken.data is given, "inside the propagation the data is shared, and the residue holds it")
    assert_(a["records"][0]["data"] is not given, "a record is not the data the gate received")
    assertEqual(shared(a["records"][0]["data"], given), 0, "a record shares no container with the stream's data")
    assertEqual(shared(a["records"][0]["data"], b["records"][0]["data"]), 0, "two observers share no container")
    assert_(a["records"][0]["data"] is a["records"][1]["data"], "data passed on is one copy for one observer")
    assertEqual(a["records"][1]["data"]["n"][0], 1)
    assertEqual(held.data["v"], 1)
    refuses("an observer's record data", lambda: setItem(a["records"][0]["data"], "v", 2))


test("each observer's records carry its own copies; within one observer data passed on is one copy", t_observer_copies)


def t_rejected_records():
    for writerFirst in (True, False):
        s = Stream()
        got = {}

        def root(e):
            got["given"] = e.data
            return Event("fx_mid", e.data)

        def boom(e):
            raise RuntimeError("boom")

        s.register(Gate("fx_root", root))
        s.register(Gate("fx_mid", boom))
        seen = []

        def writer(b):
            for r in b["records"]:
                rawsetItems(r["data"], v=99, n=(9,))

        def reader(b):
            seen.append(b)

        if writerFirst:
            s.attach(writer)
            s.attach(reader)
        else:
            s.attach(reader)
            s.attach(writer)
        try:
            s.emit(Event("fx_root", {"v": 1, "n": [1]}))
            H.fail("the propagation settled")
        except Exception as r:
            assert_(getattr(r, "code", None) == "SISO_GATE_THREW", "the propagation is rejected")
        b = seen[0]
        assertEqual(b["outcome"], "rejected")
        assertEqual(len(b["records"]), 2)
        for i, rec in enumerate(b["records"], 1):
            assertEqual(rec["data"]["v"], 1, "record " + str(i) + ": another observer's rawset did not reach it")
            assertEqual(rec["data"]["n"][0], 1)
            assertEqual(shared(rec["data"], got["given"]), 0, "record " + str(i) + " shares no container with the stream's data")
        assertEqual(got["given"]["v"], 1, "the stream's frozen data is unchanged")


test("a rejected batch's records are copies too", t_rejected_records)


def t_reads_raw_field():
    s = Stream()

    def raw(e):
        d = e.data
        rawsetItems(d, v=2)  # Python's rawset, inside the propagation
        return Event("fx_out", d)

    s.register(Gate("fx_raw", raw))
    got = []
    s.attach(lambda b: got.append(b))
    s.emit(Event("fx_raw", {"v": 1}))
    assertEqual(got[0]["records"][1]["data"]["v"], 2, "a record reads the field as indexing does")
    r = s.sampleHere()["residue"][0]
    held = s.takeResidue()
    assertEqual(r.data["v"], 2, "a sample reads the field as indexing does")
    assert_(r.data is not held[0].data, "in the copy it is part of a fresh FrozenDict")
    refuses("that field in a copy", lambda: setItem(r.data, "v", 3))


test("the copy reads what a reader reads: a raw field a gate wrote past the refusal (R3)", t_reads_raw_field)

# ── copying only when needed ───────────────────


def streamWithCopy(copyFn):
    """A private copy of siso/core/stream.py whose copy is copyFn (sys.modules untouched)."""
    spec = importlib.util.spec_from_file_location("siso_variant_stream", os.path.join(CODE, "siso", "core", "stream.py"))
    mod = importlib.util.module_from_spec(spec)
    spec.loader.exec_module(mod)
    mod.copy = copyFn
    return mod.Stream


def t_copy_counts():
    # entry takes the stream's own copy, one copy per emit
    calls = [0]

    def counted(v, memo=None):
        calls[0] += 1
        return freeze.copy(v, memo)

    Counted = streamWithCopy(counted)

    def chain():
        s = Counted()
        s.register(Gate("fx_a", lambda e: [Event("fx_b", {"k": 1}), Event("fx_c")]))
        s.register(Gate("fx_b", lambda e: Event("fx_d")))
        return s

    s = chain()
    s.emit(Event("fx_a", {"k": 0}))
    t = chain()

    def boom(e):
        raise RuntimeError("boom")

    t.register(Gate("fx_d", boom))
    try:
        t.emit(Event("fx_a"))
    except Exception:
        pass
    assertEqual(calls[0], 2, "no observer attached, settled or rejected: only each entry is copied")
    calls[0] = 0
    try:
        t.emit("not an event")
    except Exception:
        pass
    assertEqual(calls[0], 0, "a refused entry copies nothing")
    u = chain()
    u.attach(lambda b: None)
    u.attach(lambda b: None)
    u.emit(Event("fx_a"))
    assertEqual(calls[0], 1 + 2 * 4, "the entry, then two observers, four records each")
    v = chain()
    v.emit(Event("fx_a"))
    calls[0] = 0
    n = len(v.sampleHere()["residue"])
    assertEqual(n, 2)
    assertEqual(calls[0], n, "a sample copies each residue event once")
    calls[0] = 0
    v.takeResidue()
    assertEqual(calls[0], 0, "a take copies nothing")


test("no observer means no copying past the entry; one copy per record per observer, one per sampled event",
     t_copy_counts)

# ── cost (printed, never asserted) ─────────────


def best(runs, fn):
    low = float("inf")
    for _ in range(runs):
        gc.collect()
        t0 = time.process_time()
        fn()
        low = min(low, time.process_time() - t0)
    return low


def t_cost():
    Sharing = streamWithCopy(lambda v, memo=None: v)
    lines = []

    def row(label, a, b):
        ratio = "  (x%.2f)" % (b / a) if a >= 0.0001 else ""
        lines.append("  %-52s sharing %.4fs  copies %.4fs%s" % (label, a, b, ratio))

    def filled(S):
        s = S()
        for i in range(1, 2001):
            s.emit(Event("fx_r" + str(i), {"k" + str(j): i * 10 + j for j in range(1, 11)}))
        return s

    sp, sc = filled(Sharing), filled(Stream)
    row("sampleHere, 2,000 events x 10-entry data", best(3, sp.sampleHere), best(3, sc.sampleHere))
    N = 20000

    def chain(S):
        def run():
            s = S()
            for i in range(N):
                nxt = "step_" + str(i + 1)
                s.register(Gate("step_" + str(i), (lambda e, nxt=nxt: Event(nxt, {})) if i < N - 1 else (lambda e: None)))
            s.attach(lambda b: None)
            s.emit(Event("step_0", {}))
        return run

    row("20,000-gate chain, one observer (register + emit)", best(3, chain(Sharing)), best(3, chain(Stream)))
    print("ownership cost (process_time, best of 3):")
    for line in lines:
        print(line)


test("cost of the copies (printed)", t_cost)

H.finish("stream-ownership")
