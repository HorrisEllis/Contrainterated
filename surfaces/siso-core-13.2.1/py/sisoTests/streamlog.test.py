# streamlog: siso.observer.streamlog (py/code/siso/observer/streamlog.py) on
# the core 13 observer seam. Port of lua/sisoTests/streamlog.test.lua, case
# by case and in its order (its 34 cases, each under the Lua case's name),
# then the Python cases below. Comments marked "12:" carry the reference's
# notes on where core 12 departs from 11.1's oracle.
# Integration cases drive py/sisoTests/contract-fake-core.py (loaded by path),
# as the Lua suite drives its fake core; siso.core is never imported.
# Runnable from any directory: python3 py/sisoTests/streamlog.test.py
#
# Kept as they are (the mechanism exists in Python), with these counterparts:
#   Lua's nil field             an absent key (`k not in e`); causeId, which
#                               the seam's record carries as None, is None
#   pcall(s.emit, ...)          try/except Exception around s.emit
#   err.code, err.eventId       the rejection's attributes
#   math.type(t) == "integer"   type(t) is int (bool excluded)
#   os.time()                   int(time.time())
#   table.remove(s.entries)     s["entries"].pop()
#   error("boom") in a gate     raise RuntimeError("boom")
#   setLevel(nil)               setLevel(None)
#   seam.batch_problems         S.batchProblems
# Turned into Python's own counterpart: none needed; every case's mechanism
#   exists in Python.
# Cannot apply: none.
# Python cases (after the port): an entry holds exactly the keys of its
#   level (the counterpart of Lua's nil-field checks, made exact); DATA's
#   data is the seam's frozen copy, so a write to it from a sample raises;
#   the module holds no mutable global and a log no instance __dict__ (the
#   counterpart of "no module-global state", which Lua shows only through
#   labels); a non-str or str-subclass level is unknown; a batch the log
#   cannot read keeps nothing but is still counted.
import importlib.util
import os
import sys
import time
import types

sys.dont_write_bytecode = True  # no __pycache__ in the project tree
HERE = os.path.dirname(os.path.abspath(__file__))
sys.path.insert(0, os.path.normpath(os.path.join(HERE, "..", "code")))

from siso import harness as H  # noqa: E402
from siso.contracts import observer_seam as S  # noqa: E402
from siso.observer.streamlog import StreamLog  # noqa: E402
import siso.observer.streamlog as streamlogModule  # noqa: E402


def loadFake():
    spec = importlib.util.spec_from_file_location("contract_fake_core", os.path.join(HERE, "contract-fake-core.py"))
    mod = importlib.util.module_from_spec(spec)
    spec.loader.exec_module(mod)
    return mod


fake = loadFake()
core = fake.new()
Event, Gate, Stream = core["Event"], core["Gate"], core["Stream"]


# A hand-built batch stands in for 11.1's direct log.record(...) calls.
# 12: the log is fed a whole propagation, never one delivery.
def rec(id_, cause, t, claimed, data=None):
    return {"eventId": id_, "causeId": cause, "eventType": t, "claimed": claimed, "data": {} if data is None else data}


def batch(records, outcome="settled", rejection=None):
    return {"outcome": outcome, "rejection": rejection, "records": records}


def one(t, claimed, data=None):
    return batch([rec(1, None, t, claimed, data)])


def thread(entries, label, propagation, fromId):
    """Walk causeId within one propagation of one stream: -> "t1,t2,..."."""
    byId = {}
    for e in entries:
        if e["propagation"] == propagation and (label is None or e.get("streamLabel") == label):
            byId[e["eventId"]] = e
    out = []
    cur = byId.get(fromId)
    while cur is not None:
        out.insert(0, cur["type"])
        cur = byId.get(cur["causeId"]) if cur["causeId"] is not None else None
    return ",".join(out)


def emitCatch(s, e):
    try:
        s.emit(e)
        return None
    except Exception as err:
        return err


# ── Levels ─────────────────────────────────────

def t_off():
    log = StreamLog("OFF")
    log.observer("s")(one("x", True))
    H.assertEqual(log.sample()["count"], 0)


def t_default():
    H.assertEqual(StreamLog().level(), "EVENTS")


def t_events():
    log = StreamLog("EVENTS")
    log.observer("s")(one("foo", True, {"big": True}))
    e = log.sample()["entries"]
    H.assertEqual(len(e), 1)
    H.assertEqual(e[0]["type"], "foo")
    # 12: claimed is the seam's boolean (11.1 gave the signature, which is the type)
    H.assertEqual(e[0]["claimed"], True)
    H.assertEqual(e[0]["eventId"], 1)
    H.assertEqual(e[0]["causeId"], None)
    H.assertEqual(e[0]["propagation"], 1)
    H.assertEqual(e[0]["outcome"], "settled")
    H.assert_("streamLabel" not in e[0], "no streamLabel at EVENTS")
    H.assert_("data" not in e[0], "no data at EVENTS")


def t_eventsResidue():
    # 11.1: claimed null; 12: false
    log = StreamLog("EVENTS")
    log.observer("s")(one("orphan", False))
    H.assertEqual(log.sample()["entries"][0]["claimed"], False)


def t_deep():
    # 12: labels chosen by whoever attaches, in place of 11.1's numeric stream ids
    log = StreamLog("DEEP")
    log.observer("parent")
    log.observer("child", "parent")(one("x", True))
    e = log.sample()["entries"][0]
    H.assertEqual(e["streamLabel"], "child")
    H.assertEqual(e["parentLabel"], "parent")
    H.assert_("data" not in e, "no data at DEEP")


def t_deepNoParent():
    log = StreamLog("DEEP")
    log.observer("s")(one("x", False))
    e = log.sample()["entries"][0]
    H.assertEqual(e["streamLabel"], "s")
    H.assert_("parentLabel" not in e, "no parentLabel without a parent")


def t_data():
    log = StreamLog("DATA")
    log.observer("p")
    log.observer("c", "p")(one("y", True, {"val": 42}))
    e = log.sample()["entries"][0]
    H.assertEqual(e["streamLabel"], "c")
    H.assertEqual(e["parentLabel"], "p")
    H.assertEqual(e["data"]["val"], 42)


H.test("StreamLog: OFF records nothing", t_off)
H.test("StreamLog: default level is EVENTS", t_default)
H.test("StreamLog: EVENTS records type and claimed", t_events)
H.test("StreamLog: EVENTS records claimed false for residue", t_eventsResidue)
H.test("StreamLog: DEEP includes streamLabel and parentLabel", t_deep)
H.test("StreamLog: DEEP omits parentLabel when there is none", t_deepNoParent)
H.test("StreamLog: DATA includes everything", t_data)


# ── Unknown levels (X2) ────────────────────────

def t_unknownAtConstruction():
    # 11.1: an unknown level silently acted as OFF
    H.assertThrows(lambda: StreamLog("VERBOSE"), "unknown level 'VERBOSE'")
    H.assertThrows(lambda: StreamLog("events"), "unknown level")
    H.assertThrows(lambda: StreamLog(3), "unknown level")


def t_unknownWhenSet():
    log = StreamLog("DEEP")
    H.assertThrows(lambda: log.setLevel("LOUD"), "unknown level 'LOUD'")
    H.assertThrows(lambda: log.setLevel(None), "unknown level")
    H.assertEqual(log.level(), "DEEP")
    log.observer("s")(one("x", True))
    H.assertEqual(log.sample()["entries"][0]["streamLabel"], "s", "still recording at DEEP")


H.test("StreamLog: an unknown level is refused at construction (X2)", t_unknownAtConstruction)
H.test("StreamLog: an unknown level is refused when set, and the level is kept (X2)", t_unknownWhenSet)


# ── Sequencing ─────────────────────────────────

def t_seq():
    log = StreamLog("EVENTS")
    obs = log.observer("s")
    obs(batch([rec(1, None, "a", True), rec(2, 1, "b", False)]))
    obs(one("c", False))
    e = log.sample()["entries"]
    H.assertEqual(e[0]["seq"], 0)
    H.assertEqual(e[1]["seq"], 1)
    H.assertEqual(e[2]["seq"], 2)


def t_time():
    # 12: whole seconds (11.1: Date.now() milliseconds)
    log = StreamLog("EVENTS")
    before = int(time.time())
    log.observer("s")(one("x", False))
    after = int(time.time())
    t = log.sample()["entries"][0]["time"]
    H.assert_(type(t) is int and before <= t <= after, "time within the call")


H.test("StreamLog: seq increments", t_seq)
H.test("StreamLog: entries have timestamps", t_time)


# ── Labels (12, in place of nextStreamId) ──────

def t_labels():
    a, b = StreamLog(), StreamLog()
    a.observer("s")
    b.observer("s")  # no module-global counter or registry: another log is unaffected
    H.assertThrows(lambda: a.observer("s"), "already used")
    H.assertThrows(lambda: a.observer(""), "non-empty string")
    H.assertThrows(lambda: a.observer(7), "non-empty string")
    H.assertThrows(lambda: a.observer("t", "nope"), "not a label of this log")
    H.assertThrows(lambda: b.observer("t", "x"), "not a label of this log")


def t_propagationsPerStream():
    log = StreamLog("DEEP")
    p, c = log.observer("p"), log.observer("c", "p")
    p(one("a", False))
    c(one("b", False))
    p(one("d", False))
    e = log.sample()["entries"]
    H.assertEqual(e[0]["propagation"], 1)
    H.assertEqual(e[1]["propagation"], 1)
    H.assertEqual(e[2]["propagation"], 2)


def t_propagationsAtOff():
    log = StreamLog("OFF")
    obs = log.observer("s")
    obs(one("a", False))
    log.setLevel("EVENTS")
    obs(one("b", False))
    e = log.sample()["entries"]
    H.assertEqual(len(e), 1)
    H.assertEqual(e[0]["propagation"], 2)


H.test("StreamLog: labels are per log and unique within it", t_labels)
H.test("StreamLog: propagations are numbered per observed stream", t_propagationsPerStream)
H.test("StreamLog: propagations at OFF are still counted", t_propagationsAtOff)


# ── sample and clear ───────────────────────────

def t_sampleCopy():
    log = StreamLog("EVENTS")
    log.observer("s")(one("x", False))
    s = log.sample()
    s["entries"].pop()
    H.assertEqual(log.sample()["count"], 1)
    s2 = log.sample()
    s2["entries"][0]["type"] = "changed"
    H.assertEqual(log.sample()["entries"][0]["type"], "x", "an entry write does not reach the log")
    H.assertEqual(s2["level"], "EVENTS")


def t_clear():
    log = StreamLog("EVENTS")
    obs = log.observer("s")
    obs(batch([rec(1, None, "x", True), rec(2, 1, "y", False)]))
    log.clear()
    H.assertEqual(log.sample()["count"], 0)
    obs(one("z", False))
    e = log.sample()["entries"][0]
    H.assertEqual(e["seq"], 0, "seq resets")
    H.assertEqual(e["propagation"], 2, "propagation numbers are kept")


H.test("StreamLog: sample returns copy", t_sampleCopy)
H.test("StreamLog: clear resets entries and seq", t_clear)


# ── Runtime level change ───────────────────────

def t_levelChange():
    log = StreamLog("OFF")
    obs = log.observer("s")
    obs(one("a", False))
    H.assertEqual(log.sample()["count"], 0)
    log.setLevel("EVENTS")
    obs(one("b", False))
    H.assertEqual(log.sample()["count"], 1)
    log.setLevel("OFF")
    obs(one("c", False))
    H.assertEqual(log.sample()["count"], 1)


def t_upgrade():
    log = StreamLog("EVENTS")
    obs = log.observer("s")
    obs(one("a", False, {"x": 1}))
    log.setLevel("DATA")
    obs(one("b", False, {"x": 2}))
    e = log.sample()["entries"]
    H.assert_("data" not in e[0], "first entry has no data")
    H.assertEqual(e[1]["data"]["x"], 2, "second entry has data")


H.test("StreamLog: level change takes effect immediately", t_levelChange)
H.test("StreamLog: upgrade level mid-run adds detail", t_upgrade)


# ── Integration with a stream (contract fake core) ───

def t_seamBatch():
    s = Stream()
    log = StreamLog("DATA")
    obs = log.observer("s")
    shown = []
    s.attach(lambda b: shown.append(b))
    s.attach(obs)
    s.register(Gate("root", lambda e: [Event("a"), Event("b")]))
    s.emit(Event("root", {"k": 1}))
    H.assertEqual(len(shown), 1)
    H.assertEqual(S.batchProblems(shown[0]), [])
    e = log.sample()["entries"]
    H.assertEqual(len(e), 3)
    H.assertEqual(e[0]["data"]["k"], 1)


def t_emitRecords():
    log = StreamLog("EVENTS")
    s = Stream()
    s.attach(log.observer("s"))
    s.register(Gate("sink", lambda e: None))
    s.emit(Event("sink", {}))
    s.emit(Event("orphan", {}))
    e = log.sample()["entries"]
    H.assertEqual(len(e), 2)
    H.assertEqual(e[0]["claimed"], True)
    H.assertEqual(e[1]["claimed"], False)


def t_sharedLog():
    log = StreamLog("DEEP")
    parent, child = Stream(), Stream()
    parent.attach(log.observer("parent"))
    child.attach(log.observer("child", "parent"))
    parent.emit(Event("parent_event", {}))
    child.emit(Event("child_event", {}))
    e = log.sample()["entries"]
    H.assertEqual(len(e), 2)
    H.assertEqual(e[0]["streamLabel"], "parent")
    H.assert_("parentLabel" not in e[0], "the parent stream has no parentLabel")
    H.assertEqual(e[1]["streamLabel"], "child")
    H.assertEqual(e[1]["parentLabel"], "parent")
    # 12: both are delivery 1 of propagation 1 of their own stream; the label disambiguates
    H.assertEqual(e[0]["eventId"], e[1]["eventId"])


def t_returnedRecorded():
    log = StreamLog("EVENTS")
    s = Stream()
    s.attach(log.observer("s"))
    s.register(Gate("forward", lambda ev: Event("forwarded", {"from": ev.data["origin"]})))
    s.emit(Event("forward", {"origin": "test"}))
    e = log.sample()["entries"]
    H.assertEqual(len(e), 2)
    H.assertEqual(e[0]["type"], "forward")
    H.assertEqual(e[0]["claimed"], True)
    H.assertEqual(e[1]["type"], "forwarded")
    H.assertEqual(e[1]["claimed"], False)


H.test("StreamLog: what it is shown passes the seam's batch check", t_seamBatch)
H.test("StreamLog: Stream.emit records to log", t_emitRecords)
H.test("StreamLog: shared log across parent and sub-stream", t_sharedLog)
H.test("StreamLog: returned events are recorded via log", t_returnedRecorded)


# ── Causal threads ─────────────────────────────

def t_chain():
    log = StreamLog("EVENTS")
    s = Stream()
    s.attach(log.observer("s"))
    s.register(Gate("x", lambda e: Event("y")))
    s.register(Gate("y", lambda e: Event("z")))
    s.emit(Event("x", {}))
    e = log.sample()["entries"]
    H.assertEqual(len(e), 3)
    H.assertEqual(e[0]["eventId"], 1)
    H.assertEqual(e[0]["causeId"], None, "external emit has no cause")
    H.assertEqual(e[1]["eventId"], 2)
    H.assertEqual(e[1]["causeId"], 1, "y caused by x")
    H.assertEqual(e[2]["eventId"], 3)
    H.assertEqual(e[2]["causeId"], 2, "z caused by y")


def t_fanOut():
    log = StreamLog("EVENTS")
    s = Stream()
    s.attach(log.observer("s"))
    s.register(Gate("root", lambda e: [Event("left"), Event("right")]))
    s.emit(Event("root", {}))
    e = log.sample()["entries"]
    H.assertEqual(len(e), 3)
    H.assertEqual(e[1]["causeId"], e[0]["eventId"], "left caused by root")
    H.assertEqual(e[2]["causeId"], e[0]["eventId"], "right caused by root")


def t_separateRoots():
    log = StreamLog("EVENTS")
    s = Stream()
    s.attach(log.observer("s"))
    s.emit(Event("a", {}))
    s.emit(Event("b", {}))
    e = log.sample()["entries"]
    H.assertEqual(e[0]["causeId"], None)
    H.assertEqual(e[1]["causeId"], None)
    # 12: ids restart in every propagation (11.1: "ids continue across emits");
    # the log's propagation number tells the two apart
    H.assertEqual(e[0]["eventId"], 1)
    H.assertEqual(e[1]["eventId"], 1)
    H.assertEqual(e[0]["propagation"], 1)
    H.assertEqual(e[1]["propagation"], 2)


def t_mixedTree():
    # root fans out to two chains; entries interleave by generation, but
    # walking causeId untangles each thread: order means time, causeId cause.
    log = StreamLog("DEEP")
    s = Stream()
    s.attach(log.observer("s"))

    def mk(sig, outs):
        return Gate(sig, lambda e: [Event(t) for t in outs])

    s.register(mk("root", ["a", "b"]))
    s.register(mk("a", ["a1"]))
    s.register(mk("b", ["b1"]))
    s.register(mk("a1", ["a2"]))
    s.emit(Event("x"))  # 12: an earlier propagation reuses the same ids; the walk must not cross it
    s.emit(Event("root", {}))
    entries = log.sample()["entries"]
    a2 = None
    for e in entries:
        if e["type"] == "a2":
            a2 = e
    H.assert_(a2 is not None, "a2 was logged")
    H.assertEqual(thread(entries, "s", a2["propagation"], a2["eventId"]), "root,a,a1,a2")


H.test("StreamLog: chain entries carry eventId and causeId", t_chain)
H.test("StreamLog: fan-out siblings share a cause", t_fanOut)
H.test("StreamLog: separate external emits are separate roots", t_separateRoots)
H.test("StreamLog: causal chain reconstructible from a mixed tree", t_mixedTree)


# ── Acyclicity diagnostics ─────────────────────

def t_noRetries():
    log = StreamLog("DATA")
    s = Stream()
    s.attach(log.observer("s"))
    s.emit(Event("plain", {}))
    e = log.sample()["entries"]
    H.assertEqual(len(e), 1)
    H.assert_("retries" not in e[0], "no retries in core 12")


def t_cycle():
    # The rejection cannot name the loop by itself (K5); an attached log can,
    # by walking causeId from the delivery the rejection names.
    log = StreamLog("EVENTS")
    s = Stream()
    s.attach(log.observer("s"))
    s.register(Gate("a", lambda e: Event("b")))
    s.register(Gate("b", lambda e: Event("a")))
    err = emitCatch(s, Event("a", {}))
    H.assert_(err is not None, "the cycle rejects")
    H.assertEqual(err.code, "SISO_CYCLE")
    entries = log.sample()["entries"]
    last = entries[-1]
    H.assertEqual(last["eventId"], err.eventId, "the records end with the delivery the rejection names")
    H.assertEqual(last["outcome"], "rejected")
    H.assertEqual(thread(entries, None, last["propagation"], err.eventId), "a,b,a")


def t_selfLoop():
    log = StreamLog("EVENTS")
    s = Stream()
    s.attach(log.observer("s"))
    s.register(Gate("loop", lambda e: Event("loop")))
    err = emitCatch(s, Event("loop", {}))
    H.assert_(err is not None, "the self-loop rejects")
    H.assertEqual(err.code, "SISO_SELF_LOOP")
    e = log.sample()["entries"]
    H.assertEqual(len(e), 1, "the looping return is never delivered")
    H.assertEqual(e[0]["eventId"], err.eventId)
    H.assertEqual(e[0]["claimed"], True)
    H.assertEqual(e[0]["outcome"], "rejected")


def t_gateThrew():
    log = StreamLog("EVENTS")
    s = Stream()
    s.attach(log.observer("s"))

    def boom(e):
        raise RuntimeError("boom")

    s.register(Gate("a", lambda e: Event("b")))
    s.register(Gate("b", boom))
    err = emitCatch(s, Event("a", {}))
    H.assert_(err is not None, "the throw rejects")
    H.assertEqual(err.code, "SISO_GATE_THREW")
    found = None
    for x in log.sample()["entries"]:
        if x["eventId"] == err.eventId:
            found = x
    H.assert_(found is not None, "the rejection names a logged delivery")
    H.assertEqual(found["type"], "b")
    H.assertEqual(found["claimed"], True)
    H.assertEqual(found["causeId"], err.causeId)


def t_refusedEntry():
    log = StreamLog("EVENTS")
    s = Stream()
    obs = log.observer("s")
    s.attach(obs)
    emitCatch(s, "not an event")
    H.assertEqual(log.sample()["count"], 0)
    s.emit(Event("ok"))
    H.assertEqual(log.sample()["entries"][0]["propagation"], 2)


def t_presenceAcyclicity():
    def run(withLog):
        s = Stream()
        if withLog:
            s.attach(StreamLog("DATA").observer("s"))
        s.register(Gate("a", lambda e: Event("b")))
        s.register(Gate("b", lambda e: Event("a")))
        err = emitCatch(s, Event("a", {}))
        if err is None:
            return "none"
        return str(err.code) + "@" + repr(err.eventId) + "/" + repr(err.depth)

    H.assertEqual(run(True), run(False))


def t_absence():
    # 12: sampleHere carries no eventCount (core-api), so compare residue only
    def run(withLog):
        s = Stream()
        if withLog:
            s.attach(StreamLog("EVENTS").observer("s"))
        s.register(Gate("x", lambda e: Event("y")))
        s.emit(Event("x", {}))
        r = s.sampleHere()["residue"]
        return str(len(r)) + ":" + r[0].type

    H.assertEqual(run(True), run(False))


def t_detach():
    log = StreamLog("EVENTS")
    s = Stream()
    obs = log.observer("s")
    s.attach(obs)
    s.emit(Event("a"))
    s.detach(obs)
    s.emit(Event("b"))
    H.assertEqual(log.sample()["count"], 1)
    s.attach(obs)
    s.emit(Event("c"))
    e = log.sample()["entries"]
    H.assertEqual(len(e), 2)
    H.assertEqual(e[1]["propagation"], 2)


H.test("StreamLog: entries carry no retries field", t_noRetries)
H.test("StreamLog: a cycle is readable from the causal chain", t_cycle)
H.test("StreamLog: self-loop rejection points at the producing entry", t_selfLoop)
H.test("StreamLog: gate-threw rejection points at the claiming entry", t_gateThrew)
H.test("StreamLog: a refused entry is a propagation with no entries", t_refusedEntry)
H.test("StreamLog: log presence does not change acyclicity outcome", t_presenceAcyclicity)
H.test("StreamLog: log absence changes nothing", t_absence)
H.test("StreamLog: detaching stops recording; re-attaching continues numbering", t_detach)


# ── Python cases ───────────────────────────────

EVENTS_KEYS = {"seq", "time", "propagation", "outcome", "eventId", "causeId", "type", "claimed"}


def t_exactKeys():
    # Lua's nil fields, made exact: an entry holds the keys of its level, no others
    for level, extra in (("EVENTS", set()), ("DEEP", {"streamLabel"}), ("DATA", {"streamLabel", "data"})):
        log = StreamLog(level)
        log.observer("s")(one("x", True))
        log.observer("c", "s")(one("y", False))
        e = log.sample()["entries"]
        H.assertEqual(set(e[0]), EVENTS_KEYS | extra, level + ", no parent")
        withParent = extra | ({"parentLabel"} if level != "EVENTS" else set())
        H.assertEqual(set(e[1]), EVENTS_KEYS | withParent, level + ", with a parent")


def t_dataFrozen():
    # DATA keeps the seam's frozen copy: a write through a sample raises and
    # reaches neither the log nor the stream's residue
    log = StreamLog("DATA")
    s = Stream()
    s.attach(log.observer("s"))
    s.emit(Event("x", {"v": 1, "n": [1]}))
    d = log.sample()["entries"][0]["data"]

    def write():
        d["v"] = 2

    H.assertThrows(write)
    H.assertEqual(log.sample()["entries"][0]["data"]["v"], 1)
    H.assertEqual(s.takeResidue()[0].data["v"], 1)
    H.assert_(log.sample()["entries"][0]["data"] is d, "the sample hands the data the stream showed")


def t_noGlobalState():
    # nothing at module level can be written as state: only immutable
    # constants, functions, the class and the imported standard module
    for name, v in vars(streamlogModule).items():
        if name.startswith("__") and name.endswith("__"):
            continue
        ok = (type(v) in (str, int, float, bool, type(None), tuple) or isinstance(v, (types.FunctionType, type))
              or isinstance(v, types.ModuleType))
        H.assert_(ok, "module global '" + name + "' is a " + type(v).__name__)
        if type(v) is tuple:
            H.assert_(all(type(x) is str for x in v), "module tuple '" + name + "' holds only str")
    log = StreamLog()
    H.assert_(not hasattr(log, "__dict__"), "a log has no instance __dict__")

    def add():
        log.extra = 1

    H.assertThrows(add)
    # two logs observing the same batches keep separate records
    a, b = StreamLog("EVENTS"), StreamLog("EVENTS")
    a.observer("s")(one("x", True))
    H.assertEqual(b.sample()["count"], 0)
    b.observer("s")(one("y", True))
    H.assertEqual(a.sample()["entries"][0]["seq"], 0)
    H.assertEqual(b.sample()["entries"][0]["seq"], 0)
    H.assertEqual(b.sample()["entries"][0]["propagation"], 1)


class _Level(str):
    pass


def t_levelTypes():
    H.assertThrows(lambda: StreamLog(_Level("DEEP")), "unknown level")
    H.assertThrows(lambda: StreamLog(b"DEEP"), "unknown level")
    H.assertThrows(lambda: StreamLog(["DEEP"]), "unknown level")
    for level in ("OFF", "EVENTS", "DEEP", "DATA"):
        H.assertEqual(StreamLog(level).level(), level)
    H.assertThrows(lambda: StreamLog().observer("t", ["s"]), "not a label of this log")


def t_unreadableBatch():
    # an unreadable batch raises in the observer (the stream isolates it),
    # keeps no partial entries, and is still counted
    log = StreamLog("EVENTS")
    obs = log.observer("s")
    bad = batch([rec(1, None, "a", True), {"eventId": 2}])
    H.assertThrows(lambda: obs(bad))
    H.assertEqual(log.sample()["count"], 0)
    obs(one("b", False))
    e = log.sample()["entries"]
    H.assertEqual(e[0]["seq"], 0)
    H.assertEqual(e[0]["propagation"], 2)


H.test("StreamLog (Python): an entry holds exactly the keys of its level", t_exactKeys)
H.test("StreamLog (Python): DATA keeps the seam's frozen copy", t_dataFrozen)
H.test("StreamLog (Python): no module-global state, no instance __dict__", t_noGlobalState)
H.test("StreamLog (Python): a level is one of four exact str names", t_levelTypes)
H.test("StreamLog (Python): an unreadable batch keeps nothing and is still counted", t_unreadableBatch)

H.finish("streamlog")
