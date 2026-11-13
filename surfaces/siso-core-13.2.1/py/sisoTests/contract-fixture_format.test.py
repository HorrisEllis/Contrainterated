# contract test: fixture-format (py/code/siso/contracts/fixture_format.py).
# Port of lua/sisoTests/contract-fixture_format.test.lua. The shape check
# accepts well-formed fixtures and names malformed ones; the matching rules
# are order-free (K1). The shared files themselves are the fixtures
# boundary's: the Python fixtures suite checks each one's shape, as the Lua
# fixtures.test.lua does, so this test reads none of them.
# Runnable from any directory: python3 py/sisoTests/contract-fixture_format.test.py
import sys
sys.dont_write_bytecode = True  # no __pycache__ in the project tree

import os
import sys
import types

HERE = os.path.dirname(os.path.abspath(__file__))
sys.path.insert(0, os.path.normpath(os.path.join(HERE, "..", "code")))

from siso import harness as H  # noqa: E402
from siso.contracts import fixture_format as F  # noqa: E402


def joined(lst):
    return "; ".join(lst)


def has(lst, needle):
    return any(needle in s for s in lst)


# a decoded fixture, as a JSON decoder would give it
cycle = {
    "format": 1,
    "name": "an indirect cycle is rejected at the first claimed delivery deeper than the gate count",
    "covers": ["Rule 3", "K5"],
    "gates": [
        {"signature": "a", "does": {"emitOne": {"type": "b"}}},
        {"signature": "b", "does": {"emit": [{"type": "a", "data": {"n": 1}}, {"raw": 42}]}},
    ],
    "steps": [
        {"emit": {"type": "a"}, "expect": {
            "rejected": {"code": "SISO_CYCLE", "eventType": "a", "depth": 3, "at": ["a", "b", "a"]},
            "trace": [{"type": "a", "claimed": True, "then": [
                {"type": "b", "claimed": True, "then": [{"type": "a", "claimed": True}]}]}]}},
        {"sample": {"residue": [], "gateCount": 2}},
        {"register": {"signature": "a", "does": {"nothing": True}}, "refused": True},
        {"emitRaw": "x", "expect": {"rejected": {"code": "SISO_NOT_EVENT"}}},
        {"emitRaw": None, "expect": {"rejected": {"code": "SISO_NOT_EVENT"}}},
        {"take": []},
    ],
}


def base(steps, gates=None, **over):
    f = {"format": 1, "name": "x", "covers": ["K1"], "gates": [] if gates is None else gates, "steps": steps}
    f.update(over)
    return f


def t_wellFormed():
    p = F.problems(cycle)
    H.assert_(not p, joined(p))


H.test("a well-formed fixture passes", t_wellFormed)


def t_malformed():
    cases = [
        ("format", base([{"take": []}], format=2), "format"),
        ("format True", base([{"take": []}], format=True), "format"),
        ("covers", base([{"take": []}], covers=[]), "covers"),
        ("two behaviours", base([{"take": []}], [{"signature": "a", "does": {"emit": [], "throw": "x"}}]), "exactly one"),
        ("float data", base([{"emit": {"type": "a", "data": {"f": 1.5}}, "expect": "settled"}]), "float"),
        ("1.0 is a float", base([{"emit": {"type": "a", "data": {"f": 1.0}}, "expect": "settled"}]), "float"),
        ("unknown code", base([{"emit": {"type": "a"}, "expect": {"rejected": {"code": "NOPE"}}}]), "known code"),
        ("both outcomes", base([{"emit": {"type": "a"}, "expect": {"settled": True, "rejected": {"code": "SISO_CYCLE"}}}]),
         "exactly one of"),
        ("unknown step", base([{"wait": 1}]), "a step is one of"),
        ("no steps", base([]), "non-empty"),
        ("an empty array in data", base([{"emit": {"type": "a", "data": {"l": []}}, "expect": "settled"}]), "empty array"),
        ("null data", base([{"emit": {"type": "a", "data": None}, "expect": "settled"}]), "data is not an object"),
        ("an extra event field", base([{"emit": {"type": "a", "extra": 1}, "expect": "settled"}]), "unexpected field"),
        ("a raw null behaviour", base([{"take": []}], [{"signature": "a", "does": {"raw": None}}]), "raw null"),
        ("register without refused", base([{"register": {"signature": "a", "does": {"nothing": True}}}]), "refused"),
        ("sample without gateCount", base([{"sample": {"residue": []}}]), "sample is"),
        ("a bool gateCount", base([{"sample": {"residue": [], "gateCount": True}}]), "sample is"),
        ("a node without claimed", base([{"emit": {"type": "a"}, "expect": {"settled": True, "trace": [{"type": "a"}]}}]),
         "a node is"),
        ("a bool depth", base([{"emit": {"type": "a"}, "expect": {"rejected": {"code": "SISO_CYCLE", "depth": True}}}]),
         "depth is an integer"),
        ("not an object", "x", "a fixture is an object"),
    ]
    for label, f, needle in cases:
        H.assert_(has(F.problems(f), needle), label + ": " + joined(F.problems(f)))


H.test("malformed fixtures are named", t_malformed)


class Ev:
    """An event as a core hands one out: type and data by attribute, data a read-only mapping."""

    __slots__ = ("type", "data")

    def __init__(self, t, d):
        object.__setattr__(self, "type", t)
        object.__setattr__(self, "data", d)


def t_residue():
    a = [{"type": "x", "data": {"v": 1}}, {"type": "y"}, {"type": "x", "data": {"v": 1}}]
    b = [{"type": "y", "data": {}}, {"type": "x", "data": {"v": 1}}, {"type": "x", "data": {"v": 1}}]
    H.assert_(F.residueMatch(a, b)[0], "same multiset in another order")
    ok, why = F.residueMatch(a, [b[0], b[1]])
    H.assert_(not ok and isinstance(why, str), "a missing copy, and why")
    events = [Ev("x", types.MappingProxyType({"v": 1, "l": (1, 2)}))]
    H.assert_(F.residueMatch([{"type": "x", "data": {"l": [1, 2], "v": 1}}], events)[0], "events with frozen data")
    H.assert_(not F.residueMatch([{"type": "x", "data": {"l": {"0": 1}}}], [{"type": "x", "data": {"l": [1]}}])[0],
              "an array is not an object")
    H.assert_(not F.residueMatch([{"type": "x", "data": {"v": True}}], [{"type": "x", "data": {"v": 1}}])[0],
              "true is not 1")


H.test("residue matches as a multiset", t_residue)


def t_canon():
    H.assertEqual(F.canon({"b": [1, "x"], "a": None, "c": True}), '{"a":null,"b":[1,"x"],"c":true}')
    H.assertEqual(F.canon({"é": 1, "z": 2}), '{"z":2,"é":1}')
    H.assertThrows(lambda: F.canon(1.5), "float")
    H.assertThrows(lambda: F.canon(lambda: 1), "not plain data")
    c = {}
    c["self"] = c
    H.assertThrows(lambda: F.canon(c), "cycle")
    H.assertThrows(lambda: F.canon({1: 2}), "a key of type int")


H.test("canon renders plain data canonically and refuses what is not", t_canon)


def r(eventId, causeId, eventType, claimed):
    return {"eventId": eventId, "causeId": causeId, "eventType": eventType, "claimed": claimed, "data": {}}


def t_trace():
    expected = [{"type": "root", "claimed": True, "then": [
        {"type": "a", "claimed": True, "then": [{"type": "a1", "claimed": False}]},
        {"type": "b", "claimed": False}]}]
    bfs = [r(1, None, "root", True), r(2, 1, "a", True), r(3, 1, "b", False), r(4, 2, "a1", False)]
    dfs = [r(1, None, "root", True), r(2, 1, "a", True), r(3, 2, "a1", False), r(4, 1, "b", False)]
    H.assert_(F.traceMatch(expected, bfs)[0], "breadth-first")
    H.assert_(F.traceMatch(expected, dfs)[0], "depth-first")
    wrong = [bfs[0], bfs[1], bfs[2], r(4, 3, "a1", False)]
    ok, why = F.traceMatch(expected, wrong)
    H.assert_(not ok and isinstance(why, str), "a1 caused by the wrong delivery")
    H.assertEqual(len(F.forest(bfs)), 1, "one root")
    H.assertThrows(lambda: F.forest([r(1, 7, "x", False)]), "names no earlier delivery")


H.test("a trace matches whatever the delivery order", t_trace)


def t_recordAt():
    recs = [r(1, None, "a", True), r(2, 1, "c", False), r(3, 1, "b", True), r(4, 3, "a", True)]
    rec, why = F.recordAt(recs, ["a", "b", "a"])
    H.assert_(rec is not None and rec["eventId"] == 4 and why is None, "the a caused by b")
    none, whyNone = F.recordAt(recs, ["a", "x"])
    H.assert_(none is None and isinstance(whyNone, str), "no such path")
    twice = recs + [r(5, 1, "c", False)]
    H.assert_(F.recordAt(twice, ["a", "c"])[0] is None, "an ambiguous path")


H.test("recordAt finds a delivery by its path of types, in any order", t_recordAt)

H.finish("contract fixture-format")
