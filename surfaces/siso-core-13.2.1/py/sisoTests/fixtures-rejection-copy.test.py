# fixtures-rejection-copy.test.py -- the runner
# (py/code/siso/fixtures/run.py) judges each batch's rejection with
# py-observer-seam's rejectionCopyProblem, not by identity (core 13, THE
# REJECTION). Port of lua/sisoTests/fixtures-rejection-copy.test.lua,
# case by case.
#   python3 -B py/sisoTests/fixtures-rejection-copy.test.py   (from the project root, or anywhere)
#
# It must hold whatever the core in the workspace shows its observers, so
# the fixtures run on a test-local wrapper over siso.core's Stream (handed
# to the runner as core["Stream"]) that decides what its observers are
# shown: it collects the real stream's batches, lets emit return or raise,
# then hands each wrapped observer the batch with a rejection built from
# the one raised (a frozen plain copy, the raised object itself, or a
# faulty copy), and re-raises the same object. The copy it shows is plain,
# as THE REJECTION requires since core 13.0.0: a FrozenDict (the core's
# frozen mapping) of the raised rejection's fields that are not None, its
# `cause` a FrozenDict of the exception's own attributes (vars), sequences
# as tuples, and nothing in it but primitives, tuples and FrozenDicts.
#
# Turned into Python's own counterpart (py-observer-seam SUBSTRATE):
#   - Lua's read-only proxy (__newindex raising, __metatable = false)
#     becomes the core's FrozenDict; "getmetatable answers false on the
#     copy and on every table in it" becomes "the copy is a FrozenDict and
#     nothing in it is anything but a primitive, a tuple or a FrozenDict";
#     "assignment raises" becomes "item assignment raises" (TypeError).
#   - Lua's "readonly:" sentinels (data.null) pass through as themselves:
#     there are none here (JSON null is None, a primitive).
#   - "a copy that accepts assignment": the copy left writable is a plain
#     dict at every depth (lists for sequences), and nothing else differs.
#   - "a batch carrying the raised table itself": an exception is not a
#     mapping, so besides rejectionCopyProblem's "very object raised",
#     observer-seam's batchProblems also names the batch ("a rejected
#     batch carries no rejection"); both are the same fault, and both are
#     allowed (as the PHP port does). Lua's needle "very table raised" is
#     "very object raised" in the Python contract's wording; "differs from
#     the one raised at 'message'" and "accepts assignment" are the same.
# No case is left out.
import sys

sys.dont_write_bytecode = True

import collections.abc  # noqa: E402
import os  # noqa: E402

HERE = os.path.dirname(os.path.abspath(__file__))
ROOT = os.path.normpath(os.path.join(HERE, "..", ".."))
sys.path.insert(0, os.path.join(ROOT, "py", "code"))

from siso import harness as H  # noqa: E402
from siso.contracts.observer_seam import REJECTION_FIELDS  # noqa: E402
from siso.core.freeze import FrozenDict  # noqa: E402
from siso.core.stream import Stream  # noqa: E402
from siso.fixtures.run import run  # noqa: E402
from siso.kit import data  # noqa: E402

test, assert_, assertEqual = H.test, H.assert_, H.assertEqual

_PRIMITIVES = (type(None), bool, int, float, str)


def joined(lst):
    return "; ".join(lst)


# ── what an observer may be shown ───────────────────────────────────

def plain(v):
    """A value as plain data (THE REJECTION): primitives as they are, a
    mapping as a dict of its items, a sequence as a list, any other object
    (an exception) as a dict of its own attributes."""
    if type(v) in _PRIMITIVES:
        return v
    if isinstance(v, collections.abc.Mapping):
        return {k if type(k) in _PRIMITIVES else repr(k): plain(x) for k, x in v.items()}
    if isinstance(v, (list, tuple)):
        return [plain(x) for x in v]
    try:
        own = vars(v)
    except TypeError:
        own = {}
    return {k: plain(x) for k, x in own.items()}


def fields(raised):
    """The raised rejection's fields that are not None, as plain data."""
    out = {}
    for k in REJECTION_FIELDS:
        v = getattr(raised, k, None)
        if v is not None:
            out[k] = plain(v)
    return out


def frozen(d):
    """A plain dict, frozen at every depth: FrozenDicts and tuples."""
    return FrozenDict(d)


def readOnly(raised):
    return frozen(fields(raised))


def changedMessage(raised):
    c = fields(raised)
    c["message"] = str(c.get("message")) + " (changed)"
    return frozen(c)


def writable(raised):
    return fields(raised)


shows = {
    "copy": readOnly,
    "itself": lambda raised: raised,
    "changedMessage": changedMessage,
    "writable": writable,
}


def wrapped(show, seen=None):
    """A Stream factory over siso.core's Stream whose observers are shown
    show(raised) as the rejection; `seen` collects every rejection shown."""

    class Wrapped:
        def __init__(self):
            self._real = Stream()
            self._pending = []
            self._observers = []
            self._real.attach(self._pending.append)

        def register(self, g):
            return self._real.register(g)

        def takeResidue(self):
            return self._real.takeResidue()

        def sampleHere(self):
            return self._real.sampleHere()

        def attach(self, f):
            self._observers.append(f)

        def detach(self, f):
            for i, g in enumerate(self._observers):
                if g is f:
                    del self._observers[i]
                    return
            raise ValueError("not attached")

        def emit(self, e):
            del self._pending[:]
            err = None
            try:
                self._real.emit(e)
            except Exception as x:
                err = x
            for b in self._pending:
                for f in self._observers:
                    shown = {"outcome": b["outcome"], "rejection": None, "records": b["records"]}
                    if b["outcome"] == "rejected":
                        shown["rejection"] = show(err)
                        if seen is not None:
                            seen.append({"shown": shown["rejection"], "raised": err})
                    f(shown)
            if err is not None:
                raise err

    return Wrapped


# ── the suite on disk ───────────────────────────────────────────────

DIR = os.path.join(ROOT, "fixtures")
try:
    _names = os.listdir(DIR)
except OSError:
    _names = []
files = sorted((n for n in _names if n.endswith(".json")), key=os.fsencode)
_decoded = {}


def load(name):
    if name not in _decoded:
        with open(os.path.join(DIR, name), "rb") as fh:
            v, why = data.decode(fh.read().decode("utf-8"))
        if why is not None:
            raise Exception(name + ": " + why)
        _decoded[name] = v
    return _decoded[name]


def rejectedSteps(f):
    """The (1-based) indices of a fixture's steps that expect a rejection."""
    return [i + 1 for i, st in enumerate(f["steps"])
            if isinstance(st.get("expect"), dict) and "rejected" in st["expect"]]


totalRejected = sum(len(rejectedSteps(load(name))) for name in files)


def failsEachRejection(show, needles):
    """Every rejected step fails with needles[0], every other step passes,
    and every problem holds one of the needles."""
    bad = []
    for name in files:
        f = load(name)
        rej = rejectedSteps(f)
        r = run(f, {"Stream": wrapped(show)})
        if r["passed"] != r["total"] - len(rej):
            bad.append(name + ": " + str(r["passed"]) + "/" + str(r["total"]) + " passed, expected " +
                       str(r["total"] - len(rej)))
        for i in rej:
            if not any(s.startswith("steps[" + str(i) + "]") and needles[0] in s for s in r["problems"]):
                bad.append(name + " step " + str(i) + ": no '" + needles[0] + "' in " + joined(r["problems"]))
        for s in r["problems"]:
            if not any(n in s for n in needles):
                bad.append(name + ": unrelated problem " + s)
    return bad


def onlyFrozen(v):
    """None when v holds nothing but primitives, tuples and FrozenDicts, else why."""
    stack = [v]
    while stack:
        x = stack.pop()
        t = type(x)
        if t in _PRIMITIVES:
            continue
        if t is tuple:
            stack.extend(x)
        elif t is FrozenDict:
            stack.extend(x.values())
        else:
            return "the wrapper's copy holds a " + t.__name__
    return None


def _hasRejections():
    assert_(len(files) >= 15, "fixtures/ holds " + str(len(files)) + " files")
    assert_(totalRejected > 0, "no fixture step expects a rejection")


test("the suite has rejections to judge", _hasRejections)


def _acceptedCopy():
    seen, bad = [], []
    for name in files:
        r = run(load(name), {"Stream": wrapped(shows["copy"], seen)})
        if r["passed"] != r["total"] or r["problems"]:
            bad.append(name + " " + str(r["passed"]) + "/" + str(r["total"]) + ": " + joined(r["problems"]))
    assert_(len(bad) == 0, joined(bad))
    # the runner was really shown copies, one per rejected step
    assertEqual(len(seen), totalRejected, "rejections shown")
    for x in seen:
        assert_(x["shown"] is not x["raised"], "the wrapper showed the raised object")

        def assign(c=x["shown"]):
            c["code"] = "X"

        refused = False
        try:
            assign()
        except Exception:
            refused = True
        assert_(refused, "the wrapper's copy accepts assignment")
        assert_(type(x["shown"]) is FrozenDict, "the wrapper's copy is a " + type(x["shown"]).__name__)
        why = onlyFrozen(x["shown"])
        assert_(why is None, why)


test("accepted: every fixture passes when observers are shown a read-only copy", _acceptedCopy)


def _rejectedItself():
    bad = failsEachRejection(shows["itself"], ["very object raised", "a rejected batch carries no rejection"])
    assert_(len(bad) == 0, joined(bad))


test("rejected: a batch carrying the raised object itself fails each rejected step", _rejectedItself)


def _rejectedChanged():
    bad = failsEachRejection(shows["changedMessage"], ["differs from the one raised at 'message'"])
    assert_(len(bad) == 0, joined(bad))


test("rejected: a copy whose message differs fails each rejected step", _rejectedChanged)


def _rejectedWritable():
    bad = failsEachRejection(shows["writable"], ["accepts assignment"])
    assert_(len(bad) == 0, joined(bad))


test("rejected: a copy that accepts assignment fails each rejected step", _rejectedWritable)


def _inline():
    f = {
        "format": 1, "name": "inline", "covers": ["K5"],
        "gates": [{"signature": "a", "does": {"emitOne": {"type": "b"}}},
                  {"signature": "b", "does": {"throw": "b failed"}}],
        "steps": [{"emit": {"type": "a"}, "expect": {"rejected": {
            "code": "SISO_GATE_THREW", "cause": "b failed", "at": ["a", "b"]}}}],
    }
    r = run(f, {"Stream": wrapped(shows["copy"])})
    assert_(r["passed"] == 1 and not r["problems"], joined(r["problems"]))
    r = run(f, {"Stream": wrapped(shows["itself"])})
    assert_(r["passed"] == 0, "the raised object itself was accepted")


test("an inline fixture: the copy is accepted, the raised object refused", _inline)

H.finish("fixtures-rejection-copy")
