# fixtures-marks.test.py -- the runner (py/code/siso/fixtures/run.py)
# copies fixture data through siso.kit.data's API (py-kit-api): an array
# (data.isArray: a list or a tuple) is copied as data.array of a fresh
# list, data.null (None) is kept as itself, and every other mapping is a
# fresh plain dict. Nothing it hands a gate or Event is the fixture's own
# object, and no two calls share one. Port of
# lua/sisoTests/fixtures-marks.test.lua, case by case.
#   python3 -B py/sisoTests/fixtures-marks.test.py   (from the project root, or anywhere)
#
# The runner is given a core whose Event and Gate wrap the real ones
# (run(fixture, core)), so the test sees exactly the data the runner hands
# Event, and every transform it builds, before the core touches them. That
# is the counterpart of the Lua suite's package.loaded swap of core.event
# and core.gate (the Python runner takes the core as an argument).
#
# Turned into Python's own counterpart (py-kit-api SUBSTRATE: "Lua's marks
# vanish: an array is a list or a tuple, JSON null is None"):
#   - Lua's three kits become two forms of fixture data the runner must
#     copy alike: data decoded by the workspace's siso.kit.data (lists,
#     dicts, None) and data written inline with tuples for arrays (a
#     fixture written as Python may hold either; data.isArray answers True
#     for both).
#   - "a marked array stays marked" -> the copy of an array is an exact
#     list (data.array returns it as given); "data.null is kept as itself"
#     -> None is kept as None; "the data has a metatable" / "an object
#     gained a metatable" -> every mapping in the copy is an exact dict
#     and every array an exact list, no subclass, and an object is not an
#     array.
#   - "the data is the fixture's own table" and "two calls share one
#     copy" are judged by identity (`is`), at every container.
#   - Lua's "raw return" case on an inert stream: the inert stream is a
#     class whose doors take registrations and run nothing; the transform
#     the runner built is then called directly.
# Cannot apply (no counterpart in Python):
#   - The two test-local kit.data stand-ins, "marks answering strings"
#     (kit-api, core 13.0.0) and "marks answering tables" (the kit before
#     stateless-marks): Python has no marks and no metatables
#     (data.array returns the very list, data.null is None), so there are
#     no two forms of mark for the runner to be indifferent to. Their
#     three cases run on the two forms of data above instead.
import sys

sys.dont_write_bytecode = True

import os  # noqa: E402

HERE = os.path.dirname(os.path.abspath(__file__))
sys.path.insert(0, os.path.normpath(os.path.join(HERE, "..", "code")))

from siso import harness as H  # noqa: E402
from siso.core.event import Event as RealEvent  # noqa: E402
from siso.core.gate import Gate as RealGate  # noqa: E402
from siso.core.stream import Stream  # noqa: E402
from siso.fixtures.run import run  # noqa: E402
from siso.kit import data as realData  # noqa: E402

test, assert_, assertEqual = H.test, H.assert_, H.assertEqual


def joined(lst):
    return "; ".join(lst)


# ── a core that shows what the runner hands it ──────────────────────

def watched(StreamClass=Stream):
    """-> (core, entered, transforms): `entered` is every (type, data) the
    runner handed Event, `transforms` every gate transform it built, by
    signature."""
    entered = []
    transforms = {}

    def Event(t, x=None):
        entered.append({"type": t, "data": x})
        return RealEvent(t, x)

    def Gate(sig, fn=None):
        transforms[sig] = fn
        return RealGate(sig, fn)

    return {"Event": Event, "Gate": Gate, "Stream": StreamClass}, entered, transforms


# ── the forms of fixture data ───────────────────────────────────────

SAMPLE = '{"list":[1,"x",null],"obj":{"k":"v","inner":[2]},"n":null}'


def decoded():
    v, why = realData.decode(SAMPLE)
    if why is not None:
        raise Exception("decode: " + why)
    return v


def inlineTuples():
    return {"list": (1, "x", None), "obj": {"k": "v", "inner": (2,)}, "n": None}


forms = [
    ("data decoded by the workspace's kit.data", decoded),
    ("data written inline with tuples", inlineTuples),
]


def copyProblems(original, got):
    """The whole of a runner's copy of the sample, against the original."""
    p = []
    bad = p.append
    if type(got) is not dict:
        return ["the data is " + type(got).__name__ + ", not a dict"]
    if got is original:
        bad("the data is the fixture's own dict, not a copy")
    lst = got.get("list")
    if type(lst) is not list:
        bad("list is " + type(lst).__name__ + ", not a list")
    else:
        if lst is original["list"]:
            bad("list is the fixture's own array")
        if not realData.isArray(lst):
            bad("list is not an array")
        if len(lst) != 3:
            bad("list holds " + str(len(lst)) + " values")
        elif not (type(lst[0]) is int and lst[0] == 1 and lst[1] == "x"):
            bad("list's values changed")
        elif lst[2] is not realData.null:
            bad("list[3] is not data.null itself")
    obj = got.get("obj")
    if type(obj) is not dict:
        bad("obj is " + type(obj).__name__ + ", not a dict")
    else:
        if obj is original["obj"]:
            bad("obj is the fixture's own dict")
        if realData.isArray(obj):
            bad("obj, an object, is an array")
        if obj.get("k") != "v":
            bad("obj.k changed")
        inner = obj.get("inner")
        if type(inner) is not list or len(inner) != 1 or inner[0] != 2:
            bad("obj.inner lost its value or is not a list")
        elif inner is original["obj"]["inner"]:
            bad("obj.inner is the fixture's own array")
    if "n" not in got or got["n"] is not realData.null:
        bad("n is not data.null itself")
    if len(got) != 3:
        bad("the data holds " + str(len(got)) + " fields, expected 3")
    return p


def enteredAs(entered, t):
    """What the runner handed Event for `t`, the last time."""
    for e in reversed(entered):
        if e["type"] == t:
            return e["data"]
    return None


# ── cases ───────────────────────────────────────────────────────────

class Inert:
    """A stream that only takes registrations: no gate runs inside it."""

    def register(self, g):
        pass

    def attach(self, f):
        pass

    def emit(self, e):
        pass


for label, make in forms:

    def entryCopied(make=make):
        core, entered, _ = watched()
        d = make()
        f = {
            "format": 1, "name": "an entry with marks", "covers": ["Rule 4"], "gates": [],
            "steps": [{"emit": {"type": "q", "data": d}, "expect": "settled"}],
        }
        r = run(f, core)
        p = copyProblems(d, enteredAs(entered, "q"))
        assert_(len(p) == 0, joined(p))
        assert_(r["passed"] == r["total"], str(r["passed"]) + "/" + str(r["total"]) + ": " + joined(r["problems"]))

    test(label + ": an entry's data is copied through the kit's API", entryCopied)

    def emittedCopied(make=make):
        core, entered, _ = watched()
        d = make()
        f = {
            "format": 1, "name": "a gate emitting marks", "covers": ["Rule 1"],
            "gates": [{"signature": "a", "does": {"emit": [{"type": "out", "data": d}]}}],
            "steps": [{"emit": {"type": "a"}, "expect": "settled"}, {"emit": {"type": "a"}, "expect": "settled"}],
        }
        r = run(f, core)
        outs = [e["data"] for e in entered if e["type"] == "out"]
        assertEqual(len(outs), 2, "out events built")
        for i, got in enumerate(outs):
            p = copyProblems(d, got)
            assert_(len(p) == 0, "call " + str(i + 1) + ": " + joined(p))
        assert_(outs[0] is not outs[1], "two calls share one copy")
        assert_(outs[0]["list"] is not outs[1]["list"], "two calls share one array")
        assert_(outs[0]["obj"] is not outs[1]["obj"], "two calls share one object")
        assert_(r["passed"] == r["total"], str(r["passed"]) + "/" + str(r["total"]) + ": " + joined(r["problems"]))

    test(label + ": a gate's emitted data is copied through the kit's API, every call", emittedCopied)

    def rawCopied(make=make):
        core, _, transforms = watched(Inert)
        raw = make()["list"]
        f = {
            "format": 1, "name": "a raw array", "covers": ["Rule 1"],
            "gates": [{"signature": "a", "does": {"raw": raw}}],
            "steps": [{"emit": {"type": "a"}, "expect": {"rejected": {"code": "SISO_NOT_EVENT"}}}],
        }
        run(f, core)
        # the transform the runner built, called directly: only it runs
        assert_(callable(transforms.get("a")), "the runner built no gate a")
        returned = transforms["a"](RealEvent("a"))
        assert_(type(returned) is list, "the gate returned " + type(returned).__name__)
        assert_(returned is not raw, "the raw value is the fixture's own array")
        assert_(realData.isArray(returned), "the raw copy is not an array")
        assert_(len(returned) == 3 and returned[0] == 1 and returned[1] == "x" and returned[2] is realData.null,
                "the raw copy's values changed")
        again = transforms["a"](RealEvent("a"))
        assert_(again is not returned, "two calls share one raw copy")

    test(label + ": a raw return is copied through the kit's API", rawCopied)


def _endToEnd():
    f, why = realData.decode('{"format":1,"name":"decoded marks","covers":["Rule 4"],'
                             '"gates":[{"signature":"a","does":{"emitOne":{"type":"b",'
                             '"data":{"l":[1,2],"z":null,"o":{"k":"v"}}}}}],'
                             '"steps":[{"emit":{"type":"a","data":{"l":[3]}},"expect":"settled"},'
                             '{"take":[{"type":"b","data":{"l":[1,2],"z":null,"o":{"k":"v"}}}]}]}')
    assert_(why is None, "decode: " + str(why))
    r = run(f)
    assert_(r["passed"] == r["total"] and not r["problems"],
            str(r["passed"]) + "/" + str(r["total"]) + ": " + joined(r["problems"]))


test("the workspace's kit.data: a decoded fixture with marks passes end to end", _endToEnd)

H.finish("fixtures-marks")
