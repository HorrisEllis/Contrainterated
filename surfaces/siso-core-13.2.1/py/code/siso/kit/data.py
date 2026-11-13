"""siso.kit.data -- plain data and its canonical JSON (py-kit-api, decision 5).

Port of lua/code/kit/data.lua (itself ported from jaa 2.14.4 lib.json).

  data.null               None: JSON null is Python's None (a value; no
                          sentinel, nothing to write)
  data.array(t) -> t      t itself, unchanged, when it is a list or a
                          tuple (exact types; an empty one too); anything
                          else raises TypeError
  data.isArray(v)         True for a list or a tuple (exact types; a
                          frozen list in an event's data is a tuple)
  data.encode(v)          -> (canonical text, None) | (None, why)
  data.decode(s)          -> (value, None) | (None, why)
Errors are returned, never raised (data.array's TypeError is a caller's
bug, not data).

Canonical form (the Lua kit's, byte for byte, for the same plain data):
  - objects are a dict or the core's FrozenDict with str keys, sorted by
    code point (Python's str order, which is UTF-8 byte order, as Lua
    sorts bytes); arrays are a list or a tuple; no whitespace anywhere;
  - bool before int: True is "true", never 1;
  - integers as digits; they must fit in 64 bits (Lua's integers,
    -2**63 .. 2**63-1), else refused;
  - floats finite, as the shortest round-trip digits (float.__repr__
    gives them, the closest of them to the value when several have the
    same length, as ECMAScript asks), laid out as ECMAScript's
    Number::toString (decimal when 1e-6 <= |x| < 1e21, else d.ddde+n /
    d.ddde-n), always keeping a "." or an exponent so the type survives:
    1.0 is "1.0", 1e21 is "1e+21", 1e-7 is "1e-7", -0.0 is "-0.0";
    NaN and +-inf are refused;
  - strings: `"` `\\` and code points below 0x20 escaped (\\b \\f \\n \\r
    \\t, else \\u00xx, lowercase hex); everything else as is (UTF-8); a
    lone surrogate is refused (it has no UTF-8 form: RFC 8259 section 8.1);
  - exact types only: a subclass of dict, list, tuple, str, int or float,
    a set, bytes, a function, a class instance, an Event, a key that is
    not a str and a cycle are refused, never dropped or repaired;
  - nesting is limited to 512 arrays/objects, in both directions.

Frozen data (py-core-api READ-ONLY) is encoded as the plain data it
presents: a FrozenDict reads as the dict it was made of and a tuple as
the list, so encode(Event("t", v).data) == encode(v), and residue,
samples and observer records serialise as they are handed out. A
FrozenDict is read through its own Mapping surface (iteration and []),
which is the core's code; a dict through dict's own.

Decoding is strict RFC 8259 (no trailing commas, comments, NaN, Infinity,
leading zeros, duplicate keys or raw control characters in strings; the
whole input is one value, whitespace around it allowed). Objects come
back as dicts, arrays as lists, null as None; a number without "." or an
exponent comes back as an int that must fit in 64 bits, any other as a
float; \\u escapes are decoded with surrogate pairs joined, and a lone
surrogate (escaped or raw) is an error.

Error texts are the Lua kit's, with Python's positions: an encode error
names its path from the root, "$", with ".<key>" for a member and "[i]"
for an array index, counted from 0 (Lua counts from 1); a decode error
names the str index it was found at, counted from 0 ("at index N"; Lua
names a byte, counted from 1).

Both directions are iterative (an explicit stack, never recursion), so
neither the depth of the input nor Python's recursion limit decides
anything but the 512 bound. A path is a chain of (parent, step) pairs,
rendered only on error, so a deep failure costs little.

Pure: nothing is written after load, no I/O, no state. Output never
depends on a dict's insertion order: keys are sorted. Nothing this module
hands out gives a gate an object it could write and later read back:
encode returns a str, decode fresh dicts and lists of its own (fresh on
every call) holding str, int, float, bool and None.

Imports: the standard library (math, re) and the core's FrozenDict
(siso.core, py-core-api MODULES), the one object type it reads that is
not the standard library's.
"""

import math as _math
import re as _re

from siso.core import FrozenDict as _FrozenDict

__all__ = ["null", "array", "isArray", "encode", "decode"]

null = None

_MAX_DEPTH = 512  # nested arrays/objects; encode and decode alike
_INT_MIN = -2 ** 63
_INT_MAX = 2 ** 63 - 1
_INF = float("inf")


# ── arrays ──────────────────────────────────────────────────────────

def array(t):
    """t itself, unchanged, when it is a list or a tuple (exact types)."""
    if type(t) is list or type(t) is tuple:
        return t
    raise TypeError("data.array: not a list or a tuple (" + _typeName(t) + ")")


def isArray(v):
    """True for a list or a tuple (exact types)."""
    return type(v) is list or type(v) is tuple


# ── small helpers ───────────────────────────────────────────────────

_NAME = type.__dict__["__name__"]


def _typeName(v):
    """The name of v's class, read from the class itself (no code of v's runs)."""
    try:
        n = _NAME.__get__(type(v), type)
        if type(n) is str:
            return n
    except Exception:
        pass
    return "object"


def _article(name):
    return ("an " if name[:1].lower() in "aeiou" and name[:1] != "" else "a ") + name


def _notData(v):
    """Why v (not of an exact type data holds) is not data."""
    name = _typeName(v)
    t = type(v)
    for base in (dict, list, tuple, str, int, float):
        try:
            if issubclass(t, base):
                return _article(name) + " is not data (a subclass of " + base.__name__ + ")"
        except Exception:
            break
    return _article(name) + " is not data"


def _showKey(k):
    t = type(k)
    if t in (bool, int, float, type(None)):
        return repr(k)
    return _article(_typeName(k))


class _Refused(Exception):
    def __init__(self, message, where):
        Exception.__init__(self, message)
        self.message = message
        self.where = where


def _pathText(node):
    steps = []
    while node is not None:
        steps.append(node[1])
        node = node[0]
    steps.append("$")
    steps.reverse()
    return "".join(steps)


# ── floats ──────────────────────────────────────────────────────────

def _formatFloat(x, path):
    if x != x:
        raise _Refused("NaN is not allowed", path)
    if x == _INF or x == -_INF:
        raise _Refused("Infinity is not allowed", path)
    if x == 0:
        return "-0.0" if _math.copysign(1.0, x) < 0 else "0.0"
    sign = ""
    if x < 0:
        sign, x = "-", -x
    # the shortest round-trip digits; x = 0.d1..dk * 10**n (ECMAScript's k, n)
    mant, _, exp = float.__repr__(x).partition("e")
    ip, _, fp = mant.partition(".")
    allDigits = ip + fp
    stripped = allDigits.lstrip("0")
    n = len(ip) - (len(allDigits) - len(stripped)) + (int(exp) if exp else 0)
    d = stripped.rstrip("0") or "0"
    k = len(d)
    if k <= n <= 21:
        out = d + "0" * (n - k) + ".0"
    elif 0 < n <= 21:
        out = d[:n] + "." + d[n:]
    elif -6 < n <= 0:
        out = "0." + "0" * (-n) + d
    else:
        e = n - 1
        es = ("-" + str(-e)) if e < 0 else ("+" + str(e))
        out = (d if k == 1 else d[0] + "." + d[1:]) + "e" + es
    return sign + out


# ── encoding ────────────────────────────────────────────────────────

_ESC = {'"': '\\"', "\\": "\\\\", "\b": "\\b", "\f": "\\f", "\n": "\\n", "\r": "\\r", "\t": "\\t"}
_TO_ESCAPE = _re.compile('["\\\\\x00-\x1f]')
_SURROGATE = _re.compile("[\ud800-\udfff]")


def _escapeChar(m):
    c = m.group(0)
    e = _ESC.get(c)
    return e if e is not None else "\\u%04x" % ord(c)


def _quote(s, path):
    if _SURROGATE.search(s) is not None:
        raise _Refused("string is not valid UTF-8 (a lone surrogate)", path)
    return '"' + _TO_ESCAPE.sub(_escapeChar, s) + '"'


# stack entries: (_VALUE, value, path, depth) | (_TEXT, text) | (_CLOSE, text, id)
_VALUE, _TEXT, _CLOSE = 0, 1, 2


def _encode(root):
    out = []
    onPath = set()  # ids of the containers on the path being encoded
    stack = [(_VALUE, root, None, 0)]
    pop, push, emit = stack.pop, stack.append, out.append
    while stack:
        item = pop()
        kind = item[0]
        if kind == _TEXT:
            emit(item[1])
            continue
        if kind == _CLOSE:
            emit(item[1])
            onPath.discard(item[2])
            continue
        v, path, depth = item[1], item[2], item[3]
        t = type(v)
        if v is None:
            emit("null")
        elif t is bool:
            emit("true" if v else "false")
        elif t is int:
            if v < _INT_MIN or v > _INT_MAX:
                raise _Refused("integer out of 64-bit range", path)
            emit(int.__repr__(v))
        elif t is float:
            emit(_formatFloat(v, path))
        elif t is str:
            emit(_quote(v, path))
        elif t is list or t is tuple or t is dict or t is _FrozenDict:
            if id(v) in onPath:
                raise _Refused("cycle", path)
            if depth >= _MAX_DEPTH:
                raise _Refused("nesting too deep", path)
            if t is list or t is tuple:
                n = len(v)
                if n == 0:
                    emit("[]")
                    continue
                emit("[")
                onPath.add(id(v))
                push((_CLOSE, "]", id(v)))
                for i in range(n - 1, -1, -1):
                    push((_VALUE, v[i], (path, "[" + str(i) + "]"), depth + 1))
                    if i > 0:
                        push((_TEXT, ","))
            else:
                keys = list(dict.keys(v)) if t is dict else list(v)
                for k in keys:
                    if type(k) is not str:
                        raise _Refused("object key " + _showKey(k) + " is not a string", path)
                keys.sort()
                if not keys:
                    emit("{}")
                    continue
                emit("{")
                onPath.add(id(v))
                push((_CLOSE, "}", id(v)))
                for i in range(len(keys) - 1, -1, -1):
                    k = keys[i]
                    push((_VALUE, v[k], (path, "." + k), depth + 1))
                    push((_TEXT, _quote(k, (path, ".<key>")) + ":"))
                    if i > 0:
                        push((_TEXT, ","))
        else:
            raise _Refused(_notData(v), path)
    return "".join(out)


def encode(v):
    """-> (canonical text, None) | (None, why); never raises."""
    try:
        return _encode(v), None
    except _Refused as err:
        return None, "data.encode: " + err.message + " at " + _pathText(err.where)
    except Exception as err:  # MemoryError and the like: returned, never raised
        return None, "data.encode: " + _typeName(err)


# ── decoding ────────────────────────────────────────────────────────

_WS = _re.compile("[ \t\n\r]*")
_NUMBER = _re.compile("(-?[0-9]+)(\\.?[0-9]*)([eE]?[-+]?[0-9]*)")
_LEADING_ZERO = _re.compile("-?0[0-9]")
_EXPONENT = _re.compile("[eE][-+]?[0-9]+")
_RUN = _re.compile('[^"\\\\\x00-\x1f]+')
_HEX4 = _re.compile("[0-9a-fA-F]{4}")
_UNESC = {'"': '"', "\\": "\\", "/": "/", "b": "\b", "f": "\f", "n": "\n", "r": "\r", "t": "\t"}


def _dfail(message, i):
    raise _Refused(message, i)


def _skipWs(s, i):
    return _WS.match(s, i).end()


def _decodeString(s, i):
    # s[i] == '"'
    parts = []
    j = i + 1
    while True:
        m = _RUN.match(s, j)
        if m is not None:
            run = m.group(0)
            if _SURROGATE.search(run) is not None:
                _dfail("string is not valid UTF-8 (a lone surrogate)", i)
            parts.append(run)
            j = m.end()
        c = s[j:j + 1]
        if c == '"':
            return "".join(parts), j + 1
        if c == "\\":
            e = s[j + 1:j + 2]
            simple = _UNESC.get(e)
            if simple is not None:
                parts.append(simple)
                j += 2
            elif e == "u":
                if _HEX4.match(s, j + 2) is None:
                    _dfail("bad \\u escape", j)
                cp = int(s[j + 2:j + 6], 16)
                j += 6
                if 0xD800 <= cp <= 0xDBFF:
                    lo = None
                    if s[j:j + 2] == "\\u" and _HEX4.match(s, j + 2) is not None:
                        lo = int(s[j + 2:j + 6], 16)
                    if lo is None or not 0xDC00 <= lo <= 0xDFFF:
                        _dfail("lone high surrogate", j)
                    cp = 0x10000 + ((cp - 0xD800) << 10) + (lo - 0xDC00)
                    j += 6
                elif 0xDC00 <= cp <= 0xDFFF:
                    _dfail("lone low surrogate", j)
                parts.append(chr(cp))
            else:
                _dfail("bad escape", j)
        elif c == "":
            _dfail("unterminated string", i)
        else:
            _dfail("control character in string", j)


def _decodeNumber(s, i):
    # validated against the RFC 8259 grammar exactly, as the Lua kit does
    m = _NUMBER.match(s, i)
    if m is None:
        _dfail("bad number", i)
    whole, frac, exp = m.group(1), m.group(2), m.group(3)
    if _LEADING_ZERO.match(whole) is not None:
        _dfail("leading zero", i)
    if frac == ".":
        _dfail("bad fraction", i)
    if exp != "" and _EXPONENT.fullmatch(exp) is None:
        _dfail("bad exponent", i)
    text = whole + frac + exp
    end = i + len(text)
    if frac == "" and exp == "":
        # 64 bits hold at most 19 digits: a longer text is out of range
        # (and never reaches int(), whose digit limit is far beyond)
        v = int(text) if len(whole.lstrip("-")) <= 20 else None
        if v is None or v < _INT_MIN or v > _INT_MAX:
            _dfail("integer out of 64-bit range", i)
        return v, end
    v = float(text)
    if v == _INF or v == -_INF:
        _dfail("number out of range", i)
    return v, end


def _decode(s):
    stack = []  # open containers: [dict, pending key] or [list, None]
    i = 0
    while True:
        # ── a value at i ──
        i = _skipWs(s, i)
        c = s[i:i + 1]
        if c == "{" or c == "[":
            # containers only, as in the encoder: at most _MAX_DEPTH nested levels
            if len(stack) >= _MAX_DEPTH:
                _dfail("nesting too deep", i)
            i = _skipWs(s, i + 1)
            if c == "{":
                obj = {}
                if s[i:i + 1] == "}":
                    value, i = obj, i + 1
                else:
                    if s[i:i + 1] != '"':
                        _dfail("expected a string key", i)
                    k, i = _decodeString(s, i)
                    i = _skipWs(s, i)
                    if s[i:i + 1] != ":":
                        _dfail("expected ':'", i)
                    stack.append([obj, k])
                    i += 1
                    continue
            else:
                arr = []
                if s[i:i + 1] == "]":
                    value, i = arr, i + 1
                else:
                    stack.append([arr, None])
                    continue
        elif c == '"':
            value, i = _decodeString(s, i)
        elif c == "-" or (c != "" and c in "0123456789"):
            value, i = _decodeNumber(s, i)
        elif s.startswith("true", i):
            value, i = True, i + 4
        elif s.startswith("false", i):
            value, i = False, i + 5
        elif s.startswith("null", i):
            value, i = None, i + 4
        elif c == "":
            _dfail("unexpected end of input", i)
        else:
            _dfail("unexpected character", i)
        # ── the value is complete: hand it to the containers it closes ──
        while True:
            if not stack:
                return value, i
            top = stack[-1]
            cont = top[0]
            i = _skipWs(s, i)
            d = s[i:i + 1]
            if type(cont) is dict:
                k = top[1]
                if k in cont:
                    _dfail("duplicate key " + _quoteKey(k), i)
                cont[k] = value
                if d == "}":
                    stack.pop()
                    value, i = cont, i + 1
                    continue
                if d != ",":
                    _dfail("expected ',' or '}'", i)
                i = _skipWs(s, i + 1)
                if s[i:i + 1] != '"':
                    _dfail("expected a string key", i)
                k, i = _decodeString(s, i)
                i = _skipWs(s, i)
                if s[i:i + 1] != ":":
                    _dfail("expected ':'", i)
                top[1] = k
                i += 1
                break
            cont.append(value)
            if d == "]":
                stack.pop()
                value, i = cont, i + 1
                continue
            if d != ",":
                _dfail("expected ',' or ']'", i)
            i += 1
            break


def _quoteKey(k):
    try:
        return _quote(k, None)
    except _Refused:
        return repr(k)


def decode(s):
    """-> (value, None) | (None, why); never raises. decode("null") is (None, None)."""
    if type(s) is not str:
        return None, "data.decode: input must be a string (a str, got " + _article(_typeName(s)) + ")"
    try:
        v, i = _decode(s)
        i = _skipWs(s, i)
        if i < len(s):
            return None, "data.decode: trailing characters at index " + str(i)
        return v, None
    except _Refused as err:
        return None, "data.decode: " + err.message + " at index " + str(err.where)
    except Exception as err:  # MemoryError and the like: returned, never raised
        return None, "data.decode: " + _typeName(err)
