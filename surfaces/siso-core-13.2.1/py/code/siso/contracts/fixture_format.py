"""contract: fixture-format -- the language-agnostic suite every instance
must pass (core 12; Python instance). Port of
lua/code/contracts/fixture_format.lua.

Between: fixtures -> stream, observer (and every instance: the Lua
reference, the JavaScript, PHP and Python ports are checked against the
same files). Only a contracts phase changes this file. The fixture files
themselves (fixtures/*.json) are the fixtures boundary's; this is the
format they are written in, and the matching rules every runner applies.

A FIXTURE is one JSON file:
  {
    "format": 1,
    "name":   "a sentence",
    "covers": ["Rule 3", "K5", ...],    principle items it pins
    "gates":  [ gate, ... ],            registered before the first step
    "steps":  [ step, ... ]             run in order
  }
A GATE is { "signature": "a", "does": behaviour }. Behaviours are
test-only (dev notes section 2):
  { "emit": [ out, ... ] }   returns a list of these (empty: consumed)
  { "emitOne": out }         returns one Event
  { "forward": "t" }         returns Event("t", <the received event's data>)
  { "nothing": true }        returns None
  { "throw": "message" }     raises Exception("message")
  { "raw": value }           returns `value` itself (a fresh copy of the
                             decoded JSON value), which is not an Event
  where `out` is an EVENT, or { "raw": value } for a list member that is
  not an Event.
An EVENT is { "type": "t", "data": { ... } }; "data" may be omitted ({}).
Event data is plain JSON with integers only (no floats), and no empty
arrays inside it (Lua cannot tell [] from {}). The restriction is on event
data only: a list the format itself expects (gates, steps, emit, take,
sample's residue, trace, then, at) may be empty, so "take": [] pins that
the stream holds no residue.

A STEP is one of:
  { "register": gate, "refused": true|false }
  { "emit": EVENT, "expect": expectation }
  { "emitRaw": value, "expect": expectation }   the entry is not an Event
  { "take": [ EVENT, ... ] }      the residue, compared as a multiset
  { "sample": { "residue": [ EVENT, ... ], "gateCount": n } }
                                  the residue compared as a multiset
An EXPECTATION is:
  "settled"
  { "settled": true, "trace": [ node, ... ] }
  { "rejected": { "code": "SISO_...", "eventType"?: "t", "depth"?: n,
                  "cause"?: "message", "at"?: [ "t1", "t2", ... ] },
    "trace"?: [ node, ... ] }
A NODE is one delivery of the propagation, as the observer's batch shows
it: { "type": "t", "claimed": true|false, "then"?: [ node, ... ] }, where
"then" lists the deliveries its gate's return caused.

MATCHING (order-free: K1 is not semantics)
  - Residue is a multiset: two lists match when, rendered canonically,
    they hold the same events the same number of times.
  - A trace is a forest built from the batch's records by causeId. Two
    forests match when their roots match as a multiset and each node's
    "then" matches as a multiset, recursively.
  - "at" names the delivery a rejection names, by the path of types
    from the entry delivery (["a", "b"]: the b caused by the entry a).
    The rejection's eventId must be that record's id, and its causeId
    that record's cause. The fixture's author keeps the path unambiguous.
  - "eventType", "depth" and "cause" (the raised message) compare exactly
    when present. An expectation never names a raw id: ids follow
    delivery order, which an instance may choose.
  - A runner always attaches one observer, and fails a step whose batch
    breaks observer-seam (batchProblems), whose rejection breaks core-api
    (rejectionProblems), or whose batch's rejection is not the observer's
    own copy (rejectionCopyProblem).

SUBSTRATE (Python; how the Python runner, siso/fixtures/run.py, maps the
files)
  - Files are decoded with siso.kit.data.decode (strict RFC 8259): JSON
    objects are dicts, arrays are lists, null is None, an integer literal
    an int and any other number a float, so "1.0" is a float, which
    "integers only" refuses, as Lua's does.
  - Python tells [] from {}: problems() also names an empty array inside
    event data (the format forbids it), and canon renders arrays as [..]
    and objects as {..}. Lists and tuples render alike (an event's data
    holds tuples where the file held arrays).
  - bool is not int here: True is not the format's 1, and an int field
    (format, depth, gateCount) must be an int, not a bool.
  - "throw" raises Exception(message), and the runner compares the
    expectation's "cause" to str(rejection.cause).
  - "raw" values and event data are copied fresh from the decoded file
    for each call, so no gate shares an object with the fixture or with
    another call.
  - JSON null as a raw value, or as a whole emitRaw entry, is a value
    ("emitRaw": null enters None): presence is judged by key, not by
    value. "raw": null returns None, which a Python gate's return means
    "consumed", while Lua's runner returns its null sentinel (a table,
    SISO_NOT_EVENT): the instances differ there, so a fixture must not
    use a bare "raw": null as a gate behaviour (problems() names it; none
    does).
  - Event "data" must be a JSON object when present (null and arrays are
    named as shape problems).
  - Expected events are dicts and actual ones Events: the renderings read
    either ("type" and "data" by key from a mapping, by attribute from an
    Event); a record is a dict (observer-seam).
  - canon raises ValueError naming what is not fixture data. The matching
    functions return pairs, as kit.data does: residueMatch and traceMatch
    -> (True, None) | (False, why); recordAt -> (record, None) |
    (None, why).
  - Records are found by eventId (forest, recordAt), which on a batch
    that passes batchProblems is the record's position plus one, as the
    reference indexes them.

problems(fixture) checks a decoded fixture's shape. canon(v),
residueMatch(expected, actual), traceMatch(expected, records),
forest(records) and recordAt(records, path) are the matching rules, for
Python runners. Pure: no state, no I/O.
"""

import collections.abc

BEHAVIOURS = ("emit", "emitOne", "forward", "nothing", "throw", "raw")
CODES = frozenset({"SISO_SELF_LOOP", "SISO_CYCLE", "SISO_GATE_THREW", "SISO_NOT_EVENT"})

_ABSENT = object()


def _isList(t):
    return type(t) in (list, tuple)


def _isObject(v):
    return isinstance(v, collections.abc.Mapping)


def _isInt(v):
    return type(v) is int


def _get(o, k, default=None):
    """A field of a mapping (by key) or of an object (by attribute)."""
    if isinstance(o, collections.abc.Mapping):
        try:
            return o[k] if k in o else default
        except Exception:
            return default
    try:
        return getattr(o, k)
    except Exception:
        return default


def _quote(s):
    out = ['"']
    for ch in s:
        c = ord(ch)
        if ch == '"':
            out.append('\\"')
        elif ch == "\\":
            out.append("\\\\")
        elif c < 0x20 or 0xD800 <= c <= 0xDFFF:
            out.append("\\u%04x" % c)
        else:
            out.append(ch)
    out.append('"')
    return "".join(out)


def canon(v):
    """A canonical rendering of plain data: keys sorted, integers only.
    Raises ValueError naming what is not fixture data."""
    onPath = set()

    def render(x):
        t = type(x)
        if t is str:
            return _quote(x)
        if t is bool:
            return "true" if x else "false"
        if t is int:
            return str(x)
        if t is float:
            raise ValueError("fixture data holds a float: " + repr(x))
        if x is None:
            return "null"
        if t in (list, tuple) or _isObject(x):
            if id(x) in onPath:
                raise ValueError("not plain data: a cycle")
            onPath.add(id(x))
            if t in (list, tuple):
                out = "[" + ",".join(render(y) for y in x) + "]"
            else:
                keys = list(x.keys())
                for k in keys:
                    if type(k) is not str:
                        raise ValueError("not plain data: a key of type " + type(k).__name__)
                out = "{" + ",".join(_quote(k) + ":" + render(x[k]) for k in sorted(keys)) + "}"
            onPath.discard(id(x))
            return out
        raise ValueError("not plain data: " + t.__name__)

    return render(v)


def _renderEvent(e):
    data = _get(e, "data", None)
    return canon({"type": _get(e, "type"), "data": {} if data is None else data})


def _multiset(lst, render):
    return "|".join(sorted(render(x) for x in lst))


def residueMatch(expected, actual):
    a = _multiset(expected, _renderEvent)
    b = _multiset(actual, _renderEvent)
    if a == b:
        return True, None
    return False, "residue: expected " + a + ", got " + b


def _renderNode(n):
    then = _get(n, "then", None)
    return ("(" + canon(_get(n, "type")) + ("+" if _get(n, "claimed") else "-") + "[" +
            _multiset([] if then is None else then, _renderNode) + "])")


def forest(records):
    """The forest of a batch's records, as nodes {"type", "claimed", "then"}."""
    byId = {}
    roots = []
    for r in records:
        node = {"type": _get(r, "eventType"), "claimed": _get(r, "claimed"), "then": []}
        byId[_get(r, "eventId")] = node
        cause = _get(r, "causeId")
        if cause is None:
            roots.append(node)
        else:
            parent = byId.get(cause)
            if parent is None:
                raise ValueError("record " + repr(_get(r, "eventId")) + ": causeId " + repr(cause) +
                                 " names no earlier delivery")
            parent["then"].append(node)
    return roots


def traceMatch(expected, records):
    a = _multiset(expected, _renderNode)
    b = _multiset(forest(records), _renderNode)
    if a == b:
        return True, None
    return False, "trace: expected " + a + ", got " + b


def recordAt(records, path):
    """The record reached by a path of types from the entry; (None, why)
    when the path matches none or more than one."""
    if not path:
        return None, "an empty path matches no delivery"
    frontier = [_get(r, "eventId") for r in records
                if _get(r, "causeId") is None and _get(r, "eventType") == path[0]]
    for depth in range(1, len(path)):
        at = set(frontier)
        frontier = [_get(r, "eventId") for r in records
                    if _get(r, "causeId") in at and _get(r, "eventType") == path[depth]]
    if len(frontier) != 1:
        return None, str(len(frontier)) + " deliveries match the path"
    for r in records:
        if _get(r, "eventId") == frontier[0]:
            return r, None
    return None, "no record holds the delivery the path reaches"


# ── shape ───────────────────────────────────────────────────────────

def _emptyArrayIn(v, path):
    """The path of the first empty array inside v, or None."""
    seen = set()
    stack = [(v, path)]
    while stack:
        x, where = stack.pop()
        if id(x) in seen:
            continue
        if type(x) in (list, tuple):
            seen.add(id(x))
            if len(x) == 0:
                return where
            for i, y in enumerate(x):
                stack.append((y, where + "[" + str(i) + "]"))
        elif _isObject(x):
            seen.add(id(x))
            for k in list(x.keys()):
                stack.append((x[k], where + "." + str(k)))
    return None


def _eventProblems(e, where, p):
    if not _isObject(e) or type(e.get("type")) is not str:
        p.append(where + ": an event needs a string type")
        return
    for k in e:
        if k != "type" and k != "data":
            p.append(where + ": unexpected field " + str(k))
    if "data" in e:
        if not _isObject(e["data"]):
            p.append(where + ": data is not an object")
            return
        try:
            canon(e["data"])
        except ValueError as err:
            p.append(where + ": " + str(err))
        empty = _emptyArrayIn(e["data"], "data")
        if empty is not None:
            p.append(where + ": " + empty + " is an empty array (event data holds none: Lua cannot tell [] from {})")


def _outProblems(o, where, p):
    if _isObject(o) and "raw" in o and "type" not in o:
        return
    _eventProblems(o, where, p)


def _gateProblems(g, where, p):
    if not _isObject(g) or type(g.get("signature")) is not str:
        p.append(where + ": a gate needs a string signature")
        return
    d = g.get("does")
    if not _isObject(d):
        p.append(where + ": no behaviour")
        return
    keys = sorted(str(k) for k in d)
    if len(keys) != 1 or keys[0] not in BEHAVIOURS:
        p.append(where + ": a behaviour is exactly one of emit, emitOne, forward, nothing, throw, raw")
        return
    if "emit" in d:
        if not _isList(d["emit"]):
            p.append(where + ": emit takes a list")
        else:
            for i, o in enumerate(d["emit"]):
                _outProblems(o, where + ".emit[" + str(i + 1) + "]", p)
    if "emitOne" in d:
        _eventProblems(d["emitOne"], where + ".emitOne", p)
    if "forward" in d and type(d["forward"]) is not str:
        p.append(where + ": forward names a type")
    if "throw" in d and type(d["throw"]) is not str:
        p.append(where + ": throw takes a string")
    if "raw" in d and d["raw"] is None:
        p.append(where + ': raw null means "consumed" in Python and SISO_NOT_EVENT in Lua; use nothing or another raw value')


def _nodeProblems(n, where, p):
    if not _isObject(n) or type(n.get("type")) is not str or type(n.get("claimed")) is not bool:
        p.append(where + ": a node is { type, claimed, then? }")
        return
    if "then" in n:
        if not _isList(n["then"]):
            p.append(where + ": then is a list")
        else:
            for i, c in enumerate(n["then"]):
                _nodeProblems(c, where + ".then[" + str(i + 1) + "]", p)


def _expectProblems(x, where, p):
    if x == "settled" and type(x) is str:
        return
    if not _isObject(x):
        p.append(where + ': expect is "settled" or an object')
        return
    if ("settled" in x) == ("rejected" in x):
        p.append(where + ": expect exactly one of settled, rejected")
    if "rejected" in x:
        r = x["rejected"]
        if not _isObject(r) or type(r.get("code")) is not str or r.get("code") not in CODES:
            p.append(where + ": rejected needs a known code")
        else:
            if "at" in r and not _isList(r["at"]):
                p.append(where + ": at is a list of types")
            if "depth" in r and not _isInt(r["depth"]):
                p.append(where + ": depth is an integer")
    if "trace" in x:
        if not _isList(x["trace"]):
            p.append(where + ": trace is a list")
        else:
            for i, n in enumerate(x["trace"]):
                _nodeProblems(n, where + ".trace[" + str(i + 1) + "]", p)


def problems(f):
    """problems(fixture) -> list of problems with a decoded fixture's shape."""
    if not _isObject(f):
        return ["a fixture is an object"]
    p = []
    if not (_isInt(f.get("format")) and f.get("format") == 1):
        p.append("format is not 1")
    if type(f.get("name")) is not str:
        p.append("no name")
    if not _isList(f.get("covers")) or len(f.get("covers")) == 0:
        p.append("covers lists the items it pins")
    if not _isList(f.get("gates")):
        p.append("gates is a list")
    else:
        for i, g in enumerate(f["gates"]):
            _gateProblems(g, "gates[" + str(i + 1) + "]", p)
    steps = f.get("steps")
    if not _isList(steps) or len(steps) == 0:
        p.append("steps is a non-empty list")
        return p
    for i, s in enumerate(steps):
        where = "steps[" + str(i + 1) + "]"
        if not _isObject(s):
            p.append(where + ": not an object")
        elif "register" in s:
            _gateProblems(s["register"], where + ".register", p)
            if type(s.get("refused")) is not bool:
                p.append(where + ": register says whether it is refused")
        elif "emit" in s:
            _eventProblems(s["emit"], where + ".emit", p)
            _expectProblems(s.get("expect"), where, p)
        elif "emitRaw" in s:
            _expectProblems(s.get("expect"), where, p)
        elif "take" in s:
            if not _isList(s["take"]):
                p.append(where + ": take is a list")
            else:
                for j, e in enumerate(s["take"]):
                    _eventProblems(e, where + ".take[" + str(j + 1) + "]", p)
        elif "sample" in s:
            smp = s["sample"]
            if not _isObject(smp) or not _isList(smp.get("residue")) or not _isInt(smp.get("gateCount")):
                p.append(where + ": sample is { residue: [...], gateCount: n }")
        else:
            p.append(where + ": a step is one of register, emit, emitRaw, take, sample")
    return p
