# core.test.py -- port of lua/sisoTests/core.test.lua (itself the port of
# core 11.1 legacy/js/tests/core.test.js), case by case, same names, same
# expectations. The gates below are fixtures for the stream, not project
# gates.
#
# How the Lua cases map (py-core-api SUBSTRATE):
#   - Lua's raised tables become Python exceptions; JS `new Error(m, name)`
#     becomes the builtin of that name (TypeError("bad input")).
#   - select('#', ...) becomes *args / **kwargs counts.
#   - "Stream: register and lookup" and "different signatures coexist" read
#     s.gates in Lua (R5). Python has no s.gates (state in closures): the
#     same facts are read through the doors (gateCount, a claimed record),
#     and the attempt to read s.gates is Python's own (AttributeError).
#   - "Event: carries only type and data": Lua drops a third argument;
#     Python refuses it (arity, TypeError), and an event has exactly the
#     slots type and data.
#   - "a gate that throws a non-Error": Lua raises a string. Python cannot
#     raise a non-exception: `raise "plain string"` is Python's own attempt,
#     and it raises TypeError inside the gate, which is SISO_GATE_THREW with
#     that TypeError as cause.
#   - "constructor accepts log": Python's own attempt to pass options is
#     refused by Stream's arity (TypeError); nothing is read from them.
#   - s.log / s.streamId / s.parentStreamId are AttributeError, not nil.
# No case is left out.
# Runnable from any directory: python3 py/sisoTests/core.test.py
import sys

sys.dont_write_bytecode = True

import os  # noqa: E402

HERE = os.path.dirname(os.path.abspath(__file__))
sys.path.insert(0, os.path.normpath(os.path.join(HERE, "..", "code")))

from siso import harness as H  # noqa: E402
from siso.core.event import Event  # noqa: E402
from siso.core.gate import Gate  # noqa: E402
from siso.core.stream import Stream  # noqa: E402

test, assert_, assertEqual, assertThrows = H.test, H.assert_, H.assertEqual, H.assertThrows


def fieldsOf(o):
    """The fields stored on an object (its set slots and __dict__ keys), sorted and joined."""
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


def emitCatch(s, e):
    try:
        s.emit(e)
    except Exception as err:
        return err
    return None


def events(types_):
    return [Event(t, {}) for t in types_]


def recorder(into):
    return lambda b: into.append(b)


# ── Event ──────────────────────────────────────

def t_event_type_data():
    e = Event("foo", {"x": 1})
    assertEqual(e.type, "foo")
    assertEqual(e.data["x"], 1)


test("Event: type and data", t_event_type_data)


def t_event_default():
    e = Event("bar")
    assertEqual(e.type, "bar")
    assert_(hasattr(e.data, "keys"))
    assertEqual(len(e.data), 0)


test("Event: data defaults to empty object", t_event_default)

# ── Gate ───────────────────────────────────────

test("Gate: signature stored", lambda: assertEqual(Gate("test_sig").signature, "test_sig"))
test("Gate: transform returns undefined by default",
     lambda: assertEqual(Gate("noop").transform(Event("noop")), None))

# ── Stream: registration ───────────────────────


def t_register_lookup():
    s = Stream()
    s.register(Gate("x"))
    assertEqual(s.sampleHere()["gateCount"], 1)
    got = []
    s.attach(recorder(got))
    s.emit(Event("x"))
    assertEqual(got[0]["records"][0]["claimed"], True, "the gate is found for its type")
    assertThrows(lambda: s.gates, "AttributeError", "the gate table is not an attribute (R5)")


test("Stream: register and lookup", t_register_lookup)


def t_collision():
    s = Stream()
    s.register(Gate("x"))
    assertThrows(lambda: s.register(Gate("x")), "Signature collision")


test("Stream: signature collision throws", t_collision)


def t_coexist():
    s = Stream()
    s.register(Gate("a"))
    s.register(Gate("b"))
    assertEqual(s.sampleHere()["gateCount"], 2)


test("Stream: different signatures coexist", t_coexist)

# ── Stream: emit and residue ───────────────────


def t_unclaimed():
    s = Stream()
    s.emit(Event("unknown", {"v": 42}))
    sample = s.sampleHere()
    assertEqual(len(sample["residue"]), 1)
    assertEqual(sample["residue"][0].type, "unknown")
    assertEqual(sample["residue"][0].data["v"], 42)


test("Stream: unclaimed event goes to residue", t_unclaimed)


def t_claimed():
    s = Stream()
    s.register(Gate("sink", lambda e: None))
    s.emit(Event("sink", {}))
    assertEqual(len(s.sampleHere()["residue"]), 0)


test("Stream: claimed event does not go to residue", t_claimed)


def t_event_only():
    args = {}
    s = Stream()

    def spy(*a, **kw):
        args["argCount"] = len(a)
        args["kwCount"] = len(kw)
        args["type"] = a[0].type
        args["data"] = a[0].data
        return None

    s.register(Gate("spy", spy))
    s.emit(Event("spy", {"val": 99}))
    assertEqual(args["argCount"], 1)
    assertEqual(args["kwCount"], 0)
    assertEqual(args["type"], "spy")
    assertEqual(args["data"]["val"], 99)


test("Stream: gate receives event only", t_event_only)


def t_single():
    s = Stream()
    s.register(Gate("double", lambda e: Event("result", {"value": e.data["x"] * 2})))
    s.emit(Event("double", {"x": 5}))
    sample = s.sampleHere()
    assertEqual(len(sample["residue"]), 1)
    assertEqual(sample["residue"][0].data["value"], 10)


test("Stream: gate returns single event → re-emitted", t_single)


def t_array():
    s = Stream()
    s.register(Gate("split", lambda e: [Event("part_a", {"half": e.data["x"] // 2}),
                                        Event("part_b", {"doubled": e.data["x"] * 2})]))
    s.emit(Event("split", {"x": 10}))
    sample = s.sampleHere()
    assertEqual(len(sample["residue"]), 2)
    assertEqual(sample["residue"][0].data["half"], 5)
    assertEqual(sample["residue"][1].data["doubled"], 20)


test("Stream: gate returns array of events → all re-emitted", t_array)


def t_null():
    s = Stream()
    s.register(Gate("consume", lambda e: None))
    # 12.0: no eventCount; the one delivery is seen in an observer's batch
    batches = []
    s.attach(recorder(batches))
    s.emit(Event("consume", {}))
    assertEqual(len(s.sampleHere()["residue"]), 0)
    assertEqual(len(batches), 1, "one batch")
    assertEqual(len(batches[0]["records"]), 1, "one delivery")
    assertEqual(batches[0]["records"][0]["claimed"], True, "claimed by the gate")


test("Stream: gate returns null → consumed, no follow-up", t_null)


def t_undefined():
    def silent(e):
        pass  # no return

    s = Stream()
    s.register(Gate("silent", silent))
    s.emit(Event("silent", {}))
    assertEqual(len(s.sampleHere()["residue"]), 0)


test("Stream: gate returns undefined → same as null", t_undefined)


def t_causal():
    order = []
    s = Stream()

    def step(sig, out):
        def fn(e):
            order.append(sig)
            return Event(out, {}) if out else None
        return Gate(sig, fn)

    s.register(step("a", "b"))
    s.register(step("b", "c"))
    s.register(step("c", None))
    s.emit(Event("a", {}))
    assertEqual(",".join(order), "a,b,c")


test("Stream: chain processes in causal order", t_causal)


def t_fanout():
    order = []
    s = Stream()
    s.register(Gate("root", lambda e: [Event("left", {}), Event("right", {})]))
    s.register(Gate("left", lambda e: order.append("left")))
    s.register(Gate("right", lambda e: order.append("right")))
    s.emit(Event("root", {}))
    assertEqual(len(order), 2)
    assert_("left" in order, "left branch fired")
    assert_("right" in order, "right branch fired")


test("Stream: fan-out delivers all branches", t_fanout)

# ── Stream: delivery discipline ────────────────


def t_bfs():
    order = []

    def mk(sig, out):
        def fn(e):
            order.append(sig)
            return events(out) if out else None
        return Gate(sig, fn)

    s = Stream()
    s.register(mk("root", ["a", "b"]))
    s.register(mk("a", ["a1"]))
    s.register(mk("b", ["b1"]))
    s.register(mk("a1", None))
    s.register(mk("b1", None))
    s.emit(Event("root", {}))
    # Generation by generation; order is not semantic (Rule 3): this pins
    # the implementation choice, not a contract.
    assertEqual(",".join(order), "root,a,b,a1,b1")


test("Stream: delivery is breadth-first — causal generations", t_bfs)


def t_long_chain():
    # A legal chain of N distinct gates: no type repeats; queue delivery
    # spends no call stack (Python's recursion limit is about 1,000).
    N = 20000

    def step(i):
        nxt = "step_" + str(i + 1)
        return Gate("step_" + str(i), (lambda e: Event(nxt, {})) if i < N - 1 else (lambda e: None))

    s = Stream()
    for i in range(N):
        s.register(step(i))
    # 12.0: no eventCount; an observer's batch shows the N deliveries
    got = []
    s.attach(lambda b: got.append(len(b["records"])))
    s.emit(Event("step_0", {}))
    assertEqual(got, [N])
    assertEqual(len(s.sampleHere()["residue"]), 0)


test("Stream: chain length unbounded by call stack", t_long_chain)


def t_never_annotated():
    s = Stream()
    s.register(Gate("pass", lambda e: Event("out", {"v": 1})))
    rootEvent = Event("pass", {})
    s.emit(rootEvent)
    out = s.sampleHere()["residue"][0]
    assertEqual(fieldsOf(rootEvent), "data,type", "emitted event untouched")
    assertEqual(fieldsOf(out), "data,type", "delivered event untouched")


test("Stream: events are never annotated by delivery", t_never_annotated)

# ── Event: shape ───────────────────────────────


def t_only_type_data():
    # Core 11 removed the retries field. Lua drops a stray third argument;
    # Python's arity check refuses it (nothing is silently dropped).
    assertThrows(lambda: Event("foo", {"x": 1}, {"attempt": 1, "max": 3}), "TypeError")
    assertEqual(fieldsOf(Event("foo", {"x": 1})), "data,type")


test("Event: carries only type and data", t_only_type_data)

# ── Stream: acyclicity (enforced) ──────────────


def t_self_loop():
    s = Stream()
    s.register(Gate("echo", lambda e: Event("echo", {"v": e.data["v"] + 1})))
    err = emitCatch(s, Event("echo", {"v": 0}))
    assert_(err is not None, "expected a throw")
    assertEqual(err.code, "SISO_SELF_LOOP")
    assertEqual(err.eventType, "echo")
    assertEqual(len(s.sampleHere()["residue"]), 0, "nothing committed")


test("Stream: self-loop throws SISO_SELF_LOOP", t_self_loop)


def t_self_loop_fan():
    s = Stream()
    s.register(Gate("mixed", lambda e: [Event("other", {}), Event("mixed", {})]))
    assertThrows(lambda: s.emit(Event("mixed", {})), "Self-loop")


test("Stream: self-loop inside a fan-out array throws", t_self_loop_fan)


def t_cycle2():
    s = Stream()
    s.register(Gate("a", lambda e: Event("b", {})))
    s.register(Gate("b", lambda e: Event("a", {})))
    err = emitCatch(s, Event("a", {}))
    assert_(err is not None, "expected a throw — core 10 looped forever here")
    assertEqual(err.code, "SISO_CYCLE")
    # Caught at the first claimed event deeper than the gate count.
    assertEqual(err.depth, s.sampleHere()["gateCount"] + 1)


test("Stream: indirect cycle a → b → a throws SISO_CYCLE", t_cycle2)


def mkTo(sig, out):
    return Gate(sig, lambda e: Event(out, {}))


def t_cycle3():
    s = Stream()
    s.register(mkTo("a", "b"))
    s.register(mkTo("b", "c"))
    s.register(mkTo("c", "a"))
    assertThrows(lambda: s.emit(Event("a", {})), "Cycle")


test("Stream: longer indirect cycle a → b → c → a throws", t_cycle3)


def t_siblings():
    # Acyclicity is per causal path, not across the tree.
    def mk(sig, out):
        return Gate(sig, lambda e: events(out) if out else None)

    s = Stream()
    s.register(mk("root", ["x", "y"]))
    s.register(mk("x", ["z"]))
    s.register(mk("y", ["z"]))
    s.register(mk("z", ["done"]))
    s.emit(Event("root", {}))
    residue = s.sampleHere()["residue"]
    assertEqual(len(residue), 2, "z ran on both branches")
    assert_(all(e.type == "done" for e in residue))


test("Stream: same type in sibling branches is legal", t_siblings)


def t_longest_legal():
    s = Stream()
    s.register(mkTo("a", "b"))
    s.register(mkTo("b", "c"))
    s.register(mkTo("c", "answer"))
    s.emit(Event("a", {}))
    assertEqual(s.sampleHere()["residue"][0].type, "answer")


test("Stream: longest legal chain ends unclaimed at depth gates + 1", t_longest_legal)


def t_throw_commits_nothing():
    def mk(sig, out):
        return Gate(sig, lambda e: events(out))

    s = Stream()
    s.register(mk("root", ["leftover", "a"]))
    s.register(mk("a", ["b"]))
    s.register(mk("b", ["a"]))
    s.emit(Event("earlier", {}))  # settles normally
    assertThrows(lambda: s.emit(Event("root", {})), "Cycle")
    residue = s.sampleHere()["residue"]
    assertEqual(len(residue), 1, "only the earlier residue remains")
    assertEqual(residue[0].type, "earlier")


test("Stream: a propagation that throws commits no residue", t_throw_commits_nothing)


def t_usable():
    s = Stream()
    s.register(Gate("echo", lambda e: Event("echo", {})))
    assertThrows(lambda: s.emit(Event("echo", {})), "Self-loop")
    s.emit(Event("fine", {}))
    assertEqual(len(s.sampleHere()["residue"]), 1)


test("Stream: stream remains usable after a throw", t_usable)


def t_gate_threw():
    original = TypeError("bad input")

    def b(e):
        raise original

    s = Stream()
    s.register(Gate("a", lambda e: Event("b", {})))
    s.register(Gate("b", b))
    err = emitCatch(s, Event("a", {}))
    assert_(err is not None, "emit threw")
    assertEqual(err.code, "SISO_GATE_THREW")
    assertEqual(err.eventType, "b", "the event the gate was given")
    assertEqual(err.eventId, 2, "the delivery that threw")
    assertEqual(err.causeId, 1, "the delivery that caused it")
    assert_(err.cause is original, "the gate's own error is kept as cause")
    assert_("bad input" in err.message, "message carries the original")


test("Stream: a gate that throws rejects with SISO_GATE_THREW, ids and cause", t_gate_threw)


def t_gate_threw_commits():
    def b(e):
        raise RuntimeError("boom")

    s = Stream()
    s.register(Gate("root", lambda e: [Event("leftover", {}), Event("b", {})]))
    s.register(Gate("b", b))
    assertThrows(lambda: s.emit(Event("root", {})), "boom")
    assertEqual(len(s.sampleHere()["residue"]), 0, "the queued sibling is not committed")
    s.emit(Event("fine", {}))
    assertEqual(len(s.sampleHere()["residue"]), 1)


test("Stream: a gate that throws commits no residue; stream stays usable", t_gate_threw_commits)


def t_non_error():
    # Python's own attempt at raising a non-Error: the raise itself fails
    # inside the gate with a TypeError, which is the gate's exception.
    def x(e):
        raise "plain string"  # noqa: B016 -- the attempt is the point

    s = Stream()
    s.register(Gate("x", x))
    err = emitCatch(s, Event("x", {}))
    assert_(isinstance(err, Exception), "a real exception leaves emit")
    assertEqual(err.code, "SISO_GATE_THREW")
    assert_(isinstance(err.cause, TypeError), "the cause is the TypeError the attempt raised: " + H.show(err.cause))
    assert_("BaseException" in str(err.cause), "the cause names why: " + str(err.cause))
    assertEqual(err.causeId, None, "an external entry has no cause")


test("Stream: a gate that throws a non-Error is wrapped the same way", t_non_error)

# ── Stream: take residue ───────────────────────


def t_take():
    s = Stream()
    s.emit(Event("x", {"v": 1}))
    s.emit(Event("y", {"v": 2}))
    taken = s.takeResidue()
    assertEqual(len(taken), 2)
    assertEqual(taken[0].type, "x")
    assertEqual(taken[1].data["v"], 2)
    assertEqual(len(s.sampleHere()["residue"]), 0, "stream emptied")


test("Stream: takeResidue returns the residue and removes it", t_take)


def t_take_new():
    s = Stream()
    s.emit(Event("old", {}))
    s.takeResidue()
    s.emit(Event("new", {}))
    residue = s.sampleHere()["residue"]
    assertEqual(len(residue), 1)
    assertEqual(residue[0].type, "new")


test("Stream: residue after a take holds only new arrivals", t_take_new)


def t_take_empty():
    taken = Stream().takeResidue()
    assert_(type(taken) is list and len(taken) == 0)


test("Stream: takeResidue on an empty stream returns []", t_take_empty)


def t_sample_not_take():
    s = Stream()
    s.emit(Event("x", {}))
    s.sampleHere()
    s.sampleHere()
    assertEqual(len(s.takeResidue()), 1)


test("Stream: sampling does not take", t_sample_not_take)

# ── Stream: sampleHere ─────────────────────────


def t_sample_copy():
    s = Stream()
    s.emit(Event("x", {}))
    sample = s.sampleHere()
    sample["residue"].pop()
    assertEqual(len(s.sampleHere()["residue"]), 1, "original not affected")


test("Stream: sampleHere returns copy of residue", t_sample_copy)


def t_ids():
    # 12.0: eventCount is gone (ledger R1 retired). Each propagation numbers
    # its own deliveries 1, 2, ... including re-emits, shown to an observer;
    # nothing is counted across propagations and the sample has no counter.
    s = Stream()
    s.register(Gate("echo", lambda e: Event("out", {})))
    ids = []
    s.attach(lambda b: ids.append(",".join(str(r["eventId"]) for r in b["records"])))
    s.emit(Event("echo", {}))
    s.emit(Event("echo", {}))
    assertEqual(ids[0], "1,2", "the entry and its re-emit")
    assertEqual(ids[1], "1,2", "the next propagation numbers its own")
    assertEqual(",".join(sorted(s.sampleHere())), "gateCount,residue", "no counter in the sample")


test("Stream: eventCount tracks all emits including re-emits", t_ids)


def t_gatecount():
    s = Stream()
    s.register(Gate("a"))
    s.register(Gate("b"))
    s.register(Gate("c"))
    assertEqual(s.sampleHere()["gateCount"], 3)


test("Stream: gateCount in sampleHere", t_gatecount)


def t_accumulates():
    s = Stream()
    s.emit(Event("x", {}))
    s.emit(Event("y", {}))
    assertEqual(len(s.sampleHere()["residue"]), 2)


test("Stream: residue accumulates across emits", t_accumulates)

# ── Stream: constructor options ────────────────


def t_no_arg():
    # 12.0: Stream() is the only constructor: no gates, no residue, no
    # observers, and no log, stream id or lineage on the stream.
    s = Stream()
    for name in ("log", "streamId", "parentStreamId"):
        assert_(not hasattr(s, name), "the stream has no " + name)
    sample = s.sampleHere()
    assertEqual(len(sample["residue"]), 0)
    assertEqual(sample["gateCount"], 0)
    seen = []
    s.attach(lambda b: seen.append(1))
    s.emit(Event("x", {}))
    assertEqual(len(seen), 1, "the only observer is the one attached")


test("Stream: no-arg constructor still works", t_no_arg)


def t_accepts_log():
    # 12.0: Stream takes no options. A log is an observer, attached through
    # the observe door; Python's own attempt to pass options is refused by
    # Stream's arity, so nothing is called on them.
    calls = []
    fakeLog = {"nextStreamId": lambda: calls.append(1) or 42, "record": lambda *a: calls.append(1)}
    assertThrows(lambda: Stream({"log": fakeLog, "parentStreamId": 7}), "TypeError")
    assertEqual(len(calls), 0, "the log option is not read")
    s = Stream()
    s.emit(Event("x", {}))
    for name in ("log", "streamId", "parentStreamId"):
        assert_(not hasattr(s, name), "the stream has no " + name)
    batches = []
    s.attach(lambda b: batches.append(1))
    s.emit(Event("y", {}))
    assertEqual(len(batches), 1, "an observer attaches through the door")


test("Stream: constructor accepts log", t_accepts_log)

H.finish("core")
