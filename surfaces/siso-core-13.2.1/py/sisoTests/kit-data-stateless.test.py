# kit-data-stateless -- nothing py/code/siso/kit/data.py hands out gives a
# gate an object it could write and later read back (py-kit-api; py-core-
# api STATELESS THROUGH THE EVENT, core 13.0.0). Port of
# lua/sisoTests/kit-data-stateless.test.lua case by case.
#   python3 -B py/sisoTests/kit-data-stateless.test.py  (from any directory)
# Expected values: the py-kit-api contract's (py/code/siso/contracts/
# kit_api.py, its SUBSTRATE in particular: "data.null is None" and
# "data.array returns the very list it was given"); the counting probe is
# py-core-api's checkReadonly "count" gate, run here on what siso.kit.data
# hands out directly, without a stream. Freezing is the stream's and is
# checked in kit-data-frozen.
#
# Ported as Python's counterpart (py-kit-api SUBSTRATE: Lua's marks
# vanish; an array is a list or a tuple, JSON null is None):
#   - "data.null answers getmetatable with readonly:kit.data.null" ->
#     data.null is None (no sentinel object).
#   - "a marked array answers getmetatable with kit.data.array" ->
#     data.array returns the very list it was given, its type unchanged
#     and nothing attached; data.array() with no argument is Python's own
#     arity TypeError (Lua's array() made a new empty table).
#   - "decoded arrays answer the string mark, decoded null is data.null"
#     -> decoded arrays are exact lists, objects exact dicts, null None.
#   - "nothing reachable ... answers getmetatable with a table" -> nothing
#     reachable from what siso.kit.data hands out is anything but an
#     exact dict, list, tuple, str, int, float, bool or None (builtin
#     types, which take no attribute), and every decode hands out fresh
#     containers: two calls share none.
#   - "a gate cannot carry a count through the marks" -> a gate cannot
#     carry a count through what siso.kit.data hands out: every object it
#     reaches, and each one's class, refuses an attribute.
#   - "the marks cannot be replaced or stripped" -> there is no mark: a
#     list's, a tuple's or None's class cannot be reassigned, and None
#     takes no attribute; calls leave kit.data's namespace as it was
#     (nothing is written after load).
#   - "data.null still refuses assignment and still reads as null" ->
#     None refuses assignment and encodes as "null" (str(None) is
#     Python's "None": rendering is not the kit's).
#   - "data.array on a table that answers the mark (a frozen marked
#     array)" -> on a frozen list, the tuple in an event's data.
#   - "data.array raises on any other metatable, table or string" ->
#     raises TypeError on anything that is not an exact list or tuple:
#     a dict, a FrozenDict, a str (the old mark's name), None, a list
#     subclass, a namedtuple, a set, a generator.
#   - "is_array: true exactly for kit.data.array" -> isArray is True
#     exactly for exact lists and tuples.
#   - "a second load of kit.data agrees on arrays" -> a second module
#     instance (data.py loaded again under another name) agrees.
#   - "encode reads a table answering kit.data.array as an array" ->
#     encode reads a tuple, the core's frozen list, as an array.
#   - "encode refuses a table answering any other metatable" -> encode
#     refuses a subclass of list, tuple or dict and a mapping that is not
#     a dict or the core's FrozenDict (MappingProxyType, OrderedDict).
#   - "a marked array with extra keys is still refused" -> a list
#     subclass carrying an attribute is still refused.
# What kit.data's functions are reached by is an import, not an event:
# module namespaces are writable globals (py-core-api SUBSTRATE,
# convention, as Lua's module tables); the namespace probe above shows
# that kit.data itself writes none.
# Cannot apply: none.
import sys
sys.dont_write_bytecode = True  # no __pycache__ in the project tree

import collections  # noqa: E402
import importlib.util  # noqa: E402
import os  # noqa: E402
import types  # noqa: E402

HERE = os.path.dirname(os.path.abspath(__file__))
CODE = os.path.normpath(os.path.join(HERE, "..", "code"))
sys.path.insert(0, CODE)

from siso import harness as H  # noqa: E402
from siso.core import Event  # noqa: E402
from siso.kit import data  # noqa: E402

test, assert_, assertEqual, assertThrows = H.test, H.assert_, H.assertEqual, H.assertThrows
null, array = data.null, data.array

PLAIN = (dict, list, tuple, str, int, float, bool, type(None))


def reach(v):
    """Every object reachable from v by items (keys and values), v included; iterative."""
    out, seen, stack = [], set(), [v]
    while stack:
        x = stack.pop()
        if id(x) in seen:
            continue
        seen.add(id(x))
        out.append(x)
        if type(x) is dict:
            for k, y in x.items():
                stack.extend((k, y))
        elif type(x) in (list, tuple):
            stack.extend(x)
    return out


def handedOut():
    return [
        null, array([]), array([1, array([null])]),
        data.decode('{"a":[1,[],{"b":null}],"n":null,"o":{"l":[[true]]}}')[0],
        data.decode("[]")[0], data.decode("null")[0],
        data.encode({"a": [1]}), data.decode('"x"'), data.decode("[1"),
    ]


# ── what null and arrays are ────────────────────────────────────────

def t_null():
    assert_(null is None, "data.null is None")
    assert_(data.decode("null")[0] is None, "decoded null")


test("data.null is None (no sentinel object)", t_null)


def t_arraySame():
    for label, t in (("one member", [1]), ("empty", []), ("a tuple", (1,))):
        before = type(t)
        assert_(array(t) is t, label)
        assert_(type(t) is before, label + ": its type unchanged")
        assert_(not hasattr(t, "__dict__"), label + ": nothing to attach to")
    assertThrows(lambda: array(), "argument", "no argument")


test("data.array returns the very list it was given, its type unchanged", t_arraySame)


def t_decoded():
    v = data.decode('{"a":[1,[],{"b":null}],"n":null}')[0]
    assert_(type(v["a"]) is list)
    assert_(type(v["a"][1]) is list)
    assert_(type(v["a"][2]) is dict, "an object is a plain dict")
    assert_(v["n"] is None and v["a"][2]["b"] is None, "decoded null is None")


test("decoded arrays are lists, objects dicts, decoded null is None", t_decoded)


def t_onlyPlain():
    for i, h in enumerate(handedOut()):
        for o in reach(h):
            assert_(type(o) in PLAIN, "value " + str(i) + " reaches a " + type(o).__name__)
    a = data.decode('{"o":{},"l":[]}')[0]
    b = data.decode('{"o":{},"l":[]}')[0]
    assert_(a is not b and a["o"] is not b["o"] and a["l"] is not b["l"], "two decodes share an object")
    e1, e2 = data.decode("[]")[0], data.decode("[]")[0]
    assert_(e1 is not e2, "two decodes of [] share a list")


test("nothing reachable from what siso.kit.data hands out is anything but plain data, and nothing is shared",
     t_onlyPlain)


def countThroughKit(inp):
    """py-core-api checkReadonly's probe: a gate that counts through what
    its input's objects and their classes hand out."""
    n = 0
    targets = []
    for o in reach(inp):
        targets.extend((o, type(o)))
    for t in targets:
        try:
            setattr(t, "sisoCount", getattr(t, "sisoCount", 0) + 1)
            n += getattr(t, "sisoCount")
        except (AttributeError, TypeError):
            pass  # refused: nothing counted
    return n


def t_count():
    def inp():
        return {"l": array([null, array([])]), "d": data.decode("[null,[1]]")[0], "n": null,
                "t": data.decode('{"k":[1.5,"s",true]}')[0]}

    countThroughKit(inp())
    assertEqual(countThroughKit(inp()), 0, "second call")


test("a gate cannot carry a count from one call to the next through what siso.kit.data hands out", t_count)


def t_noMark():
    class Other(list):
        pass

    def reassign(o, cls):
        o.__class__ = cls

    assertThrows(lambda: reassign([], Other), "__class__", "a list's class")
    assertThrows(lambda: reassign((), Other), "__class__", "a tuple's class")
    assertThrows(lambda: reassign(None, Other), "__class__", "None's class")
    assertThrows(lambda: setattr(None, "mark", "kit.data.array"), "NoneType", "None takes no attribute")
    assertThrows(lambda: setattr([], "mark", "kit.data.array"), "list", "a list takes no attribute")
    before = dict(vars(data))
    for v in handedOut():
        data.encode(v)
    data.decode("[1,[2,{\"a\":null}]]")
    data.array([])
    data.isArray(())
    after = vars(data)
    assertEqual(sorted(after), sorted(before), "names in kit.data's namespace")
    for k, v in before.items():
        assert_(after[k] is v, "kit.data." + k + " was rebound by a call")


test("there is no mark to replace or strip, and calls write nothing into kit.data", t_noMark)


# ── null cannot be written ──────────────────────────────────────────

def t_nullWrites():
    assertThrows(lambda: setattr(null, "x", 1), "NoneType")
    assertThrows(lambda: null.__setitem__(1, True), "NoneType")
    assertEqual(data.encode(null), ("null", None))
    assertEqual(str(null), "None", "rendering is Python's, encoding the kit's")


test("data.null refuses assignment and still encodes as null", t_nullWrites)


# ── data.array and isArray ──────────────────────────────────────────

def t_arrayOnArray():
    t = [1]
    assert_(array(t) is t)
    d = data.decode("[1]")[0]
    assert_(array(d) is d, "a decoded array")


test("data.array on a list returns that list", t_arrayOnArray)


def t_arrayFrozen():
    c = Event("t", {"l": [1, 2]}).data["l"]
    assert_(type(c) is tuple, "the core froze it into a tuple")
    assert_(array(c) is c, "the core's frozen list")


test("data.array on a frozen list (the core's tuple) returns it", t_arrayFrozen)


def t_arrayRefuses():
    class ListSub(list):
        pass

    def gen():
        yield 1

    Point = collections.namedtuple("Point", "x y")
    for label, v in (
        ("a dict", {}),
        ("an event's data (a FrozenDict)", Event("t", {"k": 1}).data),
        ("a str (the old mark's name)", "kit.data.array"),
        ("None", None),
        ("a list subclass", ListSub([1])),
        ("a namedtuple", Point(1, 2)),
        ("a set", {1}),
        ("a generator", gen()),
    ):
        try:
            array(v)
            H.fail(label + ": accepted")
        except TypeError as err:
            assert_("not a list or a tuple" in str(err), label + ": " + H.show(str(err)))


test("rejection: data.array raises TypeError on anything that is not an exact list or tuple", t_arrayRefuses)


def t_isArray():
    class ListSub(list):
        pass

    assert_(data.isArray(array([])))
    assert_(data.isArray(()), "a tuple")
    assert_(data.isArray(Event("t", {"l": [1]}).data["l"]), "a frozen list")
    assert_(data.isArray(data.decode("[1]")[0]), "a decoded list")
    assert_(not data.isArray(ListSub([1])), "a list subclass")
    assert_(not data.isArray(collections.namedtuple("P", "x")(1)), "a namedtuple")
    assert_(not data.isArray(Event("t", {"k": 1}).data), "a FrozenDict")
    assert_(not data.isArray(null), "None")
    assert_(not data.isArray("kit.data.array"), "a str")
    assert_(not data.isArray(range(2)), "a range")


test("isArray: True exactly for exact lists and tuples", t_isArray)


def loadSecond():
    spec = importlib.util.spec_from_file_location("siso_kit_data_second",
                                                  os.path.join(CODE, "siso", "kit", "data.py"))
    mod = importlib.util.module_from_spec(spec)
    spec.loader.exec_module(mod)
    return mod


def t_secondInstance():
    other = loadSecond()
    assert_(other is not data, "a separate module instance")
    a = array([1])
    assert_(other.isArray(a), "the other instance sees this one's array")
    assert_(data.isArray(other.array([])), "this instance sees the other's array")
    assertEqual(other.encode(a), ("[1]", None))
    assert_(other.array(a) is a, "the other's data.array returns this one's array")
    assert_(other.null is data.null, "one null")
    assertEqual(data.encode(other.decode('{"l":[null]}')[0]), ('{"l":[null]}', None),
                "the other's decoded value encodes here")


test("nothing carries identity: a second load of kit.data agrees on arrays and null", t_secondInstance)


# ── encode's type rule ──────────────────────────────────────────────

def t_encodeTuple():
    assertEqual(data.encode(()), ("[]", None))
    assertEqual(data.encode((1, "x", null)), ('[1,"x",null]', None))
    assertEqual(data.encode({"a": Event("t", {"l": [2.5]}).data["l"]}), ('{"a":[2.5]}', None), "the core's tuple")


test("encode reads a tuple, the core's frozen list, as an array", t_encodeTuple)


def t_encodeRefuses():
    class ListSub(list):
        pass

    class TupleSub(tuple):
        pass

    class DictSub(dict):
        pass

    for label, v, fragment in (
        ("a list subclass", ListSub([1]), "(a subclass of list)"),
        ("a tuple subclass", TupleSub((1,)), "(a subclass of tuple)"),
        ("a namedtuple", collections.namedtuple("P", "x")(1), "(a subclass of tuple)"),
        ("a dict subclass", DictSub(k=1), "(a subclass of dict)"),
        ("an OrderedDict", collections.OrderedDict(k=1), "(a subclass of dict)"),
        ("a MappingProxyType", types.MappingProxyType({"k": 1}), "a mappingproxy is not data"),
        ("a range", range(1), "a range is not data"),
    ):
        s, why = data.encode(v)
        assertEqual(s, None, label)
        assert_(type(why) is str and fragment in why, label + ": " + H.show(why))


test("rejection: encode refuses a subclass of list, tuple or dict and any other mapping", t_encodeRefuses)


def t_extraKeys():
    class Marked(list):
        pass

    t = Marked([1])
    t.x = 2
    s, why = data.encode(t)
    assertEqual(s, None)
    assert_(type(why) is str and "is not data (a subclass of list)" in why, H.show(why))


test("rejection: a list with extra keys (a subclass carrying an attribute) is still refused", t_extraKeys)

H.finish("kit-data-stateless")
