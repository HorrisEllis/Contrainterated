"""contract: kit-api -- what the kit promises every Python project that
vendors it (core 13, Python instance), and this project's own fixtures
runner. Port of lua/code/contracts/kit_api.lua. Only a contracts phase
changes this file.

Between: kit -> fixtures (and consumers outside this project).

MODULES (import root py/code/; consumers vendor py/code/siso/core/,
py/code/siso/kit/ and the shared harness py/code/siso/harness.py)

  siso.kit.data   plain data and its canonical JSON (decision 5)
    data.null              None: JSON null is Python's None (a value; no
                           sentinel)
    data.array(t) -> t     t itself when it is a list or a tuple (exact
                           types; an empty one too), unchanged; anything
                           else raises TypeError
    data.isArray(v)        True for a list or a tuple (exact types; a
                           frozen list in an event's data is a tuple)
    data.encode(v) -> (text, None) | (None, why)
                           canonical JSON; errors returned, never raised
    data.decode(s) -> (value, None) | (None, why)
                           strict RFC 8259; errors returned, never raised;
                           decode("null") is (None, None)
    Canonical form (the Lua kit.data header's, mapped):
      - objects are dict or the core's FrozenDict with str keys, sorted
        by code point (Python's str order, which is UTF-8 byte order, as
        Lua sorts); arrays are list or tuple; no whitespace anywhere;
      - bool before int: True is "true", never 1;
      - integers as digits, and they must fit in 64 bits (Lua's
        integers: -2**63 .. 2**63-1), else refused;
      - floats finite, as the shortest round-trip digits (repr gives
        them), laid out as ECMAScript's Number::toString (decimal when
        1e-6 <= |x| < 1e21, else d.ddde+n / d.ddde-n), always keeping a
        "." or an exponent so the type survives: 1.0 is "1.0", 1e21 is
        "1e+21", 1e-7 is "1e-7", -0.0 is "-0.0", 2.0**53 is
        "9007199254740992.0"; NaN and +-inf are refused;
      - strings: `"` `\\` and code points below 0x20 escaped (\\b \\f \\n
        \\r \\t, else \\u00xx, lowercase hex); everything else as is
        (UTF-8); a lone surrogate is refused (not valid UTF-8);
      - exact types only: a subclass of dict, list, tuple, str, int or
        float, a set, bytes, a function, a class instance, an Event, a
        key that is not a str and a cycle are refused; nesting is limited
        to 512 arrays/objects, in both directions; iterative.
      Frozen data (core-api READ-ONLY) is encoded as the plain data it
      presents: encode(Event("t", v).data) == encode(v), so residue,
      samples and observer records can be serialised as they are handed
      out.
    decode: strict RFC 8259 (no trailing commas, comments, NaN, Infinity,
      leading zeros, duplicate keys, raw control characters in strings;
      the whole input is one value, whitespace around it allowed).
      Objects come back as dicts, arrays as lists, null as None, an
      integer literal (no "." or exponent) as an int that must fit in 64
      bits, any other number as a float; \\u escapes are decoded with
      surrogate pairs joined, and a lone surrogate is an error.
    Nothing siso.kit.data hands out gives a gate an object it could write
    and later read back: it keeps no state.

  Command line (run from the project root):
    python3 py/code/siso/kit/run.py [tests-dir]
                           every *.test.py under the tests directory
                           (default py/sisoTests) at any depth, except
                           under a directory named fixtures or ending in
                           -fixtures or _fixtures, each in its own process
                           (sys.executable -B <file>, so no
                           __pycache__ is written), in byte-wise path
                           order; a suite passes when it exits 0 and its
                           last "<suite>: P/T passed" line has P = T and no
                           FAILED. Final line "run: S suites, P/T passed"
                           (or ", F FAILED (files...)"); exit 0 only when
                           all pass.

SUBSTRATE (Python; what the Lua kit-api holds that this one does not)
  - Not ported (tooling for Lua gate authors): kit.helper (the per-gate
    test API), kit.sha256, kit.digest, kit.canon, kit.census, kit.lint.
    The kit's command line is the suite runner alone, so there is no
    register list, no gate group and no digest line; their exports and
    the sha256 probe have no counterpart here.
  - Lua's marks vanish: an array is a list or a tuple (no data.array
    metatable), JSON null is None (no "readonly:kit.data.null"
    sentinel), so the Lua probes "getmetatable(data.null)" and "a marked
    array answers getmetatable" become "data.null is None" and
    "data.array returns the very list it was given".
  - Python keeps Lua's integer/float split (1 and 1.0 differ), so the
    canonical text is the Lua kit's byte for byte; Python can tell []
    from {} where Lua needs the mark.
  - Lua's `freeze` option becomes `freeze`, any function that returns a
    frozen version of plain data (`lambda v: Event("t", v).data`); with
    the Event class given too, encode must refuse an Event.
  - Lua's is_array is isArray (one vocabulary with the other ports).

check(data, freeze=None, Event=None) runs the module half of this
contract against a siso.kit.data module object (anything with the five
attributes) and returns a list of problems (empty when it holds).
"""

import collections

EXPORTS = {"data": ("null", "array", "isArray", "encode", "decode")}

TOOLS = ("py/code/siso/kit/run.py",)

CANONICAL = '{"a":[1.0,"x",true,null],"b":1,"e":[]}'

_MISSING = object()


def _str(v):
    try:
        s = str(v)
        if isinstance(s, str):
            return s
    except Exception:
        pass
    return "<" + type(v).__name__ + ">"


def _show(v):
    try:
        s = repr(v)
        if isinstance(s, str):
            return s if len(s) <= 120 else s[:117] + "..."
    except Exception:
        pass
    return "<" + type(v).__name__ + ">"


def _strictEqual(a, b):
    """Equal with the same types at every depth (1, 1.0 and True differ); iterative."""
    stack = [(a, b)]
    while stack:
        x, y = stack.pop()
        if type(x) is not type(y):
            return False
        if isinstance(x, (list, tuple)):
            if len(x) != len(y):
                return False
            stack.extend(zip(x, y))
        elif isinstance(x, dict):
            if len(x) != len(y) or any(k not in y for k in x):
                return False
            stack.extend((x[k], y[k]) for k in x)
        else:
            try:
                if not (x == y):
                    return False
            except Exception:
                return False
    return True


def check(d, freeze=None, Event=None):
    p = []
    fail = p.append
    if d is None:
        return ["kit.data is missing"]
    for k in EXPORTS["data"]:
        try:
            v = getattr(d, k)
        except Exception:
            fail("kit.data." + k + " is missing")
            continue
        if k == "null":
            if v is not None:
                fail("kit.data.null is " + _show(v) + ", not None")
        elif not callable(v):
            fail("kit.data." + k + " is not callable")
    p.sort()
    if p:
        return p

    def encode(v, label):
        try:
            r = d.encode(v)
        except Exception as err:
            fail("data.encode raised on " + label + " instead of returning why: " + type(err).__name__ + ": " + _str(err))
            return None, "raised"
        if type(r) is not tuple or len(r) != 2:
            fail("data.encode does not return (text, why) on " + label)
            return None, "shape"
        return r

    def decode(s, label):
        try:
            r = d.decode(s)
        except Exception as err:
            fail("data.decode raised on " + label + " instead of returning why: " + type(err).__name__ + ": " + _str(err))
            return None, "raised"
        if type(r) is not tuple or len(r) != 2:
            fail("data.decode does not return (value, why) on " + label)
            return None, "shape"
        return r

    try:
        v = {"b": 1, "a": d.array([1.0, "x", True, d.null]), "e": d.array([])}
        s, why = encode(v, "plain data")
        if s != CANONICAL or why is not None:
            fail("data.encode is not canonical: " + _show(why if s is None else s))
        ordered, _ = encode({"\U0001F600": 1, "｡": 2, "z": 3, "é": 4}, "keys beyond the BMP")
        if ordered != '{"z":3,"é":4,"｡":2,"\U0001F600":1}':
            fail("data.encode does not sort keys by code point: " + _show(ordered))
        floats, _ = encode([-0.0, 1e21, 0.1, 1e-7, 1e-6, 1.5e-7, 5e-324, 1.0, 100.0, 1.5, 2.0 ** 53, 1e20,
                            1.7976931348623157e308, 123456789012345680000.0, 1e-5], "floats")
        wantF = ("[-0.0,1e+21,0.1,1e-7,0.000001,1.5e-7,5e-324,1.0,100.0,1.5,9007199254740992.0,"
                 "100000000000000000000.0,1.7976931348623157e+308,123456789012345680000.0,0.00001]")
        if floats != wantF:
            fail("data.encode does not render floats as the Lua kit does: " + _show(floats))
        ints, _ = encode([0, -1, 2 ** 63 - 1, -2 ** 63, True, False], "integers and booleans")
        if ints != "[0,-1,9223372036854775807,-9223372036854775808,true,false]":
            fail("data.encode does not render integers and booleans canonically: " + _show(ints))
        text, _ = encode(["q\"\\\n\x01\x1f\b\f\r\t\x00\x7fé\U0001F600 /"], "a string")
        if text != '["q\\"\\\\\\n\\u0001\\u001f\\b\\f\\r\\t\\u0000\x7fé\U0001F600 /"]':
            fail("data.encode does not escape strings as the Lua kit does: " + _show(text))
        empties, _ = encode({"o": {}, "a": [], "t": ()}, "empty containers")
        if empties != '{"a":[],"o":{},"t":[]}':
            fail("data.encode does not render empty containers: " + _show(empties))
        nest512 = []
        for _ in range(511):
            nest512 = [nest512]
        deep, _ = encode(nest512, "512 levels of arrays")
        if deep != "[" * 512 + "]" * 512:
            fail("data.encode refuses 512 levels of nesting")

        back, bwhy = decode(CANONICAL, "canonical text")
        if type(back) is not dict or bwhy is not None or not d.isArray(back.get("a")) or not d.isArray(back.get("e")):
            fail("data.decode loses arrays")
        else:
            a = back["a"]
            if type(a) is not list or len(a) != 4 or type(a[0]) is not float or a[3] is not None:
                fail("data.decode loses a float or null")
            if type(back.get("b")) is not int:
                fail("data.decode turns an integer into a float")
            if back.get("e") != [] or type(back.get("e")) is not list:
                fail("data.decode does not give [] back as an empty list")
        nv, nwhy = decode("null", "null")
        if nv is not None or nwhy is not None:
            fail("data.decode('null') is " + _show((nv, nwhy)) + ", not (None, None)")
        for label, src, want in (
            ("a surrogate pair", '"\\ud83d\\ude00"', "\U0001F600"),
            ("whitespace around a value", " \t\n[1] \r\n", [1]),
            ("the least 64-bit integer", "-9223372036854775808", -2 ** 63),
            ("an exponent", "1E2", 100.0),
            ("a fraction", "-0.5e-3", -0.0005),
            ("an empty object", "{}", {}),
            ("512 levels of arrays", "[" * 512 + "]" * 512, nest512),
        ):
            got, gwhy = decode(src, label)
            if gwhy is not None or not _strictEqual(got, want):
                fail("data.decode does not read " + label + ": " + _show(gwhy if gwhy is not None else got))
        if d.isArray({}) or d.isArray("x") or d.isArray(None):
            fail("data.isArray answers True for a value that is not a list or a tuple")
        if not d.isArray([]) or not d.isArray(()):
            fail("data.isArray answers False for a list or a tuple")
        marked = []
        if d.array(marked) is not marked or type(marked) is not list or marked != []:
            fail("data.array on a list is not that list, unchanged")
        pair = (1, 2)
        if d.array(pair) is not pair:
            fail("data.array on a tuple is not that tuple")

        class ListSub(list):
            pass

        for label, value in (("a dict", {}), ("a str", "x"), ("a list subclass", ListSub())):
            try:
                d.array(value)
                fail("data.array accepted " + label)
            except TypeError:
                pass
            except Exception as err:
                fail("data.array refused " + label + " with " + type(err).__name__ + ", not TypeError")

        cyclic = [1]
        cyclic.append(cyclic)

        class Opaque:
            def __init__(self):
                self.v = 1

        Point = collections.namedtuple("Point", "x y")
        nest513 = []
        for _ in range(512):
            nest513 = [nest513]
        for label, value in (
            ("a function", {"f": lambda: 1}),
            ("NaN", [float("nan")]),
            ("inf", [float("inf")]),
            ("-inf", [float("-inf")]),
            ("a set", [{1}]),
            ("bytes", [b"x"]),
            ("a class instance", Opaque()),
            ("a cycle", cyclic),
            ("an OrderedDict", collections.OrderedDict(k=1)),
            ("a list subclass", ListSub([1])),
            ("a namedtuple", Point(1, 2)),
            ("a key that is not a str", {1: 2}),
            ("an integer past 64 bits", [2 ** 63]),
            ("an integer below 64 bits", [-2 ** 63 - 1]),
            ("a lone surrogate", ["\ud800"]),
            ("513 levels of nesting", nest513),
        ):
            t, w = encode(value, label)
            if (t is not None or type(w) is not str) and w not in ("raised", "shape"):
                fail("data.encode accepted " + label)
        if Event is not None:
            t, w = encode({"e": Event("t")}, "an Event")
            if t is not None or type(w) is not str:
                fail("data.encode accepted an Event")
        for label, src in (
            ("a trailing comma", "[1,]"),
            ("a comment", "// c\n1"),
            ("NaN", "NaN"),
            ("Infinity", "Infinity"),
            ("a leading zero", "01"),
            ("a duplicate key", '{"a":1,"a":2}'),
            ("two values", "1 2"),
            ("an integer past 64 bits", "9223372036854775808"),
            ("an integer below 64 bits", "-9223372036854775809"),
            ("a lone surrogate escape", '"\\ud800"'),
            ("a raw control character", '"\x01"'),
            ("a single-quoted string", "'x'"),
            ("nothing", ""),
            ("a bare fraction", ".5"),
            ("a missing exponent", "1e"),
            ("a plus sign", "+1"),
            ("a missing colon", '{"a" 1}'),
            ("513 levels of arrays", "[" * 513 + "]" * 513),
            ("a value that is not a str", 1),
        ):
            value, w = decode(src, label)
            if (value is not None or type(w) is not str) and w not in ("raised", "shape"):
                fail("data.decode accepted " + label)

        if freeze is not None:
            def sample():
                return {"b": 1, "a": d.array([1.0, "x", True, d.null]), "e": d.array([]),
                        "o": {"k": "v", "n": [2]}, "l": [1, 2]}

            plain, _ = encode(sample(), "plain data")
            frozenSample = freeze(sample())
            frozenText, fwhy = encode(frozenSample, "frozen data")
            if frozenText != plain or plain is None:
                fail("data.encode does not read frozen data as the plain data it presents: " +
                     _show(fwhy if frozenText is None else frozenText))
            if frozenSample["a"][3] is not None:
                fail("freezing did not pass None through")
            if not d.isArray(frozenSample["a"]) or not d.isArray(frozenSample["e"]):
                fail("a frozen array is not an array")
    except Exception as err:
        fail("raised " + type(err).__name__ + ": " + _str(err))
    return p
