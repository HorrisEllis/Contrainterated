# stream-stateless.test.py -- the doors carry no state (py-core-api
# STATELESS THROUGH THE EVENT, OWNERSHIP; py-observer-seam THE REJECTION).
# Port of lua/sisoTests/stream-stateless.test.lua, case by case. Nothing
# reachable from an event, a sample, a take or an observer's batch may be
# an object the core holds or shares. Runs the contracts' checks on the
# real core, then walks everything a holder reaches by ordinary access and
# pins that it is fresh, immutable and shared with no other stream or
# propagation. The gates below are fixtures for the stream, not project
# gates.
#
# How the Lua cases map (py-core-api SUBSTRATE):
#   - getmetatable answering strings becomes "every class reached is the
#     core's and locked": Lua's metatables are hidden, Python's classes are
#     reachable by type(), so the walk below (reach) collects instances and
#     the classes are judged separately (locked, nothing settable).
#     Classes are shared by every event of the process by necessity; with
#     nothing settable on them they carry no state.
#   - Lua's rawset becomes object.__setattr__ (instances); on a class it
#     is type.__setattr__, which bypasses the lock: that is the SUBSTRATE's
#     named convention (the counterpart of Lua's debug library), so the
#     counting case writes instances only and pins the class form as the
#     named concession (written and removed at once).
#   - "pairs hands out the frozen table as its state" becomes: iterating a
#     FrozenDict hands out a builtin key iterator, which holds nothing of
#     the core's and writes nothing.
#   - Freeze's private KEY and the view it reaches do not exist (an Event
#     and a FrozenDict are recognised by their exact class). Python's own
#     attempt at the same violation is the storage a FrozenDict exposes by
#     ordinary access (its `_m` slot): a MappingProxyType, read-only, that
#     accepts no attribute and no item, and is never shared. The two
#     captured-key cases become those pins.
#   - "readonly:" sentinels vanish (None is null): None goes through every
#     door as None. "The core 12 form is not handed to observers" becomes
#     Python's own writable object in a cause (a list): the host's
#     rejection carries it, the observer's copy carries its own tuple.
# Added for the Python instance (py-core-api 13.2.0, independent review):
#   - no class reachable from an event is an ABC: FrozenDict implements the
#     Mapping protocol itself and is registered with collections.abc.Mapping
#     from outside, so the review's channel (a gate registering classes
#     with super(type(T), T).register and counting them with issubclass)
#     carries nothing across streams.
# No case is left out.
# Runnable from any directory: python3 py/sisoTests/stream-stateless.test.py
import sys

sys.dont_write_bytecode = True

import abc  # noqa: E402
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
from siso.core.rejection import Rejection  # noqa: E402
from siso.core.stream import Stream  # noqa: E402

core = {"Event": Event, "Gate": Gate, "Stream": Stream}
test, assert_, assertEqual = H.test, H.assert_, H.assertEqual

LEAVES = (str, int, float, bool, type(None))


def noProblems(lst, label=None):
    assert_(len(lst) == 0, ((label + ": ") if label else "") + "; ".join(lst))


def slotValues(o):
    out = []
    for c in type(o).__mro__:
        for n in c.__dict__.get("__slots__", ()):
            try:
                out.append(object.__getattribute__(o, n))
            except Exception:
                pass
    return out


def reach(roots):
    """{id: object}: every object (not a leaf, not a class) a holder reaches
    from roots by ordinary access: items of containers, iteration, and every
    attribute stored on an instance (its __dict__ and slots, so a field
    written past the refusal counts)."""
    seen = {}
    stack = list(roots)
    while stack:
        v = stack.pop()
        if type(v) in LEAVES or isinstance(v, type) or id(v) in seen:
            continue
        seen[id(v)] = v
        if isinstance(v, (list, tuple, set, frozenset)):
            stack.extend(v)
        elif isinstance(v, (dict, FrozenDict, types.MappingProxyType)):
            for k, x in list(v.items()):
                stack.append(k)
                stack.append(x)
        try:
            stack.extend(object.__getattribute__(v, "__dict__").values())
        except Exception:
            pass
        stack.extend(slotValues(v))
    return seen


def overlap(a, b):
    return sum(1 for k in a if k in b)


def raiser(exc):
    def g(e):
        raise exc
    return g


class GateError(Exception):
    def __init__(self):
        super().__init__("the gate's own")
        self.first = "the gate's own"
        self.nested = [1]
        self.n = None


def scenario():
    """Everything that left one fresh stream through its doors in a settled
    and a rejected propagation: the events its gates were given, two
    samples, the take, and an observer's batches."""
    out = []
    s = Stream()

    def root(e):
        out.append(e)
        return [Event("fx_mid", {"list": [e.data["v"], 2], "m": {"k": "a"}, "n": None}), Event("fx_leaf", e.data)]

    def mid(e):
        out.append(e)
        return Event("fx_out", {"from": e.data})

    def bad(e):
        out.append(e)
        raise GateError()

    s.register(Gate("fx_root", root))
    s.register(Gate("fx_mid", mid))
    s.register(Gate("fx_bad", bad))
    batches = []
    s.attach(lambda b: batches.append(b))
    s.emit(Event("fx_root", {"v": 1, "deep": {"a": {"b": [1]}}}))
    try:
        s.emit(Event("fx_bad", {"w": [1]}))
    except Exception:
        pass
    out.append(s.sampleHere())
    out.append(s.sampleHere())
    out.append(s.takeResidue())
    out.extend(batches)
    return out, batches


# ── the contracts on the real core ─────────────


def t_api():
    noProblems(API.checkReadonly(core), "readonly")
    noProblems(API.checkOwnership(core), "ownership")
    noProblems(API.checkDoors(core), "doors")


def t_seam():
    noProblems(SEAM.checkSeam(core), "seam")
    noProblems(SEAM.checkOwnership(core), "ownership")


test("core-api read-only, ownership and doors hold on the core", t_api)
test("observer-seam seam and ownership hold on the core", t_seam)

# ── identities answer with strings ─────────────


def locked(cls):
    try:
        setattr(cls, "sisoProbe", 1)
    except Exception:
        return True
    try:
        delattr(cls, "sisoProbe")
    except Exception:
        pass
    return False


def t_identities():
    e = Event("t", {"list": [1], "m": {}})
    assert_(type(e) is Event and locked(Event), "an event's class is the core's Event, locked")
    assert_(type(e.data) is FrozenDict and locked(FrozenDict), "its data's class is the core's FrozenDict, locked")
    assert_(type(e.data["list"]) is tuple, "a list is a tuple (a builtin type, which takes no attribute)")
    assert_(type(Event("u").data) is FrozenDict, "default data")
    look = types.SimpleNamespace(type="t", data=e.data)
    assert_(Event.isEvent(e) and not Event.isEvent({"type": "t", "data": {}}) and not Event.isEvent(look),
            "Event.isEvent compares the exact class")
    assert_(locked(type(Event.isEvent)), "Event.isEvent's class is locked")
    s = Stream()
    s.register(Gate("fx_boom", raiser(RuntimeError("boom"))))
    try:
        s.emit(Event("fx_boom"))
        H.fail("settled")
    except Exception as err:
        r = err
    assert_(type(r) is Rejection and Rejection.isRejection(r), "Rejection.isRejection compares the exact class")
    assertEqual(str(r), r.message, "str of the raised rejection is still its message")
    assert_(locked(type(r)), "nothing settable answers type() on a rejection")


test("events answer \"siso.event\", plain data false, rejections \"siso.rejection\"", t_identities)

# ── nothing reachable is shared ────────────────


def t_two_streams():
    a = reach(scenario()[0])
    b = reach(scenario()[0])
    assert_(len(a) > 20 and len(b) > 20, "the walk reached the outputs (" + str(len(a)) + ", " + str(len(b)) + ")")
    assertEqual(overlap(a, b), 0, "objects reachable from both streams")


test("two streams share no table through anything that left their doors", t_two_streams)


def t_two_events_props():
    assertEqual(overlap(reach([Event("t", {"a": [1]})]), reach([Event("t", {"a": [1]})])), 0, "two events")
    s = Stream()
    given = []

    def g(e):
        given.append(e)
        return Event("fx_out", {"got": e.data})

    s.register(Gate("fx_g", g))
    entry = Event("fx_g", {"v": [1]})
    s.emit(entry)
    first = [given[0], s.takeResidue()]
    s.emit(entry)
    second = [given[1], s.takeResidue()]
    assertEqual(overlap(reach(first), reach(second)), 0, "one entry entered twice")
    assertEqual(overlap(reach([entry]), reach(first)), 0, "the entering code's event and the stream's")


test("two events, and two propagations of one stream, share no table", t_two_events_props)


def t_only_immutable():
    out, batches = scenario()
    own = set()  # the receiver's own containers: lists and batch, record and sample dicts
    for v in out:
        own.add(id(v))
        if isinstance(v, dict) and type(v.get("residue")) is list:
            own.add(id(v["residue"]))
    for b in batches:
        own.add(id(b["records"]))
        for r in b["records"]:
            own.add(id(r))
    checked = 0
    for k, t in reach(out).items():
        if k in own:
            continue
        checked += 1
        assert_(type(t) in (Event, FrozenDict, tuple, types.MappingProxyType),
                "a reachable object is a " + type(t).__name__)
        try:
            setattr(t, "sisoProbe", 1)
            H.fail("a reachable " + type(t).__name__ + " accepts an attribute")
        except (AttributeError, TypeError):
            pass
        assert_(type(t) is tuple or type(t) is types.MappingProxyType or locked(type(t)),
                "the class of a reachable " + type(t).__name__ + " is locked")
    assert_(checked > 20, "the walk checked the frozen objects (" + str(checked) + ")")


test("plain data reaches only immutable values: every reachable table refuses assignment and hands out no table",
     t_only_immutable)


def t_counting_gate():
    def counting():
        s = Stream()

        def count(e):
            most = 0
            for t in reach([e]).values():
                try:
                    n = object.__getattribute__(t, "sisoProbe") + 1
                except Exception:
                    n = 1
                try:
                    object.__setattr__(t, "sisoProbe", n)  # Python's rawset
                except Exception:
                    pass
                most = max(most, n)
            return Event("fx_counted", {"n": most})

        s.register(Gate("fx_count", count))
        return s

    def run(s):
        s.emit(Event("fx_count", {"a": {"b": [1]}, "l": [1, 2]}))
        return s.takeResidue()[0].data["n"]

    s = counting()
    assertEqual(run(s), 1, "first propagation")
    assertEqual(run(s), 1, "a second propagation of the same stream")
    assertEqual(run(counting()), 1, "another stream")

    # the class form of rawset bypasses the lock: the named SUBSTRATE
    # convention, pinned so a change is seen (written and removed at once)
    type.__setattr__(Event, "sisoProbe", 1)
    try:
        assertEqual(Event("x").sisoProbe, 1, "type.__setattr__ writes a locked class (named convention)")
    finally:
        type.__delattr__(Event, "sisoProbe")
    assert_(not hasattr(Event, "sisoProbe"), "the probe was removed")


test("a gate writing (rawset included) into everything it can reach counts nothing across propagations or streams",
     t_counting_gate)

# ── no ABC registry is reachable from an event (Python, 13.2.0) ──


def classesFrom(e):
    """Every class reached from e by type() and by non-dunder class attributes."""
    out = {}
    stack = [type(x) for x in reach([e]).values()] + [type(e)]
    while stack:
        c = stack.pop()
        if id(c) in out or issubclass(c, type):
            continue
        out[id(c)] = c
        for n in dir(c):
            if not n.startswith("__"):
                try:
                    v = getattr(c, n)
                except Exception:
                    continue
                stack.append(v if isinstance(v, type) else type(v))
    return list(out.values())


def t_no_abc():
    e = Event("t", {"m": {"k": [1]}, "inner": Event("i")})
    for c in classesFrom(e):
        assert_(not isinstance(c, abc.ABCMeta), "a class reachable from an event is an ABC: " + c.__name__)
    FD = type(e.data)
    assert_(collections.abc.Mapping not in FD.__mro__, "FrozenDict inherits nothing from collections.abc.Mapping")
    assert_(isinstance(e.data, collections.abc.Mapping), "FrozenDict is registered with Mapping: isinstance holds")
    assert_(e.data == {"m": {"k": (1,)}, "inner": e.data["inner"]} and e.data.get("zz", 0) == 0
            and sorted(e.data.keys()) == ["inner", "m"] and len(list(e.data.values())) == 2
            and dict(e.data.items())["m"] is e.data["m"], "the Mapping protocol reads as a Mapping's")
    for name in ("register", "_abc_registry_clear"):
        assert_(not hasattr(FD, name), "FrozenDict has no " + name)
    try:
        super(type(FD), FD).register(int)
        H.fail("super(type(T), T).register was accepted")
    except AttributeError:
        pass
    assert_(not issubclass(int, FD), "nothing was registered with FrozenDict")


test("no class reachable from an event is an ABC; FrozenDict is a Mapping by registration", t_no_abc)


def t_abc_channel():
    probes = tuple(type("Probe" + str(i), (), {}) for i in range(4))

    def counting():
        s = Stream()

        def count(e):
            n = 0
            for c in classesFrom(e):
                k = sum(1 for x in probes if issubclass(x, c))
                for reg in (lambda c=c: c.register, lambda c=c: super(type(c), c).register,
                            lambda c=c: abc.ABCMeta.register.__get__(c)):
                    try:
                        reg()(probes[min(k, len(probes) - 1)])
                        n += k + 1
                        break
                    except Exception:
                        pass
            return Event("fx_counted", {"n": n})

        s.register(Gate("fx_count", count))
        return s

    def run(s):
        s.emit(Event("fx_count", {"a": {"b": [1]}}))
        return s.takeResidue()[0].data["n"]

    got = [run(counting()) for _ in range(3)]
    assertEqual(got, [0, 0, 0], "a gate registering through what its event reaches counted across three streams")


test("a gate registering classes through what its event reaches carries no count across streams", t_abc_channel)

# ── the storage a FrozenDict exposes (the captured key's counterpart) ──


def t_iteration():
    e = Event("t", {"a": 1})
    it = iter(e.data)
    assertEqual(type(it).__module__, "builtins", "iteration hands out a builtin iterator")
    assertEqual([n for n in dir(it) if not n.startswith("__")], [], "the iterator exposes nothing")
    assertEqual(next(it), "a")
    try:
        setattr(it, "x", 1)
        H.fail("the iterator accepts an attribute")
    except (AttributeError, TypeError):
        pass


test("pairs hands out the frozen table itself as its state; the step reads its own proxy", t_iteration)


def t_storage():
    e = Event("t", {"v": 1, "list": [1]})
    store = e.data._m
    assert_(type(store) is types.MappingProxyType, "the storage reachable is a read-only MappingProxyType")
    H.assertThrows(lambda: setattr(store, "x", 1), "", "an attribute on the storage")
    try:
        store["v"] = 2
        H.fail("a write through the storage was accepted")
    except TypeError:
        pass
    assertEqual(e.data["v"], 1, "the data is unchanged")
    other = Event("t", {"v": 1, "list": [1]}).data._m
    assertEqual(overlap(reach([store]), reach([other])), 0, "two FrozenDicts share no storage")


test("a captured key is a function that holds no field; the view it reaches answers false", t_storage)


def t_storage_writes():
    def counting():
        s = Stream()

        def count(e):
            most = 1
            for t in reach([e]).values():
                if type(t) is types.MappingProxyType:
                    try:
                        t["sisoProbe"] = 1
                        most = 2
                    except TypeError:
                        pass
            return Event("fx_counted", {"n": most})

        s.register(Gate("fx_count", count))
        return s

    def run(s):
        s.emit(Event("fx_count", {"a": {"b": [1]}, "l": [1, 2]}))
        return s.takeResidue()[0].data["n"]

    s = counting()
    assertEqual(run(s), 1, "first propagation")
    assertEqual(run(s), 1, "a second propagation of the same stream")
    assertEqual(run(counting()), 1, "another stream")

    # the outside, through the storage: a sample's and a record's
    t = Stream()
    batches = []
    t.attach(lambda b: batches.append(b))
    t.attach(lambda b: batches.append(b))
    t.emit(Event("fx_kept", {"v": 1}))
    sampled = t.sampleHere()["residue"][0]
    for store in (sampled.data._m, batches[0]["records"][0]["data"]._m):
        try:
            store["v"] = 9
            H.fail("a write through a storage was accepted")
        except TypeError:
            pass
    assertEqual(t.sampleHere()["residue"][0].data["v"], 1, "a write through a sample's storage reached the stream")
    assertEqual(batches[1]["records"][0]["data"]["v"], 1, "a write through one observer's storage reached another")
    assertEqual(t.takeResidue()[0].data["v"], 1, "a write through a storage reached the residue taken")


test("nothing written through a captured key reaches another propagation, stream, sample or observer", t_storage_writes)

# ── immutable sentinels ────────────────────────


class NullError(Exception):
    def __init__(self, n):
        super().__init__("null")
        self.n = n


def t_none_doors():
    s = Stream()
    got = {}

    def null(e):
        got["inGate"] = e.data["n"]
        return Event("fx_out", {"n": e.data["n"], "l": [None]})

    def throw(e):
        raise NullError(e.data["n"])

    s.register(Gate("fx_null", null))
    s.register(Gate("fx_throw", throw))
    batches = []
    s.attach(lambda b: batches.append(b))
    s.emit(Event("fx_null", {"n": None}))
    try:
        s.emit(Event("fx_throw", {"n": None}))
        H.fail("settled")
    except Exception as err:
        raised = err
    assert_(got["inGate"] is None, "a gate is given None itself, through the entry copy")
    sampled = s.sampleHere()["residue"][0]
    assert_(sampled.data["n"] is None and sampled.data["l"][0] is None, "a sample keeps it")
    assert_(batches[0]["records"][1]["data"]["n"] is None, "an observer's record keeps it")
    assert_(batches[1]["rejection"]["cause"]["n"] is None, "an observer's rejection keeps it")
    assert_(raised.cause.n is None, "the host's rejection keeps it")
    taken = s.takeResidue()[0]
    assert_(taken.data["n"] is None, "a take keeps it")
    bare = Event("fx_bare", None).data
    assert_(type(bare) is FrozenDict and len(bare) == 0, "as the data itself: an empty FrozenDict")

    # a writable object in a cause: the host's rejection carries what the
    # gate raised; the observer's copy carries its own frozen tuple
    old = [1]
    t = Stream()
    t.register(Gate("fx_old", raiser(NullError(old))))
    seen = []
    t.attach(lambda b: seen.append(b["rejection"]))
    try:
        t.emit(Event("fx_old"))
    except Exception as err:
        r2 = err
    assert_(r2.cause.n is old, "the host's rejection carries what the gate raised")
    assert_(seen[0]["cause"]["n"] is not old and type(seen[0]["cause"]["n"]) is tuple,
            "the observer's copy does not carry the writable list")
    why = SEAM.rejectionCopyProblem(seen[0], r2, Event)
    assert_(why is None, str(why))


test("a \"readonly:\" sentinel keeps its identity through every door; the core 12 form is not handed to observers",
     t_none_doors)

# ── the rejection an observer is shown ─────────


class Err(Exception):
    kind = "io"

    def describe(self):
        return "d"

    def __str__(self):
        return "E"


def t_plain_copy():
    cause = Err()
    cause.msg = "m"
    cause.nested = [1, [2]]
    s = Stream()
    s.register(Gate("fx_err", raiser(cause)))
    copies = []
    s.attach(lambda b: copies.append(b["rejection"]))
    s.attach(lambda b: copies.append(b["rejection"]))
    try:
        s.emit(Event("fx_err"))
        H.fail("settled")
    except Exception as err:
        raised = err
    assertEqual(len(copies), 2)
    for i, c in enumerate(copies, 1):
        where = "copy " + str(i)
        why = SEAM.rejectionCopyProblem(c, raised, Event)
        assert_(why is None, where + ": " + str(why))
        assertEqual(c["cause"]["msg"], "m", where + ": the cause's own field")
        assertEqual(c["cause"].get("kind"), None, where + ": an inherited field is not carried")
        assertEqual(c["cause"].get("describe"), None, where + ": a method is not carried")
        assertEqual(c["cause"]["nested"][1][0], 2)
        assertEqual(c["message"], raised.message, where + ": the message carries the rendering")
        for t in reach([c]).values():
            assert_(type(t) in (FrozenDict, tuple, types.MappingProxyType),
                    where + ": an object in it is a " + type(t).__name__)
        assertEqual(overlap(reach([c]), reach([raised, cause])), 0, where + " shares an object with the raised rejection")
    assertEqual(overlap(reach([copies[0]]), reach([copies[1]])), 0, "the two observers' copies share an object")
    assertEqual(str(cause), "E", "the gate's error object is untouched")
    assertEqual(cause.kind, "io")


test("an observer's rejection is plain at every depth: own fields only, no metatable, nothing shared", t_plain_copy)

H.finish("stream-stateless")
