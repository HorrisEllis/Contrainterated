# kit-data-frozen -- py/code/siso/kit/data.py's data.encode reads frozen
# data (py-core-api READ-ONLY) as the plain data it presents (py-kit-api),
# so encode(freeze(v)) == encode(v) byte for byte, and residue, samples
# and observer records from a real siso.core Stream serialise as handed
# out. Port of lua/sisoTests/kit-data-frozen.test.lua case by case.
#   python3 -B py/sisoTests/kit-data-frozen.test.py   (from any directory)
# Expected values: the encoding of the same data built plainly (the
# plain-data encoder is pinned by kit-data.test.py), and for the fixed
# samples a literal canonical text written by hand.
#
# `freeze` is the real core's (py-kit-api SUBSTRATE: any function that
# returns a frozen version of plain data): v -> Event("t", {"v": v}).data
# ["v"], the core's own frozen deep copy (py-core-api EVENT: a dict
# becomes a FrozenDict, a list or tuple a tuple; wrapping lets any value,
# a list or a scalar too, be frozen).
# Ported as Python's counterpart:
#   - "freezing really happened: writes refused, marks answered through
#     getmetatable" -> a frozen dict is a FrozenDict (a Mapping, not a
#     dict) that refuses writes, a frozen list is a tuple and an array, a
#     frozen dict is not an array, None passes through.
#   - "next sees contents on a frozen table (read surface is not raw)" ->
#     a FrozenDict is no dict (dict's own methods cannot read it), so
#     encode reads it through its Mapping surface: iteration and [].
#   - "marked arrays" / "unmarked arrays (keys exactly 1..n)" -> lists and
#     tuples (every list is an array; freezing makes it a tuple);
#     "an empty unmarked table is an object" -> [] is "[]", {} is "{}".
#   - "null keeps its identity" -> frozen data's null is None.
#   - "invalid UTF-8" -> a lone surrogate.
#   - "a frozen table inside plain data" -> a FrozenDict and a frozen
#     tuple inside a plain dict and list.
#   - refusals: the core's Event itself refuses what has no place in a
#     Python value of plain data, so "a hole in a frozen unmarked list",
#     "mixed keys", "a marked array with a hole or a named key" and "a
#     non-string, non-number key" become: a key that is not a str and a
#     list subclass are refused by encode plainly, and the core refuses to
#     freeze them at all (TypeError at construction). What the core
#     freezes and encode refuses -- NaN, +-inf, a lone surrogate, an
#     integer past 64 bits, an Event carried in data, nesting past 512 --
#     is refused alike, plainly and frozen, with the same message.
#   - "a foreign metatable" -> a class instance and a MappingProxyType,
#     plain.
#   - nesting: Lua's nest(n) wraps an empty table n times; the same counts
#     here (511 wraps = 512 containers pass, 512 wraps are refused).
#   - the real stream: Stream.register/emit/takeResidue/sampleHere/attach,
#     with an observer batch's records (dicts with "eventType" and
#     "data", py-observer-seam).
#   - the kit-api check is given freeze and the core's Event, so it also
#     asks that an Event is refused.
# Cannot apply: none (the kit-api check takes no sha256 or helper module).
import sys
sys.dont_write_bytecode = True  # no __pycache__ in the project tree

import collections.abc  # noqa: E402
import os  # noqa: E402
import types  # noqa: E402

HERE = os.path.dirname(os.path.abspath(__file__))
sys.path.insert(0, os.path.normpath(os.path.join(HERE, "..", "code")))

from siso import harness as H  # noqa: E402
from siso.contracts import kit_api as K  # noqa: E402
from siso.core import Event, FrozenDict, Gate, Stream  # noqa: E402
from siso.kit import data  # noqa: E402

test, assert_, assertEqual = H.test, H.assert_, H.assertEqual
null, array = data.null, data.array


def freeze(v):
    return Event("t", {"v": v}).data["v"]


def enc(v):
    s, err = data.encode(v)
    if s is None:
        H.fail("encode failed: " + str(err))
    return s


def encErr(v, fragment):
    s, err = data.encode(v)
    assertEqual(s, None, "encode should fail")
    assert_(type(err) is str and fragment in err, "error " + H.show(err) + " lacks '" + fragment + "'")


def same(build, want=None):
    """encode(freeze(build())) == encode(build()), and both equal `want` when given."""
    plain = enc(build())
    frozen = freeze(build())
    assert_(type(frozen) in (FrozenDict, tuple, str, int, float, bool, type(None)), "the value was frozen")
    assertEqual(enc(frozen), plain, "frozen vs plain")
    if want is not None:
        assertEqual(plain, want, "plain text")


def refusedAlike(build, fragment):
    """build() is refused plainly and frozen, with the same message."""
    encErr(build(), fragment)
    _, plainWhy = data.encode(build())
    _, frozenWhy = data.encode(freeze(build()))
    assertEqual(frozenWhy, plainWhy, "frozen refusal vs plain refusal")


def coreRefuses(build):
    try:
        freeze(build())
    except TypeError as err:
        assert_("not plain data" in str(err), str(err))
        return
    H.fail("the core froze " + H.show(build()))


# ── the contract ────────────────────────────────────────────────────

def t_check():
    p = K.check(data, freeze=freeze, Event=Event)
    assert_(not p, "; ".join(p))
    p = K.check(data, freeze=lambda v: Event("t", v).data, Event=Event)
    assert_(not p, "the contract's own freeze: " + "; ".join(p))


test("kit-api check passes with freeze and Event given (frozen data reads as plain)", t_check)


def t_frozen():
    f = freeze({"a": [1], "o": {}})
    assert_(type(f) is FrozenDict, "a frozen dict is a FrozenDict")
    assert_(isinstance(f, collections.abc.Mapping) and not isinstance(f, dict), "a Mapping, not a dict")
    for label, write in (("setting an item", lambda: f.__setitem__("x", 1)),
                         ("setting an attribute", lambda: setattr(f, "x", 1)),
                         ("appending to a frozen list", lambda: f["a"].append(2))):
        try:
            write()
            H.fail("a frozen value accepted " + label)
        except (TypeError, AttributeError):
            pass
    assert_(data.isArray(f["a"]), "a frozen list is not an array")
    assert_(type(f["a"]) is tuple, "a frozen list is a tuple")
    assert_(not data.isArray(f["o"]), "a frozen dict is an array")
    assert_(freeze(null) is null, "None did not pass through freezing")
    assertEqual(sorted(f), ["a", "o"], "the Mapping surface reads the contents")
    try:
        dict.keys(f)
        H.fail("dict's own methods read a FrozenDict")
    except TypeError:
        pass


test("freezing really happened: writes refused, a frozen list is a tuple, a frozen dict a Mapping", t_frozen)


# ── encode(freeze(v)) == encode(v) ──────────────────────────────────

test("nested objects", lambda: same(
    lambda: {"z": {"y": {"x": {"w": "deep"}}}, "a": {"b": 1, "c": {"d": True}}},
    '{"a":{"b":1,"c":{"d":true}},"z":{"y":{"x":{"w":"deep"}}}}'))

test("arrays, nested and of objects", lambda: same(
    lambda: [[1, 2], {"k": "v"}, [[]]],
    '[[1,2],{"k":"v"},[[]]]'))

test("lists and tuples alike", lambda: same(
    lambda: {"l": [3, 2, 1], "m": ((1,), ("a", "b"))},
    '{"l":[3,2,1],"m":[[1],["a","b"]]}'))


def t_empties():
    same(lambda: [], "[]")
    same(lambda: (), "[]")
    same(lambda: {"e": [], "o": {}}, '{"e":[],"o":{}}')
    same(lambda: {}, "{}")


test("empty arrays, and an empty dict is an object", t_empties)

test("floats kept apart from integers", lambda: same(
    lambda: [1, 1.0, -0.0, 0.1, 1e21, 5e-324, 2.0 ** 53],
    "[1,1.0,-0.0,0.1,1e+21,5e-324,9007199254740992.0]"))


def t_null():
    same(lambda: {"n": null, "l": [null, 1, null]}, '{"l":[null,1,null],"n":null}')
    f = freeze({"n": null})
    assert_(f["n"] is null, "frozen data's null is not None")


test("null, in arrays and objects, is None", t_null)

test("strings, escapes and UTF-8", lambda: same(
    lambda: {"s": 'a"b\\c\n\x00', "u": "ääkköset ✓", "ü": ""},
    '{"s":"a\\"b\\\\c\\n\\u0000","u":"ääkköset ✓","ü":""}'))

test("booleans and integers at the edges", lambda: same(
    lambda: [True, False, 2 ** 63 - 1, -2 ** 63, 0],
    "[true,false,9223372036854775807,-9223372036854775808,0]"))


def t_mixed():
    inner = freeze({"k": [1, 2.5]})
    assertEqual(enc({"wrap": inner, "l": [inner]}), '{"l":[{"k":[1,2.5]}],"wrap":{"k":[1,2.5]}}')
    assertEqual(enc([freeze([1, (2,)]), Event("t", {"x": {"y": []}}).data]), '[[1,[2]],{"x":{"y":[]}}]')


test("frozen data inside plain data", t_mixed)


# ── refusals still refuse when frozen ───────────────────────────────

def t_keysAndSubclasses():
    class Marked(list):
        pass

    for build, fragment in ((lambda: {1: "x"}, "object key 1 is not a string"),
                            (lambda: {True: 1, "a": 2}, "is not a string"),
                            (lambda: {"l": Marked([1])}, "(a subclass of list)")):
        encErr(build(), fragment)
        coreRefuses(build)


test("rejection: a key that is not a str or a list subclass: refused by encode, never frozen by the core",
     t_keysAndSubclasses)


def t_valuesAlike():
    refusedAlike(lambda: {"x": float("nan")}, "NaN is not allowed")
    refusedAlike(lambda: {"x": "\udcff"}, "not valid UTF-8")
    refusedAlike(lambda: {"x": [1, float("inf")]}, "Infinity is not allowed at $.x[1]")
    refusedAlike(lambda: {"x": [2 ** 63]}, "integer out of 64-bit range")
    refusedAlike(lambda: {"e": Event("t", {"k": 1})}, "an Event is not data at $.e")


test("rejection: NaN, inf, a lone surrogate, a big integer and an Event inside frozen data", t_valuesAlike)


def t_foreign():
    class Opaque:
        pass

    encErr(Opaque(), "an Opaque is not data")
    encErr(types.MappingProxyType({"k": 1}), "a mappingproxy is not data")
    coreRefuses(lambda: Opaque())


test("rejection: a class instance or another mapping is still refused (plain)", t_foreign)


def t_nesting():
    def nest(n):
        v = {}
        for _ in range(n):
            v = [v]
        return v

    same(lambda: nest(511))
    refusedAlike(lambda: nest(512), "nesting too deep")


test("rejection: nesting limit holds on frozen data", t_nesting)


# ── real stream: residue, samples, observer records ─────────────────

def payload(v):
    """What the gate returns, built plainly each time it is called."""
    return {
        "v": v,
        "f": 2.5,
        "whole": 3.0,
        "n": null,
        "s": "ä\t",
        "list": [1, 2, 3],
        "tuple": ({"k": "x"}, []),
        "empty": [],
        "obj": {"deep": {"deeper": [True, False]}},
    }


def entryData():
    return {"v": 7, "tags": ["a", "b"], "meta": {"at": 1.5}}


def newStream():
    s = Stream()
    s.register(Gate("in", lambda e: [Event("out", payload(e.data["v"])), Event("plain", {"only": e.data["tags"]})]))
    return s


def plainEvents():
    """Plain encodings to compare with."""
    return {"out": enc(payload(7)), "plain": enc({"only": ["a", "b"]}), "in": enc(entryData())}


def byType(events):
    return {e.type: enc(e.data) for e in events}


def refusesWrite(d):
    try:
        d.__setitem__("v", 0)
    except (TypeError, AttributeError):
        return True
    return False


def t_residue():
    s = newStream()
    s.emit(Event("in", entryData()))
    taken = s.takeResidue()
    assertEqual(len(taken), 2, "residue size")
    assert_(refusesWrite(taken[0].data), "taken residue is not frozen")
    want, got = plainEvents(), byType(taken)
    assertEqual(got["out"], want["out"], "out")
    assertEqual(got["plain"], want["plain"], "plain")


test("residue from takeResidue encodes as the plain data", t_residue)


def t_sample():
    s = newStream()
    s.emit(Event("in", entryData()))
    residue = s.sampleHere()["residue"]
    assert_(refusesWrite(residue[0].data), "sampled residue is not frozen")
    want, got = plainEvents(), byType(residue)
    assertEqual(got["out"], want["out"], "out")
    assertEqual(got["plain"], want["plain"], "plain")
    # a whole residue list of {type, data} encodes as built plainly
    lst = sorted(({"type": e.type, "data": e.data} for e in residue), key=lambda r: r["type"])
    plainList = [{"type": t, "data": payload(7) if t == "out" else {"only": ["a", "b"]}} for t in ("out", "plain")]
    assertEqual(enc(lst), enc(plainList), "residue list")


test("events from sampleHere encode as the plain data", t_sample)


def t_records():
    s = newStream()
    batches = []
    s.attach(batches.append)
    s.emit(Event("in", entryData()))
    assertEqual(len(batches), 1, "batches")
    records = batches[0]["records"]
    assertEqual(len(records), 3, "records")
    want, seen = plainEvents(), 0
    for r in records:
        assert_(refusesWrite(r["data"]), "record data is not frozen")
        assertEqual(enc(r["data"]), want[r["eventType"]], "record " + r["eventType"])
        seen += 1
    assertEqual(seen, 3, "records compared")


test("records' data from an observer batch encode as the plain data", t_records)

H.finish("kit-data-frozen")
