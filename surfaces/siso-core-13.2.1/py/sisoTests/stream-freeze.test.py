# stream-freeze.test.py -- read-only events (py-core-api READ-ONLY). Port of
# lua/sisoTests/stream-freeze.test.lua, case by case. Runs the core-api
# read-only and doors checks and the observer-seam check on the real core,
# then pins what they leave open, then measures what freezing costs
# (printed, never asserted). The gates below are fixtures for the stream,
# not project gates.
#
# How the Lua cases map (py-core-api SUBSTRATE):
#   - Writes into data are refused by the language (FrozenDict and tuple
#     support no item assignment: TypeError); an attribute write on an
#     event or its data raises the contract's "read-only:" AttributeError.
#   - "pairs hands out the frozen table as its state": Python's own
#     counterpart is iteration: iterating a FrozenDict hands out a builtin
#     key iterator, which holds no attribute and writes nothing.
#   - Kit-style marks vanish (a list is a tuple, an object a FrozenDict):
#     the marks case pins that every door reads lists back as tuples and
#     dicts as the core's FrozenDict, whose class is locked.
#   - "readonly:" / __readonly sentinels vanish (None is null): the
#     sentinel case pins None through a gate, a nested value, a list and as
#     the data itself (an empty FrozenDict, where Lua keeps the sentinel).
#   - freeze(v) of a frozen value giving it back becomes Event / FrozenDict
#     keeping frozen data by identity.
#   - "a frozen table with a named key" becomes a FrozenDict returned by a
#     gate (its event's data): SISO_NOT_EVENT.
#   - "a cycle terminates": Python refuses a cycle at Event (plain data is
#     acyclic), so the stream never sees one.
#   - R3's rawset is object.__setattr__ (pinned, as in Lua).
#   - The baseline arm (Lua swaps package.loaded) loads a second, private
#     copy of siso/core/stream.py by path (sys.modules untouched) whose
#     copy is the identity and whose Event is a plain mutable class.
# No case is left out.
# Runnable from any directory: python3 py/sisoTests/stream-freeze.test.py
import sys

sys.dont_write_bytecode = True

import gc  # noqa: E402
import importlib.util  # noqa: E402
import os  # noqa: E402
import time  # noqa: E402

HERE = os.path.dirname(os.path.abspath(__file__))
CODE = os.path.normpath(os.path.join(HERE, "..", "code"))
sys.path.insert(0, CODE)

from siso import harness as H  # noqa: E402
from siso.contracts import core_api as API  # noqa: E402
from siso.contracts import observer_seam as SEAM  # noqa: E402
from siso.core.event import Event  # noqa: E402
from siso.core.freeze import FrozenDict  # noqa: E402
from siso.core.gate import Gate  # noqa: E402
from siso.core.stream import Stream  # noqa: E402

core = {"Event": Event, "Gate": Gate, "Stream": Stream}
test, assert_, assertEqual = H.test, H.assert_, H.assertEqual

READ_ONLY = "read-only"
NO_ITEMS = "does not support item assignment"


def joined(lst):
    return "; ".join(lst)


def noProblems(lst, label=None):
    assert_(len(lst) == 0, ((label + ": ") if label else "") + joined(lst))


def refuses(label, fn, needle=READ_ONLY):
    try:
        fn()
    except Exception as err:
        assert_(needle in str(err), label + ": raised " + type(err).__name__ + ": " + str(err))
        return
    H.fail(label + " was accepted")


def setItem(c, k, v):
    c[k] = v


def attempt(fn):
    """True when fn ran without raising (the write was accepted)."""
    try:
        fn()
        return True
    except Exception:
        return False


def isFrozen(v):
    return type(v) in (Event, FrozenDict, tuple)


# ── the contracts on the real core ─────────────

test("core-api read-only holds on the core", lambda: noProblems(API.checkReadonly(core)))
test("core-api doors still hold on the core", lambda: noProblems(API.checkDoors(core)))
test("observer-seam still holds on the core", lambda: noProblems(SEAM.checkSeam(core)))

# ── what a gate receives ───────────────────────


def t_gate_cannot_write():
    s = Stream()
    seen = {}

    def probe(e):
        seen["type"] = attempt(lambda: setattr(e, "type", "z"))
        seen["data"] = attempt(lambda: setattr(e, "data", {}))
        seen["field"] = attempt(lambda: setItem(e.data, "v", 2))
        seen["new"] = attempt(lambda: setItem(e.data, "added", 1))
        seen["nested"] = attempt(lambda: setItem(e.data["list"], 0, 0))
        seen["deep"] = attempt(lambda: setItem(e.data["m"]["inner"], "x", 0))
        seen["after"] = (e.type + ":" + str(e.data["v"]) + ":" + str(e.data["list"][0]) + ":" +
                         str(e.data["m"]["inner"]["x"]))
        seen["added"] = e.data.get("added")

    s.register(Gate("fx_probe", probe))
    s.emit(Event("fx_probe", {"v": 1, "list": [1, 2], "m": {"inner": {"x": 5}}}))
    for k in ("type", "data", "field", "new", "nested", "deep"):
        assertEqual(seen[k], False, "write to " + k + " refused")
    assertEqual(seen["after"], "fx_probe:1:1:5", "the input reads back unchanged")
    assertEqual(seen["added"], None)

    def writer(e):
        e.data["v"] = 2

    t = Stream()
    t.register(Gate("fx_writer", writer))
    try:
        t.emit(Event("fx_writer", {"v": 1}))
        H.fail("a writing gate settled")
    except Exception as r:
        noProblems(API.rejectionProblems(r, {"code": "SISO_GATE_THREW", "eventId": 1, "eventType": "fx_writer"}))
        assert_(NO_ITEMS in str(r.cause), "the cause is the refusal: " + str(r.cause))


test("a gate cannot write its input, at any depth; the attempt rejects as a throw", t_gate_cannot_write)


def t_reads():
    s = Stream()
    got = {}

    def read(e):
        d = e.data
        it = iter(d)
        got.update(
            len=len(d["list"]), second=d["list"][1], sum=sum(x for x in d["list"]),
            keys=",".join(sorted(d)), items=sorted(k for k, _ in d.items()),
            same=d["m"] is d["m"] and e.data is e.data, missing=d.get("nope"), contains="m" in d,
            iterWrites=hasattr(it, "__dict__") or type(it).__module__ != "builtins",
        )

    s.register(Gate("fx_read", read))
    s.emit(Event("fx_read", {"list": [3, 4, 5], "m": {"k": 1}, "s": "x"}))
    assertEqual(got["len"], 3)
    assertEqual(got["second"], 4)
    assertEqual(got["sum"], 12)
    assertEqual(got["keys"], "list,m,s")
    assertEqual(got["items"], ["list", "m", "s"])
    assert_(got["same"], "two reads of one field are the same object")
    assertEqual(got["missing"], None)
    assertEqual(got["contains"], True)
    assert_(not got["iterWrites"], "iteration hands out a builtin key iterator, nothing writable")


test("reads: indexing, #, ipairs and pairs; the same field twice is the same value", t_reads)

# ── what leaves through the doors ──────────────


def t_sample_take():
    s = Stream()
    s.register(Gate("fx_a", lambda e: Event("fx_out", {"v": e.data["v"], "list": [1]})))
    s.emit(Event("fx_a", {"v": 1}))
    r = s.sampleHere()["residue"][0]
    refuses("a sampled event's type", lambda: setattr(r, "type", "z"))
    refuses("a sampled event's data", lambda: setattr(r, "data", {}))
    refuses("a sampled event's field", lambda: setItem(r.data, "v", 9), NO_ITEMS)
    refuses("a sampled event's nested list", lambda: setItem(r.data["list"], 0, 9), NO_ITEMS)
    assertEqual(s.sampleHere()["residue"][0].data["v"], 1)
    t = s.takeResidue()[0]
    # OWNERSHIP: a sample is the receiver's own copy, so compare values
    assert_(t.type == r.type and t.data["v"] == r.data["v"] and t.data["list"][0] == r.data["list"][0]
            and len(t.data["list"]) == len(r.data["list"]), "the take hands out the same frozen event the sample showed")
    refuses("a taken event's field", lambda: setItem(t.data, "v", 5), NO_ITEMS)
    refuses("a taken event's new key", lambda: setItem(t.data, "w", 5), NO_ITEMS)
    assertEqual(t.data["v"], 1)


test("a sample's and a take's events refuse writes; the stream is unchanged", t_sample_take)


def t_records_refuse():
    s = Stream()
    s.register(Gate("fx_root", lambda e: Event("fx_mid", {"k": [2]})))
    s.register(Gate("fx_mid", lambda e: Event("fx_mid")))
    batches = []
    s.attach(lambda b: batches.append(b))
    try:
        s.emit(Event("fx_root", {"k": [1]}))
    except Exception:
        pass
    s.emit(Event("fx_other", {"k": [3]}))
    assertEqual(len(batches), 2)
    assertEqual(batches[0]["outcome"], "rejected")
    assertEqual(batches[1]["outcome"], "settled")
    for bi, b in enumerate(batches, 1):
        for ri, rec in enumerate(b["records"], 1):
            where = "batch " + str(bi) + " record " + str(ri)
            refuses(where + " data", lambda: setItem(rec["data"], "k", 0), NO_ITEMS)
            if "k" in rec["data"]:
                refuses(where + " nested data", lambda: setItem(rec["data"]["k"], 0, 0), NO_ITEMS)
            refuses(where + " data attribute", lambda: setattr(rec["data"], "k", 0))
    # the batch and record dicts are the observer's own and stay writable
    batches[1]["records"][0]["eventType"] = "relabelled"
    assertEqual(batches[1]["records"][0]["eventType"], "relabelled")


test("an observer's records' data refuse writes, settled and rejected", t_records_refuse)

# ── marks and sentinels ────────────────────────


def t_marks():
    s = Stream()
    got = {}

    def mark(e):
        got["inGate"] = type(e.data["list"])
        return Event("fx_out", {"list": [1, 2], "plain": {}})

    shown = []
    s.attach(lambda b: shown.append(b))
    s.register(Gate("fx_mark", mark))
    s.emit(Event("fx_mark", {"list": []}))
    assert_(got["inGate"] is tuple, "a gate reads its input's list as a tuple")
    r = s.sampleHere()["residue"][0]
    assert_(type(r.data["list"]) is tuple, "an output's list reads back as a tuple in a sample")
    assert_(type(shown[0]["records"][1]["data"]["list"]) is tuple, "and in an observer's record")
    assert_(Event.isEvent(r), "Event.isEvent recognises a frozen event")
    assert_(type(r) is type(Event("x")), "a frozen event is of the event class")
    assert_(type(r.data["plain"]) is FrozenDict and type(r.data) is FrozenDict, "every mapping is the core's FrozenDict")
    H.assertThrows(lambda: setattr(type(r.data), "mark", 1), "locked", "the FrozenDict class")


test("kit-style marks survive: a frozen table answers its original's metatable", t_marks)


def t_none_sentinel():
    s = Stream()
    got = {}

    def null(e):
        got["inGate"] = e.data["n"]
        return [Event("fx_out", {"n": None, "list": [None]}), Event("fx_bare", None)]

    s.register(Gate("fx_null", null))
    s.emit(Event("fx_null", {"n": None}))
    assert_(got["inGate"] is None, "a gate reads None itself")
    byType = {e.type: e for e in s.takeResidue()}
    assert_(byType["fx_out"].data["n"] is None, "a nested None is None")
    assert_(byType["fx_out"].data["list"][0] is None, "None in a list is None")
    bare = byType["fx_bare"].data
    assert_(type(bare) is FrozenDict and len(bare) == 0, "None as data gives an empty FrozenDict")


test("a __readonly sentinel keeps its identity, nested or as the data itself", t_none_sentinel)

# ── never wrapped twice ────────────────────────


def t_not_double():
    # residue taken and entered again: entry takes the stream's own copy,
    # one frozen layer, not a wrapper of it
    s = Stream()
    s.emit(Event("fx_kept", {"v": 1}))
    taken = s.takeResidue()[0]
    assert_(isFrozen(taken), "taken residue is frozen")
    s.emit(taken)
    again = s.takeResidue()[0]
    assert_(again is not taken, "re-entered residue is the stream's own copy")
    assert_(isFrozen(again) and Event.isEvent(again), "the copy is a frozen event")
    assertEqual(again.type + ":" + str(again.data["v"]), "fx_kept:1")
    assert_(FrozenDict(again.data) is again.data and Event("x", again.data).data is again.data,
            "the copy is frozen once: freezing it again gives it back")

    # data passed on by a gate, and an event carried in data and returned
    t = Stream()
    got = {}

    def passOn(e):
        got["given"] = e.data
        return Event("fx_next", e.data)

    def unwrap(e):
        got["carried"] = e.data["inner"]
        return e.data["inner"]

    t.register(Gate("fx_pass", passOn))
    t.register(Gate("fx_unwrap", unwrap))
    t.emit(Event("fx_pass", {"v": 1, "list": [1]}))
    out = t.takeResidue()[0]
    assert_(out.data is got["given"], "data passed on is the same frozen FrozenDict")
    t.emit(Event("fx_unwrap", {"inner": Event("fx_inner", {"w": 2})}))
    inner = t.takeResidue()[0]
    assert_(inner is got["carried"], "an event returned from data is the same frozen event")
    assertEqual(inner.data["w"], 2)
    assert_(Event("y", {"e": inner}).data["e"] is inner, "an event carried in new data is that event")


test("an event passed on unchanged is not double-wrapped", t_not_double)

# ── what freezing pins beyond the checks ───────


def t_frozen_list_returned():
    s = Stream()
    s.register(Gate("fx_fan", lambda e: e.data["out"]))
    s.emit(Event("fx_fan", {"out": [Event("fx_x"), Event("fx_y")]}))
    assertEqual(",".join(sorted(e.type for e in s.takeResidue())), "fx_x,fx_y", "both members carried, nothing dropped")

    def emitCatch(e):
        try:
            s.emit(e)
        except Exception as err:
            return err
        return None

    r = emitCatch(Event("fx_fan", {"out": [Event("fx_x"), {"type": "fake"}]}))
    assert_(r is not None, "a frozen list with a non-Event member settled")
    noProblems(API.rejectionProblems(r, {"code": "SISO_NOT_EVENT", "eventId": 1, "eventType": "fx_fan"}))
    r = emitCatch(Event("fx_fan", {"out": [Event("fx_fan")]}))
    assert_(r is not None, "a frozen list with the gate's own type settled")
    assertEqual(r.code, "SISO_SELF_LOOP")
    r = emitCatch(Event("fx_fan", {"out": {"named": Event("fx_x")}}))
    assert_(r is not None, "a FrozenDict returned settled")
    assertEqual(r.code, "SISO_NOT_EVENT")


test("a frozen list a gate returns is judged as a list: carried, or rejected", t_frozen_list_returned)


def t_copies_original():
    s = Stream()
    original = {"v": 1, "list": [1]}
    entered = Event("fx_kept", original)
    refuses("writing a built event's type", lambda: setattr(entered, "type", "changed"))
    s.emit(entered)
    original["v"] = 2
    original["list"][0] = 9
    r = s.sampleHere()["residue"][0]
    assertEqual(r.type, "fx_kept")
    assertEqual(r.data["v"], 1)
    assertEqual(r.data["list"][0], 1)
    assertEqual(",".join(sorted(original)), "list,v", "the original carries nothing new")
    assert_(type(original) is dict, "the caller's dict is not frozen")
    assert_(isFrozen(entered), "the event is frozen from construction")


test("freezing copies: the emitter's original stays its own and is never annotated", t_copies_original)


def t_shared_cycle():
    shared = [1]
    s = Stream()
    s.emit(Event("fx_shared", {"a": shared, "b": shared}))
    byType = {e.type: e for e in s.takeResidue()}
    assert_(byType["fx_shared"].data["a"] is byType["fx_shared"].data["b"], "a shared list freezes once")
    cyclic = {"name": "c"}
    cyclic["self"] = cyclic
    H.assertThrows(lambda: Event("fx_cycle", cyclic), "a cycle", "a cycle is refused at Event")


test("a table reached twice is one frozen table; a cycle terminates (not plain data)", t_shared_cycle)


def t_rawset():
    s = Stream()
    s.emit(Event("fx_kept", {"v": 1}))
    r = s.sampleHere()["residue"][0]
    object.__setattr__(r, "data", {"v": 2})
    assertEqual(r.data["v"], 2, "Python's rawset bypasses the refusal (substrate concession, Lua's R3)")


test("R3 concession: rawset is not refused (pinned so a change to it is seen)", t_rawset)

# ── cost (printed, never asserted) ─────────────


class PlainEvent:
    """The baseline's Event: a plain mutable object holding what it is given."""

    __slots__ = ("type", "data")

    def __init__(self, type, data=None):  # noqa: A002
        self.type = type
        self.data = {} if data is None else data

    @staticmethod
    def isEvent(v):
        return builtinType(v) is PlainEvent


builtinType = type


def withoutFreeze():
    """A private copy of siso/core/stream.py (sys.modules untouched) whose
    copy is the identity and whose Event is PlainEvent."""
    spec = importlib.util.spec_from_file_location("siso_baseline_stream", os.path.join(CODE, "siso", "core", "stream.py"))
    mod = importlib.util.module_from_spec(spec)
    spec.loader.exec_module(mod)
    mod.copy = lambda v, memo=None: v
    mod.Event = PlainEvent
    mod.fieldsOf = lambda e: (e.type, e.data)
    return PlainEvent, mod.Stream


def best(runs, fn):
    low = float("inf")
    for _ in range(runs):
        gc.collect()
        t0 = time.process_time()
        fn()
        dt = time.process_time() - t0
        low = min(low, dt)
    return low


def t_baseline():
    PE, PS = withoutFreeze()
    e = PE("fx_plain", {"v": 1})
    assert_(not isFrozen(e) and not isFrozen(e.data), "the baseline Event does not freeze")
    e.data["v"] = 2
    assertEqual(e.data["v"], 2, "the baseline event is writable")
    assert_(PE.isEvent(e), "the baseline Event still recognises its events")
    s = PS()
    s.emit(e)
    assert_(s.takeResidue()[0] is e, "the baseline stream does not copy or freeze")
    assert_(isFrozen(Event("fx_real")), "the real Event still freezes afterwards")
    assert_(sys.modules["siso.core.stream"].Stream is Stream, "the real stream module is untouched")


test("the baseline arm freezes nothing, so the comparison measures freezing", t_baseline)


def t_cost():
    PE, PS = withoutFreeze()
    plain = (PE, PS)
    real = (Event, Stream)
    lines = []
    N = 20000

    def chain(arm):
        E, S = arm

        def run():
            s = S()
            for i in range(N):
                nxt = "step_" + str(i + 1)
                s.register(Gate("step_" + str(i), (lambda e, nxt=nxt: E(nxt, {})) if i < N - 1 else (lambda e: None)))
            s.emit(E("step_0", {}))
        return run

    W, HOPS = 10000, 10
    wide = {"k" + str(i): i for i in range(1, W + 1)}

    def wideChain(arm, rebuild):
        E, S = arm

        def run():
            s = S()
            for i in range(1, HOPS + 1):
                nxt = "w_" + str(i + 1)

                def fn(e, nxt=nxt):
                    d = e.data
                    if rebuild:
                        d = dict(d.items())
                    return E(nxt, d)
                s.register(Gate("w_" + str(i), fn))
            s.emit(E("w_1", dict(wide)))
        return run

    def row(label, a, b):
        ratio = "  (x%.2f)" % (b / a) if a >= 0.001 else ""
        lines.append("  %-58s without %.4fs  with %.4fs%s" % (label, a, b, ratio))

    row("20,000-gate chain (register + emit)", best(3, chain(plain)), best(3, chain(real)))
    row("10,000-entry data, 10-gate chain, data passed on", best(3, wideChain(plain, False)), best(3, wideChain(real, False)))
    row("10,000-entry data, 10-gate chain, each gate rebuilds data", best(3, wideChain(plain, True)),
        best(3, wideChain(real, True)))
    print("freeze cost (process_time, best of 3):")
    for line in lines:
        print(line)


test("cost of freezing (printed)", t_cost)

H.finish("stream-freeze")
