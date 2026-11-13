# kit-data -- py/code/siso/kit/data.py (canonical JSON; null and arrays),
# port of lua/sisoTests/kit-data.test.lua case by case, then the
# py-kit-api module check on the real siso.kit.data.
#   python3 -B py/sisoTests/kit-data.test.py     (from any directory)
# Expected values come from outside the code: float texts are
# ECMAScript's Number::toString (Node 22, `String(x)`) with ".0" appended
# to integral values so the float type survives, and "-0.0" for negative
# zero: the Lua suite's table, pasted from Node's output in jaa Phase 2,
# unchanged (Python keeps Lua's integer/float split, so the canonical
# text is the Lua kit's byte for byte; py-kit-api SUBSTRATE).
#
# Ported as Python's counterpart (py-kit-api SUBSTRATE: Lua's marks
# vanish; an array is a list or a tuple, JSON null is None):
#   - "null: renders as null, cannot be written to, answers
#     readonly:kit.data.null" -> data.null is None: it encodes as "null",
#     takes no attribute, and there is no sentinel to answer anything.
#   - "array: marks, is idempotent, rejects a foreign metatable" ->
#     data.array returns the very list or tuple it was given, unchanged,
#     and raises TypeError on anything else (a subclass included).
#   - "array: a table that answers the mark through getmetatable" -> a
#     frozen list (the tuple an event's data holds) is an array.
#   - "encode: booleans and integers" adds "bool before int": True is
#     "true", never 1.
#   - "invalid UTF-8 is rejected" (bytes) -> a lone surrogate is rejected
#     (a Python str holds code points; one that is a surrogate has no
#     UTF-8 form); bytes are not data at all.
#   - "a table with holes or mixed keys is rejected" -> a dict key that is
#     not a str ({2: "x"}, {1.5: "x"}, {True: 1}) is rejected; a list
#     cannot have holes or named keys.
#   - "a marked array with a hole or extra keys is rejected" -> a list
#     subclass carrying an attribute (the only list with "extra keys") is
#     rejected, as is any list or tuple subclass.
#   - "values that are not data": nil, userdata, a thread, a foreign
#     metatable and a boolean key -> None is null (not an error: Python
#     has no nil apart from null); a file object, a generator, a class
#     instance, a set, bytes, a subclass of a builtin (OrderedDict,
#     namedtuple, IntEnum, a str subclass), a MappingProxyType, an Event,
#     a bool and a tuple key, and cycles through a dict and a list.
#   - "the modules require nothing outside code/kit/" -> data.py imports
#     only the standard library and siso.core (for the core's FrozenDict,
#     the one non-standard type it reads; py-kit-api canonical form).
#   - "exports are exactly the contract's" -> the module's public names
#     (and __all__) are exactly py-kit-api EXPORTS["data"].
#   - error paths index arrays from 0 ($.x[1], $.a.b[1].c); decode errors
#     name a str index ("at index N"), not a byte.
#   - "decode: arrays come back marked; objects plain" -> arrays come back
#     as lists, objects as dicts (exact types).
#   - "a non-string input is an error, not a raise" -> None and bytes.
#   - "round trip: value -> text -> an equal value (types kept)" uses the
#     harness's strict assertEqual (1 and 1.0 and True differ) and checks
#     the sign of -0.0 apart (== does not see it).
# Cannot apply (not ported, py-kit-api SUBSTRATE: kit.sha256 is not part
# of the Python kit): every sha256 case -- the empty message, "abc", the
# 448- and 896-bit messages, one million 'a', the 14 padding-boundary
# lengths, all 256 byte values, the hex shape, independent calls and the
# non-string raise. The kit-api check runs on data alone (no sha256 or
# helper module to pass it), here without and in kit-data-frozen with
# freeze and Event.
import sys
sys.dont_write_bytecode = True  # no __pycache__ in the project tree

import ast  # noqa: E402
import collections  # noqa: E402
import enum  # noqa: E402
import math  # noqa: E402
import os  # noqa: E402
import struct  # noqa: E402
import time  # noqa: E402
import types  # noqa: E402

HERE = os.path.dirname(os.path.abspath(__file__))
CODE = os.path.normpath(os.path.join(HERE, "..", "code"))
sys.path.insert(0, CODE)

from siso import harness as H  # noqa: E402
from siso.contracts import kit_api as K  # noqa: E402
from siso.core import Event  # noqa: E402
from siso.kit import data  # noqa: E402

test, assert_, assertEqual, assertThrows = H.test, H.assert_, H.assertEqual, H.assertThrows
null, array = data.null, data.array


def enc(v):
    r = data.encode(v)
    assert_(type(r) is tuple and len(r) == 2, "encode returns (text, why)")
    s, err = r
    if s is None:
        H.fail("encode failed: " + str(err))
    assertEqual(err, None, "why on success")
    return s


def encErr(v, fragment):
    r = data.encode(v)
    assert_(type(r) is tuple and len(r) == 2, "encode returns (text, why)")
    s, err = r
    assertEqual(s, None, "encode should fail")
    assert_(type(err) is str and fragment in err, "error " + H.show(err) + " lacks '" + fragment + "'")


def dec(s):
    v, err = data.decode(s)
    if err is not None:
        H.fail("decode failed: " + err)
    return v


def decErr(s, fragment):
    r = data.decode(s)
    assert_(type(r) is tuple and len(r) == 2, "decode returns (value, why)")
    v, err = r
    assertEqual(v, None, "decode of " + H.show(s)[:60] + " should fail")
    assert_(type(err) is str and fragment in err, "error " + H.show(err) + " lacks '" + fragment + "'")


def isNegZero(x):
    return type(x) is float and x == 0 and math.copysign(1.0, x) < 0


# ── exports and the contract ────────────────────────────────────────

def t_check():
    p = K.check(data)
    assert_(not p, "; ".join(p))


test("kit-api check passes on the real data module", t_check)


def t_exports():
    want = set(K.EXPORTS["data"])
    public = {k for k in vars(data) if not k.startswith("_")}
    for k in sorted(public - want):
        H.fail("kit.data." + k + " is not in kit-api")
    for k in sorted(want - public):
        H.fail("kit.data." + k + " is missing")
    assertEqual(sorted(data.__all__), sorted(want), "__all__")


test("exports are exactly the contract's", t_exports)


def t_imports():
    path = os.path.join(CODE, "siso", "kit", "data.py")
    with open(path, encoding="utf-8") as f:
        tree = ast.parse(f.read(), path)
    found = []
    for node in ast.walk(tree):
        if isinstance(node, ast.Import):
            found.extend(a.name for a in node.names)
        elif isinstance(node, ast.ImportFrom):
            assert_(node.level == 0, "data.py has a relative import")
            found.append(node.module)
        elif isinstance(node, ast.Call) and isinstance(node.func, ast.Name) and node.func.id in ("__import__", "exec", "eval"):
            H.fail("data.py calls " + node.func.id)
    for name in found:
        top = name.split(".")[0]
        assert_(top in sys.stdlib_module_names or name == "siso.core", "data.py imports " + name)
    assert_("siso.core" in found, "data.py reads the core's FrozenDict from siso.core")


test("the module imports only the standard library and siso.core", t_imports)


# ── null and arrays ─────────────────────────────────────────────────

def t_null():
    assert_(null is None, "data.null is None")
    assertEqual(enc(null), "null")
    assertThrows(lambda: setattr(null, "x", 1), "NoneType", "None takes no attribute")
    assertEqual(dec("null"), None)


test("null: data.null is None, encodes as null, cannot be written to", t_null)


def t_array():
    t = [1]
    assert_(data.isArray(t))
    assert_(array(t) is t, "the same list")
    assert_(type(t) is list and t == [1], "unchanged")
    assert_(array(array(t)) is t, "idempotent")
    p = (1, 2)
    assert_(array(p) is p, "the same tuple")
    assert_(data.isArray(array([])))
    assert_(data.isArray([1, 2]), "every list is an array (no mark)")
    assert_(not data.isArray({}))
    assert_(not data.isArray("x"))
    assert_(not data.isArray(null))

    class ListSub(list):
        pass

    for label, v in (("a dict", {}), ("None", None), ("a str", "x"), ("a list subclass", ListSub())):
        try:
            array(v)
            H.fail("data.array accepted " + label)
        except TypeError as err:
            assert_("not a list or a tuple" in str(err), str(err))


test("array: returns the same list or tuple unchanged, raises TypeError on anything else", t_array)


def t_frozenArray():
    # what the core hands out (kit-api: frozen data reads as plain)
    f = Event("t", {"l": [1]}).data["l"]
    assert_(type(f) is tuple, "a frozen list is a tuple")
    assert_(data.isArray(f))
    assert_(array(f) is f)


test("array: a frozen list (an event's tuple) is an array, and data.array returns it", t_frozenArray)


# ── encoding scalars ────────────────────────────────────────────────

def t_scalars():
    assertEqual(enc(True), "true")
    assertEqual(enc(False), "false")
    assertEqual(enc(0), "0")
    assertEqual(enc(-17), "-17")
    assertEqual(enc(2 ** 63 - 1), "9223372036854775807")
    assertEqual(enc(-2 ** 63), "-9223372036854775808")
    assertEqual(enc([True, 1, False, 0]), "[true,1,false,0]", "bool before int")
    encErr(2 ** 63, "64-bit")
    encErr(-2 ** 63 - 1, "64-bit")


test("encode: booleans and integers", t_scalars)

FLOATS = (
    # value, Node 22 String(x) (+ ".0" when integral)
    (1.0, "1.0"),
    (0.1, "0.1"),
    (0.1 + 0.2, "0.30000000000000004"),
    (100.0, "100.0"),
    (1.5, "1.5"),
    (-2.5, "-2.5"),
    (1e20, "100000000000000000000.0"),
    (1e21, "1e+21"),
    (1.5e21, "1.5e+21"),
    (1e-6, "0.000001"),
    (1.5e-6, "0.0000015"),
    (1e-7, "1e-7"),
    (1.23e-7, "1.23e-7"),
    (2.0 ** 53, "9007199254740992.0"),
    (5e-324, "5e-324"),
    (1.7976931348623157e308, "1.7976931348623157e+308"),
    (123456789012345680000.0, "123456789012345680000.0"),
    (0.0, "0.0"),
    (-0.0, "-0.0"),
    # exact powers of two: the rounding interval is lopsided, so the
    # shortest form can be a neighbour of the correctly rounded digits
    (2.0 ** -1017, "7.120236347223045e-307"),
    (2.0 ** 896, "5.282945311356653e+269"),
    (2.0 ** -1022, "2.2250738585072014e-308"),
    (2.0 ** 1023, "8.98846567431158e+307"),
    (-(2.0 ** -1000), "-9.332636185032189e-302"),
)

for x, text in FLOATS:
    def t_float(x=x, text=text):
        assertEqual(enc(x), text)
        assertEqual(enc([x]), "[" + text + "]")

    test("encode float " + text, t_float)


def t_intFloat():
    assertEqual(enc([1, 1.0, -0.0, 0]), "[1,1.0,-0.0,0]")


test("encode: integers and floats are kept apart", t_intFloat)


def t_nonFinite():
    encErr(float("nan"), "NaN")
    encErr(float("inf"), "Infinity")
    encErr(float("-inf"), "Infinity")
    encErr({"x": [1.0, float("inf")]}, "$.x[1]")


test("encode: NaN and infinities are rejected", t_nonFinite)


def t_escapes():
    assertEqual(enc('a"b\\c'), '"a\\"b\\\\c"')
    assertEqual(enc("\b\f\n\r\t"), '"\\b\\f\\n\\r\\t"')
    assertEqual(enc("\x00\x01\x1f"), '"\\u0000\\u0001\\u001f"')
    assertEqual(enc("\x7f/"), '"\x7f/"', "DEL and '/' are not escaped")
    assertEqual(enc("hé \U0001F600"), '"hé \U0001F600"', "UTF-8 as is")


test("encode: string escapes", t_escapes)


def t_surrogates():
    encErr("\ud800", "UTF-8")
    encErr("\udc00", "lone surrogate")
    encErr("a\ude00\ud83d", "lone surrogate")  # a pair in the wrong order
    encErr({"\ud800": 1}, "UTF-8")
    encErr({"\ud800": 1}, "at $.<key>")
    encErr(b"\xff", "bytes is not data")
    assertEqual(enc("\U0001F600"), '"\U0001F600"', "a code point beyond the BMP is one character")


test("encode: a lone surrogate is rejected (not valid UTF-8)", t_surrogates)


# ── encoding objects and arrays ─────────────────────────────────────

def t_keyOrder():
    assertEqual(enc({"b": 1, "a": 2, "B": 3, "é": 4, "z": 5, "": 6}),
                '{"":6,"B":3,"a":2,"b":1,"z":5,"é":4}')
    assertEqual(enc({"ab": 1, "a": 2, "abcdefghij": 3, "abcdefghi": 4, "abcdefghik": 5}),
                '{"a":2,"ab":1,"abcdefghi":4,"abcdefghij":3,"abcdefghik":5}', "prefixes and 8-byte chunks")
    # UTF-16 order would put U+1F600 (a surrogate pair) before U+FF61
    assertEqual(enc({"\U0001F600": 1, "｡": 2, "": 3}), '{"":3,"｡":2,"\U0001F600":1}', "beyond the BMP")


test("encode: object keys sorted by code point (UTF-8 byte order)", t_keyOrder)


def t_sameText():
    keys = ["k" + str((i * 7919) % 1000) for i in range(1, 201)]
    a, b = {}, {}
    for i, k in enumerate(keys):
        a[k] = i
    for i in range(len(keys) - 1, -1, -1):
        b[keys[i]] = i
    assert_(list(a) != list(b), "the two dicts were built in different orders")
    assertEqual(enc(a), enc(b))


test("encode: the same object always gives the same text", t_sameText)


def t_arrays():
    assertEqual(enc([1, 2, 3]), "[1,2,3]")
    assertEqual(enc((1, 2, 3)), "[1,2,3]", "a tuple is an array")
    assertEqual(enc(array(["x", null, 1.0])), '["x",null,1.0]')
    assertEqual(enc([]), "[]", "an empty list is an array (no mark needed)")
    assertEqual(enc(()), "[]")
    assertEqual(enc({}), "{}", "an empty dict is an object")


test("encode: arrays and objects, empty ones included", t_arrays)


def t_nested():
    assertEqual(enc({"rows": [[1, "a"], [2, null]], "columns": ["id", "v"], "n": 2}),
                '{"columns":["id","v"],"n":2,"rows":[[1,"a"],[2,null]]}')


test("encode: nested structures", t_nested)


def t_keys():
    encErr({2: "x"}, "not a string")
    encErr({1.5: "x"}, "not a string")
    encErr({True: 1}, "object key True is not a string")
    encErr({True: 1, "a": 2}, "not a string")
    encErr({None: 1}, "not a string")
    encErr({("a",): 1}, "object key a tuple is not a string")


test("encode: a key that is not a str is rejected", t_keys)


def t_listExtra():
    class Marked(list):
        pass

    t = Marked([1, 2])
    t.x = 1
    encErr(t, "is not data (a subclass of list)")
    encErr(Marked([1]), "is not data (a subclass of list)")


test("encode: a list subclass (a list with extra keys) is rejected", t_listExtra)


def t_notData():
    class Opaque:
        def __init__(self):
            self.v = 1

    class StrSub(str):
        pass

    class Level(enum.IntEnum):
        LOW = 1

    def gen():
        yield 1

    Point = collections.namedtuple("Point", "x y")
    cases = (
        ({"f": lambda: 1}, "function"),
        (len, "builtin_function_or_method"),
        (sys.stdout, "is not data"),
        (gen(), "generator"),
        (Opaque(), "an Opaque is not data"),
        ({1}, "a set is not data"),
        (frozenset(), "frozenset"),
        (b"x", "bytes"),
        (bytearray(b"x"), "bytearray"),
        (1j, "complex"),
        (collections.OrderedDict(k=1), "an OrderedDict is not data (a subclass of dict)"),
        (Point(1, 2), "a Point is not data (a subclass of tuple)"),
        (Level.LOW, "(a subclass of int)"),
        (StrSub("s"), "(a subclass of str)"),
        (types.MappingProxyType({"k": 1}), "mappingproxy"),
        (Event("t"), "an Event is not data"),
        ({"e": Event("t", {"k": 1})}, "at $.e"),
        (int, "is not data"),
    )
    for v, fragment in cases:
        encErr(v, fragment)
    c = {}
    c["self"] = c
    encErr(c, "cycle")
    lst = []
    lst.append(lst)
    encErr(lst, "cycle")
    encErr({"a": [{"b": lst}]}, "cycle at $.a[0].b[0]")


test("encode: values that are not data are rejected", t_notData)


def nest(n):
    v = 1
    for _ in range(n):
        v = [v]
    return v


def t_depth():
    text = enc(nest(512))
    assertEqual(data.decode(text)[1], None, "512 levels decode")
    encErr(nest(513), "nesting too deep")
    decErr("[" * 513 + "]" * 513, "too deep")


test("encode: nesting up to 512 levels, as decode allows", t_depth)


def t_deepFailure():
    v = nest(20000)
    t0 = time.process_time()
    encErr(v, "nesting too deep")
    assert_(time.process_time() - t0 < 1.0, "fast failure")


test("encode: a deep failure costs little (paths are built lazily)", t_deepFailure)


def t_path():
    encErr({"a": {"b": [1, {"c": float("nan")}]}}, "at $.a.b[1].c")


test("encode: the error names the path", t_path)


def t_shared():
    shared = [1]
    assertEqual(enc({"a": shared, "b": shared}), '{"a":[1],"b":[1]}')
    assertEqual(enc([shared, (shared, shared)]), "[[1],[[1],[1]]]")


test("encode: the same list in two places is fine (not a cycle)", t_shared)


def t_noModify():
    v = {"b": [1, 2], "a": "x"}
    enc(v)
    assertEqual(v, {"b": [1, 2], "a": "x"})
    assertEqual(list(v), ["b", "a"], "key order untouched")
    assert_(type(v["b"]) is list, "a list stays a list")


test("encode: does not modify its input", t_noModify)


# ── decoding ────────────────────────────────────────────────────────

def t_decodeScalars():
    assertEqual(dec("true"), True)
    assertEqual(dec("false"), False)
    assert_(dec("null") is null)
    assertEqual(data.decode("null"), (None, None), "decode('null') is (None, None)")
    assertEqual(dec("12"), 12)
    assertEqual(dec("-0"), 0)
    assertEqual(dec("1.0"), 1.0)
    assertEqual(dec("1e2"), 100.0)
    assertEqual(dec("1E-2"), 0.01)
    assertEqual(dec('"x"'), "x")
    assert_(type(dec("12")) is int, "an integer")
    assert_(type(dec("1e2")) is float, "a float")
    assert_(isNegZero(dec("-0.0")), "-0.0 keeps its sign")
    assert_(not isNegZero(dec("-0")), '"-0" is the integer 0')


test("decode: scalars and types", t_decodeScalars)


def t_decodeLimits():
    assertEqual(dec("9223372036854775807"), 2 ** 63 - 1)
    assertEqual(dec("-9223372036854775808"), -2 ** 63)
    decErr("9223372036854775808", "64-bit")
    decErr("-9223372036854775809", "64-bit")
    decErr("123456789012345678901234", "64-bit")
    decErr("1" * 5000, "64-bit")


test("decode: 64-bit integer limits", t_decodeLimits)


def t_decodeArrays():
    v = dec('{"a":[1,[],{}],"b":{}}')
    assert_(data.isArray(v["a"]))
    assert_(data.isArray(v["a"][1]))
    assert_(not data.isArray(v["a"][2]))
    assert_(not data.isArray(v))
    assert_(type(v) is dict and type(v["a"]) is list and type(v["a"][2]) is dict, "exact dicts and lists")
    assertEqual(len(v["a"]), 3)


test("decode: arrays come back as lists; objects as dicts", t_decodeArrays)


def t_decodeNulls():
    v = dec("[null,null,3]")
    assertEqual(len(v), 3)
    assert_(v[0] is null and v[1] is null)


test("decode: nulls inside arrays keep the length", t_decodeNulls)


def t_decodeEscapes():
    assertEqual(dec('"a\\"b\\\\c\\/d\\b\\f\\n\\r\\t"'), 'a"b\\c/d\b\f\n\r\t')
    assertEqual(dec('"\\u00e9\\u0041\\u0000"'), "éA\x00")
    assertEqual(dec('"\\ud83d\\ude00"'), "\U0001F600")
    assertEqual(dec('"\\uD83D\\uDE00"'), "\U0001F600", "upper-case hex")


test("decode: string escapes and \\u, including surrogate pairs", t_decodeEscapes)


def t_decodeBadStrings():
    decErr('"\\ud83d"', "surrogate")
    decErr('"\\ude00"', "surrogate")
    decErr('"\\ud83d\\u0041"', "surrogate")
    decErr('"\\x"', "bad escape")
    decErr('"\\u12"', "\\u")
    decErr('"a\nb"', "control character")
    decErr('"abc', "unterminated")
    decErr('"\ud800"', "UTF-8")  # a raw lone surrogate, not an escape


test("decode: bad strings are rejected", t_decodeBadStrings)


def t_decodeBadNumbers():
    decErr("01", "leading zero")
    decErr("-01", "leading zero")
    decErr("1.", "fraction")
    decErr(".5", "unexpected character")
    decErr("1e", "exponent")
    decErr("+1", "unexpected character")
    decErr("-", "bad number")
    decErr("1E400", "out of range")
    decErr("NaN", "unexpected character")
    decErr("Infinity", "unexpected character")
    decErr("١", "unexpected character")  # a digit outside ASCII is not a JSON digit


test("decode: bad numbers are rejected", t_decodeBadNumbers)


def t_decodeStructure():
    decErr("", "end of input")
    decErr("   ", "end of input")
    decErr("[1,]", "unexpected character")
    decErr('{"a":1,}', "string key")
    decErr('{"a":1,"a":2}', "duplicate key")
    decErr('{"a" 1}', "':'")
    decErr("[1 2]", "','")
    decErr("{1:2}", "string key")
    decErr("[1] x", "trailing")
    decErr("// c\n1", "unexpected character")
    decErr("tru", "unexpected character")
    decErr("[" * 600 + "]" * 600, "too deep")


test("decode: structure errors", t_decodeStructure)


def t_decodeWhitespace():
    v = dec(' \n\t{ "a" : [ 1 , 2 ] ,\r\n "b" : null } \n')
    assertEqual(enc(v), '{"a":[1,2],"b":null}')


test("decode: whitespace between tokens is allowed", t_decodeWhitespace)


def t_decodeNotStr():
    v, err = data.decode(None)
    assertEqual(v, None)
    assert_("must be a string" in err, err)
    assert_("must be a string" in data.decode(b"1")[1], "bytes are not a str")


test("decode: a non-str input is an error, not a raise", t_decodeNotStr)


# ── round trips and canonical form ──────────────────────────────────

def t_roundTripText():
    texts = (
        '{"a":[1,2.5,"x",null,true,false],"b":{"c":{}},"d":[]}',
        '[[],[[]],{"":""}]',
        '{"n":-9223372036854775808,"f":-1.5e-300}',
    )
    for t in texts:
        canonical = enc(dec(t))
        assertEqual(enc(dec(canonical)), canonical)
    assertEqual(enc(dec(texts[0])), texts[0])


test("round trip: canonical text -> value -> the same text", t_roundTripText)


def t_canonicalization():
    assertEqual(enc(dec('{ "b" : 1E2, "a" : 0.50 }')), '{"a":0.5,"b":100.0}')


test("canonicalization: key order, whitespace and number spelling", t_canonicalization)


def t_roundTripValue():
    v = {"rows": array([array([1, 1.0, "1", null, True, -0.0]), array([])]), "meta": {}}
    back = dec(enc(v))
    assertEqual(back, v)
    assert_(isNegZero(back["rows"][0][5]), "-0.0 keeps its sign")


test("round trip: value -> text -> an equal value (types kept)", t_roundTripValue)


def t_floatsBitExact():
    seed = 7
    checked = 0
    for i in range(1, 10001):
        seed = (seed * 6364136223846793005 + 1442695040888963407) & 0xFFFFFFFFFFFFFFFF
        x = struct.unpack("<d", struct.pack("<Q", seed))[0]
        if x == x and x != float("inf") and x != float("-inf"):
            y = dec(enc(x))
            assert_(type(y) is float and struct.pack("<d", y) == struct.pack("<d", x),
                    "float " + float.hex(x) + " at " + str(i))
            checked += 1
    assert_(checked > 9900, "only " + str(checked) + " floats checked")


test("round trip: 10,000 floats come back bit-exact", t_floatsBitExact)

H.finish("kit-data")
