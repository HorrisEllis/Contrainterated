# contract test: core-api (py/code/siso/contracts/core_api.py).
# Port of lua/sisoTests/contract-core_api.test.lua. Shows the contract's
# checks pass on an implementation that honours it and catch each
# departure the reference's history names, plus the Python ones. The core
# itself is checked against the contract by the stream phases' tests.
# Runnable from any directory: python3 py/sisoTests/contract-core_api.test.py
import sys
sys.dont_write_bytecode = True  # no __pycache__ in the project tree

import abc
import collections.abc
import importlib.util
import os
import sys
import warnings

HERE = os.path.dirname(os.path.abspath(__file__))
sys.path.insert(0, os.path.normpath(os.path.join(HERE, "..", "code")))

from siso import harness as H  # noqa: E402
from siso.contracts import core_api as C  # noqa: E402


def loadFake():
    spec = importlib.util.spec_from_file_location("contract_fake_core", os.path.join(HERE, "contract-fake-core.py"))
    mod = importlib.util.module_from_spec(spec)
    spec.loader.exec_module(mod)
    return mod


fake = loadFake()


def joined(lst):
    return "; ".join(lst)


def has(lst, needle):
    return any(needle in s for s in lst)


def caught(check, switch, *needles):
    p = check(fake.new(switch))
    for n in needles:
        H.assert_(has(p, n), switch + ": no problem names " + repr(n) + ": " + joined(p))


# a rejection as a core builds one: an Exception named Rejection whose
# fields are its own attributes
class Rejection(Exception):
    def __init__(self, code, message, eventId=None, causeId=None, eventType=None, depth=None, cause=None, **extra):
        super().__init__(message)
        self.code = code
        self.message = message
        self.eventId = eventId
        self.causeId = causeId
        self.eventType = eventType
        self.depth = depth
        self.cause = cause
        for k, v in extra.items():
            setattr(self, k, v)


def t_doors():
    p = C.checkDoors(fake.new())
    H.assert_(not p, joined(p))


def t_readonly():
    p = C.checkReadonly(fake.new())
    H.assert_(not p, joined(p))


def t_ownership():
    p = C.checkOwnership(fake.new())
    H.assert_(not p, joined(p))


H.test("doors hold on an implementation that honours the contract", t_doors)
H.test("read-only holds on an implementation that honours the contract", t_readonly)
H.test("ownership holds on an implementation that honours the contract", t_ownership)

H.test("11.1 X1: skipping non-Event outputs is caught",
       lambda: caught(C.checkDoors, "skipNonEvents", "output not an Event"))
H.test("11.1: an entry door that takes any value is caught",
       lambda: caught(C.checkDoors, "acceptAnyEntry", "entry not an Event"))
H.test("11.1 R1: ids counted across propagations are caught",
       lambda: caught(C.checkDoors, "countIds", "gate threw, again"))
H.test("11.1 R1: an event counter in the sample is caught",
       lambda: caught(C.checkDoors, "sampleCount", "sampleHere carries"))
H.test("11.1 R3: writable events are caught",
       lambda: caught(C.checkReadonly, "noFreeze", "was accepted"))
H.test("12.0: an event writable until it enters the stream is caught",
       lambda: caught(C.checkReadonly, "writableAtBirth", "a new event", "passed to Event reached the event"))
H.test("12.0.1 and 13.0.0-rc1: an unlocked Event class (a state channel) is caught",
       lambda: caught(C.checkReadonly, "tableMeta", "is not locked", "carried a count"))
H.test("13.0.0-rc1: an entry that keeps the entering code's own event is caught",
       lambda: caught(C.checkOwnership, "entryAlias", "entering code's own event",
                      "carried a count from one emit to the next"))
H.test("12.0 X6: samples that share the stream's objects are caught",
       lambda: caught(C.checkOwnership, "shareFrozen", "reached the stream"))
H.test("Python X1: an Event.isEvent that answers by attributes is caught",
       lambda: caught(C.checkDoors, "duckEvent", "Event.isEvent accepts", "entry a look-alike"))
H.test("Python: data that is not plain data taken into an event is caught",
       lambda: caught(C.checkReadonly, "anyData", "Event accepted a set in data", "Event accepted a function in data",
                      "Event accepted a dict subclass in data"))
H.test("Python Drift 7: odd keys dropped instead of refused are caught",
       lambda: caught(C.checkReadonly, "dropOddKeys", "it was dropped"))
H.test("Python R5: a stream whose state is on instance attributes is caught",
       lambda: caught(C.checkDoors, "openStream", "besides its doors"))
H.test("Python: a stream instance that accepts assignment is caught",
       lambda: caught(C.checkDoors, "shadowableStream", "can be shadowed"))
H.test("Python: core exports left unlocked are caught",
       lambda: caught(C.checkReadonly, "unlockedExports", "Event.isEvent is a function", "Stream is not locked",
                      "a gate's default transform is a function"))
H.test("Python: an unlocked rejection is caught",
       lambda: caught(C.checkReadonly, "openRejection", "assigning a rejection's field was accepted",
                      "type(rejection) is not locked"))
H.test("Python: a frozen mapping that keeps a dict under an ordinary name is caught",
       lambda: caught(C.checkReadonly, "openStorage", "_store is a dict"))
H.test("Python: an ABC registry left open (a state channel) is caught",
       lambda: caught(C.checkReadonly, "abcRegister", "carried a count"))
H.test("13.2.0 review: a FrozenDict that is an ABC (registry reached past a locked register) is caught",
       lambda: caught(C.checkReadonly, "abcData", "carried a count"))
H.test("Python X1: a list subclass read through its own __iter__ is caught",
       lambda: caught(C.checkDoors, "listSubclass", "list subclass whose __iter__ lies"))
H.test("Python Drift 7: a coroutine taken as consumed is caught",
       lambda: caught(C.checkDoors, "acceptCoroutine", "output a coroutine"))
H.test("Python: a returned coroutine left unclosed is caught",
       lambda: caught(C.checkDoors, "unclosedCoroutine", "did not close it"))
H.test("Python X1: a gate's exception rendered outside protection is caught",
       lambda: caught(C.checkDoors, "unsafeMessage", "a gate's exception whose __str__ raises"))
H.test("Python: a process-level exit turned into a rejection is caught",
       lambda: caught(C.checkDoors, "catchBase", "a process-level exit raised by a gate"))
H.test("Python: an entry copy that recurses is caught",
       lambda: caught(C.checkOwnership, "recursiveEntry", "made emit raise RecursionError"))


def t_untargeted():
    for sw in ("tableMeta", "entryAlias", "shareFrozen", "anyData", "dropOddKeys", "openStorage", "abcRegister",
               "openRejection", "abcData"):
        p = C.checkDoors(fake.new(sw))
        H.assert_(not p, sw + ": " + joined(p))
    for sw in ("skipNonEvents", "countIds", "sampleCount", "listSubclass", "acceptCoroutine", "unsafeMessage",
               "catchBase", "duckEvent"):
        p = C.checkReadonly(fake.new(sw))
        H.assert_(not p, sw + ": " + joined(p))
    for sw in ("tableMeta",):
        p = C.checkOwnership(fake.new(sw))
        H.assert_(not p, sw + ": " + joined(p))


H.test("each switch leaves the checks it does not target passing", t_untargeted)


def t_noGlobalState():
    hook = sys.unraisablehook
    filters = list(warnings.filters)
    limit = sys.getrecursionlimit()
    mapping = sorted(vars(collections.abc.Mapping))
    C.checkOwnership(fake.new())
    for sw in (None, "tableMeta", "abcRegister", "unlockedExports", "openRejection", "noFreeze", "richRejection"):
        core = fake.new(sw)
        C.checkDoors(core)
        C.checkReadonly(core)
        E = core["Event"]
        H.assert_(not hasattr(E, "sisoContractCount"), str(sw) + ": a count was left on the Event class")
        if sw == "abcRegister":
            FD = type(E("t").data)
            H.assertEqual(len(abc._get_dump(FD)[0]), 0, "abcRegister: a registration was left on FrozenDict")
    H.assert_(sys.unraisablehook is hook, "sys.unraisablehook was not restored")
    H.assert_(list(warnings.filters) == filters, "warnings.filters were not restored")
    H.assertEqual(sys.getrecursionlimit(), limit, "the recursion limit")
    H.assertEqual(sorted(vars(collections.abc.Mapping)), mapping, "collections.abc.Mapping")


H.test("no check leaves global state changed", t_noGlobalState)


def t_wellFormed():
    r = Rejection("SISO_CYCLE", "m", 3, 2, "a", 3)
    p = C.rejectionProblems(r, {"code": "SISO_CYCLE", "depth": 3})
    H.assert_(not p, joined(p))
    exc = RuntimeError("x")
    q = C.rejectionProblems(Rejection("SISO_GATE_THREW", "m", 1, None, "a", None, exc), {"cause": exc, "entry": True})
    H.assert_(not q, joined(q))
    s = C.rejectionProblems(Rejection("SISO_NOT_EVENT", "m"), {"noDelivery": True, "causeId": None})
    H.assert_(not s, joined(s))


H.test("rejectionProblems: a well-formed rejection passes", t_wellFormed)


def t_shapeErrors():
    class Other(Rejection):
        pass

    class Rendered(Exception):
        pass

    Rendered.__name__ = "Rejection"

    def rendered():
        r = Rendered("not the message")
        r.code, r.message, r.eventId, r.causeId, r.eventType, r.depth, r.cause = "SISO_SELF_LOOP", "m", 1, None, "a", None, None
        return r

    class Missing(Exception):
        def __init__(self):
            super().__init__("m")
            self.code = "SISO_SELF_LOOP"
            self.message = "m"

    Missing.__name__ = "Rejection"
    cases = [
        ("a class not named Rejection", Other("SISO_SELF_LOOP", "m", 1, None, "a"), None, "class is Other"),
        ("str is not the message", rendered(), None, "str(rejection) is not its message"),
        ("unknown code", Rejection("X", "m"), None, "unknown code"),
        ("an extra field", Rejection("SISO_SELF_LOOP", "m", extra=1), None, "unexpected field 'extra'"),
        ("a missing field", Missing(), None, "missing field 'eventId'"),
        ("depth off a cycle", Rejection("SISO_SELF_LOOP", "m", depth=2), None, "only by SISO_CYCLE"),
        ("a cycle without a depth", Rejection("SISO_CYCLE", "m", 1), None, "integer depth"),
        ("a refused entry with an id", Rejection("SISO_NOT_EVENT", "m", 1), {"noDelivery": True}, "names no delivery"),
        ("an entry with a cause", Rejection("SISO_SELF_LOOP", "m", 1, 1), {"entry": True}, "has no cause"),
        ("a cause that is not an exception", Rejection("SISO_GATE_THREW", "m", 1, None, "a", None, "boom"), None,
         "carries the exception"),
        ("a bool id", Rejection("SISO_SELF_LOOP", "m", True), {"eventId": 1}, "eventId is not an int"),
        ("True pinned as 1", Rejection("SISO_SELF_LOOP", "m", True), {"eventId": 1}, "eventId: expected 1, got True"),
        ("a pinned field", Rejection("SISO_CYCLE", "m", 3, 2, "a", 3), {"eventId": 2}, "eventId: expected 2, got 3"),
        ("a pinned cause by identity", Rejection("SISO_GATE_THREW", "m", 1, None, "a", None, RuntimeError("x")),
         {"cause": RuntimeError("x")}, "cause: expected"),
        ("a str", "boom", None, "is an exception"),
        ("a process-level exit", SystemExit("x"), None, "not an Exception"),
    ]
    for label, r, want, needle in cases:
        H.assert_(has(C.rejectionProblems(r, want), needle), label + ": " + joined(C.rejectionProblems(r, want)))


H.test("rejectionProblems: shape errors are named", t_shapeErrors)

H.finish("contract core-api")
