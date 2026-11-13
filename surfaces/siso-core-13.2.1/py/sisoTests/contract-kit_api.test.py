# contract test: kit-api (py/code/siso/contracts/kit_api.py).
# Port of lua/sisoTests/contract-kit_api.test.lua. The module check names
# what is missing and what misbehaves, and passes on a module that answers
# as the contract says. (A stand-in defined here takes the place of
# siso.kit.data, which a kit phase builds; it answers what the check asks
# and is no reference for the kit. The fake core's Event freezes data for
# the `freeze` and `Event` options.)
# Runnable from any directory: python3 py/sisoTests/contract-kit_api.test.py
import sys
sys.dont_write_bytecode = True  # no __pycache__ in the project tree

import importlib.util
import json
import math
import os
import re
import sys
import types

HERE = os.path.dirname(os.path.abspath(__file__))
sys.path.insert(0, os.path.normpath(os.path.join(HERE, "..", "code")))

from siso import harness as H  # noqa: E402
from siso.contracts import kit_api as K  # noqa: E402


def loadFake():
    spec = importlib.util.spec_from_file_location("contract_fake_core", os.path.join(HERE, "contract-fake-core.py"))
    mod = importlib.util.module_from_spec(spec)
    spec.loader.exec_module(mod)
    return mod


core = loadFake().new()
Event = core["Event"]
FrozenDict = type(Event("t").data)


def joined(lst):
    return "; ".join(lst)


def has(lst, needle):
    return any(needle in s for s in lst)


# ── the stand-in ────────────────────────────────────────────────────

class Refused(Exception):
    pass


MAPPINGS = (dict, FrozenDict, types.MappingProxyType)


def esFloat(x):
    """A finite float as ECMAScript's Number::toString lays out its shortest digits, keeping "." or an exponent."""
    if x == 0:
        return "-0.0" if math.copysign(1.0, x) < 0 else "0.0"
    r = repr(x)
    sign = ""
    if r.startswith("-"):
        sign, r = "-", r[1:]
    mant, _, exp = r.partition("e")
    ip, _, fp = mant.partition(".")
    allDigits = ip + fp
    lead = len(allDigits) - len(allDigits.lstrip("0"))
    n = len(ip) + (int(exp) if exp else 0) - lead
    digits = allDigits.lstrip("0").rstrip("0") or "0"
    k = len(digits)
    if k <= n <= 21:
        out = digits + "0" * (n - k) + ".0"
    elif 0 < n <= 21:
        out = digits[:n] + "." + digits[n:]
    elif -6 < n <= 0:
        out = "0." + "0" * (-n) + digits
    else:
        e = n - 1
        out = digits[0] + ("." + digits[1:] if k > 1 else "") + "e" + ("+" if e >= 0 else "-") + str(abs(e))
    return sign + out


ESCAPES = {'"': '\\"', "\\": "\\\\", "\b": "\\b", "\f": "\\f", "\n": "\\n", "\r": "\\r", "\t": "\\t"}


def quote(s):
    out = ['"']
    for ch in s:
        c = ord(ch)
        if 0xD800 <= c <= 0xDFFF:
            raise Refused("a lone surrogate")
        if ch in ESCAPES:
            out.append(ESCAPES[ch])
        elif c < 0x20:
            out.append("\\u%04x" % c)
        else:
            out.append(ch)
    out.append('"')
    return "".join(out)


def encodeWith(sortKeys=sorted, floats=esFloat):
    def enc(v, onPath, depth):
        t = type(v)
        if v is None:
            return "null"
        if t is bool:
            return "true" if v else "false"
        if t is int:
            if not -2 ** 63 <= v < 2 ** 63:
                raise Refused("an integer past 64 bits")
            return str(v)
        if t is float:
            if math.isnan(v) or math.isinf(v):
                raise Refused("a float that is not finite")
            return floats(v)
        if t is str:
            return quote(v)
        if t in (list, tuple) or t in MAPPINGS:
            if depth >= 512:
                raise Refused("nested too deep")
            if id(v) in onPath:
                raise Refused("a cycle")
            onPath.add(id(v))
            parts = []  # plain loops: one frame per level of nesting
            if t in (list, tuple):
                for x in v:
                    parts.append(enc(x, onPath, depth + 1))
                out = "[" + ",".join(parts) + "]"
            else:
                keys = list(v.keys())
                if any(type(k) is not str for k in keys):
                    raise Refused("a key that is not a str")
                for k in sortKeys(keys):
                    parts.append(quote(k) + ":" + enc(v[k], onPath, depth + 1))
                out = "{" + ",".join(parts) + "}"
            onPath.discard(id(v))
            return out
        raise Refused("not data: " + t.__name__)

    def encode(v):
        try:
            return enc(v, set(), 0), None
        except Refused as err:
            return None, str(err)

    return encode


NUMBER = re.compile(r"-?(0|[1-9][0-9]*)(\.[0-9]+)?([eE][+-]?[0-9]+)?")


def decodeWith(duplicates=False):
    def decode(s):
        if type(s) is not str:
            return None, "not a str"
        pos = [0]

        def fail(m):
            raise Refused(m + " at " + str(pos[0]))

        def ws():
            while pos[0] < len(s) and s[pos[0]] in " \t\n\r":
                pos[0] += 1

        def string():
            pos[0] += 1
            out = []
            while True:
                if pos[0] >= len(s):
                    fail("an unterminated string")
                ch = s[pos[0]]
                if ch == '"':
                    pos[0] += 1
                    break
                if ch == "\\":
                    e = s[pos[0] + 1:pos[0] + 2]
                    simple = {'"': '"', "\\": "\\", "/": "/", "b": "\b", "f": "\f", "n": "\n", "r": "\r", "t": "\t"}
                    if e in simple:
                        out.append(simple[e])
                        pos[0] += 2
                        continue
                    if e != "u" or not re.fullmatch(r"[0-9a-fA-F]{4}", s[pos[0] + 2:pos[0] + 6]):
                        fail("a bad escape")
                    c = int(s[pos[0] + 2:pos[0] + 6], 16)
                    pos[0] += 6
                    if 0xD800 <= c <= 0xDBFF and s[pos[0]:pos[0] + 2] == "\\u" and \
                            re.fullmatch(r"[dD][c-fC-F][0-9a-fA-F]{2}", s[pos[0] + 2:pos[0] + 6]):
                        low = int(s[pos[0] + 2:pos[0] + 6], 16)
                        pos[0] += 6
                        c = 0x10000 + ((c - 0xD800) << 10) + (low - 0xDC00)
                    elif 0xD800 <= c <= 0xDFFF:
                        fail("a lone surrogate")
                    out.append(chr(c))
                    continue
                if ord(ch) < 0x20:
                    fail("a control character in a string")
                out.append(ch)
                pos[0] += 1
            return "".join(out)

        def value(depth):
            ws()
            if pos[0] >= len(s):
                fail("no value")
            c = s[pos[0]]
            if c in "[{":
                if depth >= 512:
                    fail("nested too deep")
                pos[0] += 1
                close = "]" if c == "[" else "}"
                o = [] if c == "[" else {}
                ws()
                if s[pos[0]:pos[0] + 1] == close:
                    pos[0] += 1
                    return o
                while True:
                    if c == "{":
                        ws()
                        if s[pos[0]:pos[0] + 1] != '"':
                            fail("a key")
                        k = string()
                        if k in o and not duplicates:
                            fail("a duplicate key")
                        ws()
                        if s[pos[0]:pos[0] + 1] != ":":
                            fail("a colon")
                        pos[0] += 1
                        o[k] = value(depth + 1)
                    else:
                        o.append(value(depth + 1))
                    ws()
                    nxt = s[pos[0]:pos[0] + 1]
                    if nxt == ",":
                        pos[0] += 1
                        continue
                    if nxt == close:
                        pos[0] += 1
                        return o
                    fail("a separator")
            if c == '"':
                return string()
            for word, v in (("true", True), ("false", False), ("null", None)):
                if s.startswith(word, pos[0]):
                    pos[0] += len(word)
                    return v
            m = NUMBER.match(s, pos[0])
            if not m:
                fail("a value")
            pos[0] = m.end()
            if m.group(2) is None and m.group(3) is None:
                n = int(m.group(0))
                if not -2 ** 63 <= n < 2 ** 63:
                    fail("an integer past 64 bits")
                return n
            x = float(m.group(0))
            if math.isinf(x):
                fail("a number past the float range")
            return x

        try:
            v = value(0)
            ws()
            if pos[0] != len(s):
                fail("trailing text")
            return v, None
        except Refused as err:
            return None, str(err)

    return decode


def array(t):
    if type(t) not in (list, tuple):
        raise TypeError("data.array: not a list or a tuple")
    return t


def isArray(v):
    return type(v) in (list, tuple)


def standIn(**overrides):
    m = types.SimpleNamespace(null=None, array=array, isArray=isArray, encode=encodeWith(), decode=decodeWith())
    for k, v in overrides.items():
        setattr(m, k, v)
    return m


def freezeMP(v):
    """A frozen copy of plain data in the standard library's own read-only types."""
    if type(v) is dict:
        return types.MappingProxyType({k: freezeMP(x) for k, x in v.items()})
    if type(v) in (list, tuple):
        return tuple(freezeMP(x) for x in v)
    return v


def freezeCore(v):
    return Event("t", v).data


# ── the tests ───────────────────────────────────────────────────────

def t_passes():
    p = K.check(standIn())
    H.assert_(not p, joined(p))
    p = K.check(standIn(), freeze=freezeMP)
    H.assert_(not p, joined(p))
    p = K.check(standIn(), freeze=freezeCore, Event=Event)
    H.assert_(not p, joined(p))


H.test("a module that answers as the contract says passes (with and without freeze)", t_passes)


def t_missing():
    m = standIn()
    del m.decode
    del m.null
    p = K.check(m)
    H.assert_(has(p, "kit.data.decode is missing"), joined(p))
    H.assert_(has(p, "kit.data.null is missing"), joined(p))
    H.assert_(has(K.check(None), "kit.data is missing"), "no module")
    H.assert_(has(K.check(standIn(encode=42)), "kit.data.encode is not callable"), "not callable")


H.test("missing exports are named", t_missing)


def t_noncanonical():
    p = K.check(standIn(encode=encodeWith(sortKeys=lambda ks: ks)))
    H.assert_(has(p, "not canonical"), joined(p))
    p = K.check(standIn(encode=encodeWith(sortKeys=lambda ks: sorted(ks, key=lambda k: k.encode("utf-16-be")))))
    H.assert_(has(p, "does not sort keys by code point"), joined(p))
    p = K.check(standIn(encode=encodeWith(floats=repr)))
    H.assert_(has(p, "does not render floats as the Lua kit does"), joined(p))

    def viaJson(v):
        try:
            return json.dumps(v, sort_keys=True), None
        except Exception as err:
            return None, str(err)

    p = K.check(standIn(encode=viaJson))
    H.assert_(has(p, "not canonical") and has(p, "data.encode accepted NaN"), joined(p))


H.test("a non-canonical encoder is named", t_noncanonical)


def t_raises():
    plain = encodeWith()

    def encode(v):
        if isinstance(v, dict) and callable(v.get("f")):
            raise TypeError("a function")
        return plain(v)

    p = K.check(standIn(encode=encode))
    H.assert_(has(p, "data.encode raised on a function instead of returning why"), joined(p))
    p = K.check(standIn(decode=lambda s: decodeWith()(s)[0]))
    H.assert_(has(p, "does not return (value, why)"), joined(p))


H.test("an encoder that raises, or a decoder that returns no pair, is named", t_raises)


def t_marks():
    H.assert_(has(K.check(standIn(null=object())), "kit.data.null is"), "a sentinel null")
    p = K.check(standIn(array=lambda t: list(t)))
    H.assert_(has(p, "data.array on a list is not that list"), joined(p))
    p = K.check(standIn(array=lambda t: t))
    H.assert_(has(p, "data.array accepted a dict"), joined(p))
    p = K.check(standIn(isArray=lambda v: isinstance(v, (list, tuple, dict))))
    H.assert_(has(p, "isArray answers True"), joined(p))


H.test("Lua's marks, in Python: a null sentinel, an array mark that copies or accepts anything", t_marks)


def t_laxDecoder():
    p = K.check(standIn(decode=decodeWith(duplicates=True)))
    H.assert_(has(p, "data.decode accepted a duplicate key"), joined(p))

    def viaJson(s):
        try:
            return json.loads(s), None
        except Exception as err:
            return None, str(err)

    p = K.check(standIn(decode=viaJson))
    for needle in ("accepted NaN", "accepted a duplicate key", "accepted an integer past 64 bits",
                   "accepted a lone surrogate escape"):
        H.assert_(has(p, needle), needle + ": " + joined(p))


H.test("a lax decoder is named", t_laxDecoder)


def t_frozenBlind():
    plain = encodeWith()

    def encode(v):
        if type(v) in (types.MappingProxyType, FrozenDict):
            return None, "a frozen mapping is not data"
        return plain(v)

    m = standIn(encode=encode)
    H.assertEqual(K.check(m), [], "without freeze the check does not ask")
    p = K.check(m, freeze=freezeCore)
    H.assert_(has(p, "frozen"), joined(p))
    p = K.check(standIn(encode=lambda v: plain(v) if not isinstance(v, dict) or "e" not in v or not
                        isinstance(v["e"], Event) else ('{"e":{}}', None)), Event=Event)
    H.assert_(has(p, "data.encode accepted an Event"), joined(p))


H.test("with freeze or Event given, an encoder blind to frozen data or to an Event is named", t_frozenBlind)

H.finish("contract kit-api")
