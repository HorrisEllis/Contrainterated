# contract test: observer-seam (py/code/siso/contracts/observer_seam.py).
# Port of lua/sisoTests/contract-observer_seam.test.lua. Shows the
# contract's checks pass on an implementation that honours it and catch
# 11.1's observer (called during the propagation) and the other departures
# the contract rules out, the Python ones included.
# Runnable from any directory: python3 py/sisoTests/contract-observer_seam.test.py
import sys
sys.dont_write_bytecode = True  # no __pycache__ in the project tree

import importlib.util
import os
import sys
import types

HERE = os.path.dirname(os.path.abspath(__file__))
sys.path.insert(0, os.path.normpath(os.path.join(HERE, "..", "code")))

from siso import harness as H  # noqa: E402
from siso.contracts import observer_seam as S  # noqa: E402


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


def t_seam():
    p = S.checkSeam(fake.new())
    H.assert_(not p, joined(p))


def t_ownership():
    p = S.checkOwnership(fake.new())
    H.assert_(not p, joined(p))


H.test("the seam holds on an implementation that honours it", t_seam)
H.test("ownership holds on an implementation that honours it", t_ownership)
H.test("11.1: an observer called during the propagation is caught",
       lambda: caught(S.checkSeam, "observeDuring", "crossings reached the propagation", "batches"))
H.test("11.1: an observer's raise escaping emit is caught",
       lambda: caught(S.checkSeam, "unprotected", "raising observer"))
H.test("one batch shared by every observer is caught",
       lambda: caught(S.checkSeam, "sharedBatch", "reached another observer"))
H.test("11.1 R1: ids counted across propagations break the batch",
       lambda: caught(S.checkSeam, "countIds", "eventId is"))
H.test("12.0 X6: records that share the residue's frozen data are caught",
       lambda: caught(S.checkOwnership, "shareFrozen", "reached the residue", "another observer"))
H.test("11.1 R3: writable record data is caught",
       lambda: caught(S.checkOwnership, "noFreeze", "record data accepts assignment",
                      "took the residue and wrote to it"))


def t_shared():
    f = fake.new("sharedRejection")
    p = S.checkSeam(f)
    H.assert_(has(p, "very object raised"), joined(p))
    p = S.checkOwnership(f)
    H.assert_(has(p, "reached the rejection emit raised"), joined(p))


H.test("12.0 C-2: observers shown the very rejection raised are caught", t_shared)


def t_rich():
    f = fake.new("richRejection")
    p = S.checkSeam(f)
    H.assert_(has(p, "object of class"), joined(p))
    p = S.checkOwnership(f)
    H.assert_(has(p, "through its copy's classes"), joined(p))


H.test("13.0.0-rc1: a rejection copy made of the host's and the gate's classes is caught", t_rich)


def t_shallow():
    f = fake.new("shallowRejection")
    p = S.checkSeam(f)
    H.assert_(has(p, "raised object itself"), joined(p))
    p = S.checkOwnership(f)
    H.assert_(has(p, "reached what the gate raised"), joined(p))


H.test("Python: a rejection copy holding the very exception the gate raised is caught", t_shallow)
H.test("PHP review, in Python: observers called from a helper that takes them as arguments are caught",
       lambda: caught(S.checkOwnership, "frameArgs", "host's rejection among the arguments",
                      "observers list among the arguments"))
H.test("Python: an observer's process-level exit swallowed is caught",
       lambda: caught(S.checkSeam, "catchBase", "raised by an observer did not pass through"))


H.test("13.2.0 review: a cause read once per observer (the gate's code counts them) is caught",
       lambda: caught(S.checkOwnership, "copyPerObserver", "ran a different number of times"))


def t_untargeted():
    for sw in ("unprotected", "countIds", "catchBase"):
        p = S.checkOwnership(fake.new(sw))
        H.assert_(not p, sw + ": " + joined(p))
    for sw in ("shareFrozen", "frameArgs", "noFreeze", "copyPerObserver"):
        p = S.checkSeam(fake.new(sw))
        H.assert_(not p, sw + ": " + joined(p))


H.test("each switch leaves the checks it does not target passing", t_untargeted)


class Rejection(Exception):
    def __init__(self, code, message, eventId=None, causeId=None, eventType=None, depth=None, cause=None):
        super().__init__(message)
        self.code, self.message, self.eventId, self.causeId = code, message, eventId, causeId
        self.eventType, self.depth, self.cause = eventType, depth, cause


class Err(Exception):
    def __init__(self):
        super().__init__("x")
        self.first = "x"
        self.n = [1]


def t_copyProblem():
    ro = types.MappingProxyType
    raised = Rejection("SISO_GATE_THREW", "m", 1, None, "a", None, Err())

    def copyWith(**changes):
        base = {"code": "SISO_GATE_THREW", "message": "m", "eventId": 1, "eventType": "a",
                "cause": ro({"first": "x", "n": (1,)})}
        base.update(changes)
        return ro({k: v for k, v in base.items() if v is not None})

    H.assertEqual(S.rejectionCopyProblem(copyWith(), raised), None)
    cases = [
        ("the very object", raised, "very object"),
        ("an unequal field", copyWith(eventId=2), "eventId"),
        ("an unequal cause", copyWith(cause=ro({"first": "x", "n": (2,)})), "cause"),
        ("an added field", copyWith(depth=3), "lacks"),
        ("a missing field", ro({"code": "SISO_GATE_THREW", "message": "m", "eventType": "a",
                                 "cause": ro({"first": "x", "n": (1,)})}), "eventId"),
        ("a writable copy", {"code": "SISO_GATE_THREW", "message": "m", "eventId": 1, "eventType": "a",
                             "cause": ro({"first": "x", "n": (1,)})}, "accepts assignment"),
        ("a writable cause", copyWith(cause={"first": "x", "n": (1,)}), "accepts assignment"),
        ("a list in the copy", copyWith(cause=ro({"first": "x", "n": [1]})), "is a list"),
        ("a copy sharing the raised cause", copyWith(cause=raised.cause), "raised object itself"),
        ("a copy sharing the raised cause's list", copyWith(cause=ro({"first": "x", "n": raised.cause.n})),
         "raised object itself"),
        ("a copy of the host's class", Rejection("SISO_GATE_THREW", "m", 1, None, "a", None, None), "object of class"),
        ("a cause of the gate's class", copyWith(cause=Err()), "object of class Err"),
    ]
    for label, copy, needle in cases:
        why = S.rejectionCopyProblem(copy, raised)
        H.assert_(isinstance(why, str) and needle in why, label + ": " + repr(why))
    # a cycle in the cause becomes None in the copy
    cyc = Err()
    cyc.self = cyc
    raisedC = Rejection("SISO_GATE_THREW", "m", 1, None, "a", None, cyc)
    good = copyWith(cause=ro({"first": "x", "n": (1,), "self": None}))
    H.assertEqual(S.rejectionCopyProblem(good, raisedC), None, "a cycle copied as None")
    # with the Event class given, a fresh Event in the copy is plain; the raised one is not
    E = fake.new()["Event"]
    inner = E("in", {"w": 1})
    holder = Err()
    holder.e = inner
    raisedE = Rejection("SISO_GATE_THREW", "m", 1, None, "a", None, holder)
    fresh = copyWith(cause=ro({"first": "x", "n": (1,), "e": E("in", {"w": 1})}))
    H.assertEqual(S.rejectionCopyProblem(fresh, raisedE, E), None, "a fresh Event in the copy")
    sharing = copyWith(cause=ro({"first": "x", "n": (1,), "e": inner}))
    why = S.rejectionCopyProblem(sharing, raisedE, E)
    H.assert_(isinstance(why, str) and "raised object itself" in why, "the raised Event in the copy: " + repr(why))
    why = S.rejectionCopyProblem(fresh, raisedE)
    H.assert_(isinstance(why, str) and "object of class" in why, "an Event without the Event class given: " + repr(why))


H.test("rejectionCopyProblem: a copy, the same object, a changed field", t_copyProblem)


def rec(eventId, causeId, claimed, **extra):
    r = {"eventId": eventId, "causeId": causeId, "eventType": "t", "claimed": claimed, "data": {}}
    r.update(extra)
    return r


def t_batchGood():
    b = {"outcome": "settled", "rejection": None, "records": [rec(1, None, True), rec(2, 1, False)]}
    p = S.batchProblems(b)
    H.assert_(not p, joined(p))
    r = {"outcome": "rejected", "rejection": types.MappingProxyType({"code": "SISO_CYCLE", "eventId": 2}),
         "records": b["records"]}
    q = S.batchProblems(r)
    H.assert_(not q, joined(q))


H.test("batchProblems: a consistent batch passes", t_batchGood)


def t_batchBad():
    cases = [
        ("a gap in ids", {"outcome": "settled", "rejection": None, "records": [rec(1, None, True), rec(3, 1, False)]},
         "eventId is 3"),
        ("a cause not claimed", {"outcome": "settled", "rejection": None, "records": [rec(1, None, False), rec(2, 1, False)]},
         "not claimed"),
        ("an entry with a cause", {"outcome": "settled", "rejection": None, "records": [rec(1, 1, True)]}, "has a cause"),
        ("a rejected batch without its rejection", {"outcome": "rejected", "rejection": None, "records": []},
         "carries no rejection"),
        ("a settled batch with a rejection", {"outcome": "settled", "rejection": {"code": "SISO_CYCLE"}, "records": []},
         "settled batch carries a rejection"),
        ("records past the rejection", {"outcome": "rejected", "rejection": {"eventId": 2},
                                        "records": [rec(1, None, True)]}, "do not end"),
        ("a refused entry with records", {"outcome": "rejected", "rejection": {"code": "SISO_NOT_EVENT"},
                                          "records": [rec(1, None, True)]}, "refused entry has records"),
        ("an extra field", {"outcome": "settled", "rejection": None, "records": [], "extra": 1}, "unexpected field"),
        ("a missing field", {"outcome": "settled", "records": []}, "missing field 'rejection'"),
        ("records not a list", {"outcome": "settled", "rejection": None, "records": (rec(1, None, True),)},
         "records is not a list"),
        ("a record with an extra field", {"outcome": "settled", "rejection": None,
                                          "records": [rec(1, None, True, extra=1)]}, "record 1: unexpected field"),
        ("data not a mapping", {"outcome": "settled", "rejection": None, "records": [rec(1, None, True, data="d")]},
         "data is not a mapping"),
        ("a bool id", {"outcome": "settled", "rejection": None, "records": [rec(True, None, True)]}, "eventId is True"),
        ("claimed not a bool", {"outcome": "settled", "rejection": None, "records": [rec(1, None, 1)]},
         "claimed is not a bool"),
        ("an unknown outcome", {"outcome": "done", "rejection": None, "records": []}, "outcome is 'done'"),
        ("not a dict", None, "a batch is a dict"),
    ]
    for label, b, needle in cases:
        p = S.batchProblems(b)
        H.assert_(has(p, needle), label + ": " + joined(p))


H.test("batchProblems: inconsistencies are named", t_batchBad)

H.finish("contract observer-seam")
