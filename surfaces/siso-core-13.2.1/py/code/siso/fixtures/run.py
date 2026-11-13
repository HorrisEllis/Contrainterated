"""fixtures runner -- the Python runner of the language-agnostic suite
(contract py-fixture-format, py/code/siso/contracts/fixture_format.py).
Port of lua/code/fixtures/run.lua.

  python3 -B py/code/siso/fixtures/run.py [dir]     (from the project
                                                     root, or anywhere)
    runs every <dir>/*.json (default: the project's fixtures/, resolved
    from this script's own location, the suite every instance shares),
    sorted by name (byte-wise), decoding each with siso.kit.data.decode,
    and prints one line per fixture, then the final line
      "fixtures: F files, P/T steps passed"
    exit 0 only when every step of every file passes (and there is at
    least one file). Run as a script it puts py/code on sys.path itself
    and sets sys.dont_write_bytecode before importing siso.

  from siso.fixtures.run import run
  run(fixture, core=None) -> {"name", "passed", "total", "problems": [...]}
    one decoded fixture (or one written inline as Python dicts and
    lists), run on a fresh core["Stream"]() with one observer attached.
    `core` is a mapping {"Event", "Gate", "Stream"} (py-core-api's
    `core`); a key left out (or core None) is the real siso.core's. A
    test passes another Stream (any callable returning a stream) to run
    the same fixture against a stream it controls, or another Event and
    Gate to see what the runner hands them. Events are built with
    core["Event"], gates with core["Gate"].

Matching never happens here: shape is fixture_format.problems, residue
is residueMatch, a trace is traceMatch, "at" is recordAt, every batch is
observer_seam.batchProblems, every rejection core_api's
rejectionProblems, and a batch's rejection is judged against the one
raised by observer_seam.rejectionCopyProblem (the observer's own frozen
plain copy, core 13), never by identity.

SUBSTRATE (py-fixture-format): a key is present when `in` says so (JSON
null is None, a value: "emitRaw": null enters None). Fixture data (event
data, "raw" values, emitRaw entries) is copied fresh for each call
through siso.kit.data's API (py-kit-api): an array (data.isArray: a list
or a tuple) is copied as data.array(<a fresh list>), data.null (None) is
kept as itself, a mapping becomes a fresh dict, and every other value is
kept as it is (str, int, float, bool are immutable). No gate shares an
object with the fixture or with another call. A { throw } gate raises
Exception(message), and "cause" is compared to str(rejection.cause).
Only Exceptions are caught: a process-level exit passes through.

No module state: the observer's batch list is a local of run().
"""

import collections.abc
import os
import sys

HERE = os.path.dirname(os.path.abspath(__file__))

if __name__ == "__main__":
    sys.dont_write_bytecode = True
    # the script's own directory is not an import root; py/code is
    if sys.path and os.path.abspath(sys.path[0] or ".") == HERE:
        del sys.path[0]
    sys.path.insert(0, os.path.normpath(os.path.join(HERE, "..", "..")))

from siso.contracts import core_api as API  # noqa: E402
from siso.contracts import fixture_format as F  # noqa: E402
from siso.contracts import observer_seam as SEAM  # noqa: E402
from siso.core.event import Event as _Event  # noqa: E402
from siso.core.gate import Gate as _Gate  # noqa: E402
from siso.core.stream import Stream as _Stream  # noqa: E402
from siso.kit import data  # noqa: E402

__all__ = ["CORE", "DEFAULT_DIR", "copy", "run", "main"]

CORE = {"Event": _Event, "Gate": _Gate, "Stream": _Stream}

DEFAULT_DIR = os.path.normpath(os.path.join(HERE, "..", "..", "..", "..", "fixtures"))


def _str(v):
    """A value as Lua's tostring renders it, for messages only."""
    try:
        s = str(v)
        if isinstance(s, str):
            return s
    except Exception:
        pass
    return "<" + type(v).__name__ + ">"


def _strict(a, b):
    if type(a) is not type(b):
        return False
    try:
        return bool(a == b)
    except Exception:
        return False


def _field(o, k):
    """A field of a mapping (by key) or an object (by attribute); None when absent."""
    try:
        if isinstance(o, collections.abc.Mapping):
            return o[k] if k in o else None
        return getattr(o, k, None)
    except Exception:
        return None


# ── gates built from test-only behaviours ───────────────────────────

def copy(v):
    """Fixture data copied through siso.kit.data's API: an array stays an
    array (data.array of a fresh list), data.null is kept as itself, a
    mapping becomes a fresh dict, any other value is kept."""
    if v is data.null:
        return v
    if data.isArray(v):
        return data.array([copy(x) for x in v])
    if isinstance(v, collections.abc.Mapping):
        return {k: copy(x) for k, x in v.items()}
    return v


def _eventOf(Event, o):
    return Event(o["type"], copy(o["data"]) if "data" in o else None)


def _outOf(Event, o):
    if isinstance(o, collections.abc.Mapping) and "type" not in o and "raw" in o:
        return copy(o["raw"])
    return _eventOf(Event, o)


def _transformOf(Event, does):
    if "emit" in does:
        emit = does["emit"]
        return lambda e: [_outOf(Event, o) for o in emit]
    if "emitOne" in does:
        one = does["emitOne"]
        return lambda e: _outOf(Event, one)
    if "forward" in does:
        t = does["forward"]
        return lambda e: Event(t, e.data)
    if "nothing" in does:
        return lambda e: None
    if "throw" in does:
        message = does["throw"]

        def throw(e):
            raise Exception(message)

        return throw
    raw = does.get("raw")
    return lambda e: copy(raw)


def _gateOf(core, g):
    return core["Gate"](g["signature"], _transformOf(core["Event"], g["does"]))


# ── steps ───────────────────────────────────────────────────────────

def _addAll(into, label, lst):
    for s in lst:
        into.append(label + ": " + s)


def _check(into, label, ok, why):
    if not ok:
        into.append(label + ": " + _str(why))


def _show(err):
    code = _field(err, "code")
    if type(code) is str:
        return code + " (" + _str(_field(err, "message")) + ")"
    return type(err).__name__ + ": " + _str(err)


def _entry(s, batches, value, expect, raw, Event, p):
    """An entry through the door, against its expectation."""
    before = s.sampleHere()
    del batches[:]
    err = None
    try:
        s.emit(value)
    except Exception as x:
        err = x
    if len(batches) != 1:
        p.append("the observer was shown " + str(len(batches)) + " batches, expected 1")
        return
    b = batches[0]
    _addAll(p, "batch", SEAM.batchProblems(b))
    outcome = _field(b, "outcome")
    records = _field(b, "records")
    records = records if isinstance(records, list) else []
    x = expect
    isMap = isinstance(x, collections.abc.Mapping)
    if x == "settled" or (isMap and "settled" in x):
        if err is not None:
            p.append("expected settled, got rejected " + _show(err))
            return
        _check(p, "batch", outcome == "settled", "outcome " + _str(outcome))
    else:
        want = x["rejected"]
        if err is None:
            p.append("expected rejected " + _str(want.get("code")) + ", it settled")
            return
        _addAll(p, "rejection", API.rejectionProblems(err, {"noDelivery": True} if raw else None))
        _check(p, "batch", outcome == "rejected", "outcome " + _str(outcome))
        why = SEAM.rejectionCopyProblem(_field(b, "rejection"), err, Event)
        _check(p, "batch", why is None, why)
        for k in ("code", "eventType", "depth", "cause"):
            if k not in want:
                continue
            got = _field(err, k)
            if k == "cause" and isinstance(got, BaseException):
                got = _str(got)
            if not _strict(got, want[k]):
                p.append(k + ": expected " + _str(want[k]) + ", got " + _str(got))
        if "at" in want:
            at = want["at"]
            path = ">".join(_str(t) for t in at)
            rec, whyAt = F.recordAt(records, at)
            if rec is None:
                p.append("at " + path + ": " + _str(whyAt))
            elif (not _strict(_field(err, "eventId"), _field(rec, "eventId"))
                  or not _strict(_field(err, "causeId"), _field(rec, "causeId"))):
                p.append("at " + path + ": the rejection names delivery " +
                         _str(_field(err, "eventId")) + "<" + _str(_field(err, "causeId")) +
                         ", the path reaches " +
                         _str(_field(rec, "eventId")) + "<" + _str(_field(rec, "causeId")))
        # a rejected propagation commits nothing (K5)
        after = s.sampleHere()
        ok, whyK5 = F.residueMatch(before["residue"], after["residue"])
        _check(p, "rejection committed", ok, whyK5)
    if isMap and "trace" in x:
        ok, whyT = F.traceMatch(x["trace"], records)
        _check(p, "trace", ok, whyT)


def _step(core, s, batches, st, p):
    if "register" in st:
        sig = _str(st["register"]["signature"])
        err = None
        try:
            s.register(_gateOf(core, st["register"]))
        except Exception as x:
            err = x
        if st["refused"] and err is None:
            p.append("register " + sig + ": expected refused, it was accepted")
        elif not st["refused"] and err is not None:
            p.append("register " + sig + ": refused (" + _str(err) + ")")
    elif "emit" in st:
        _entry(s, batches, _eventOf(core["Event"], st["emit"]), st["expect"], False, core["Event"], p)
    elif "emitRaw" in st:
        _entry(s, batches, copy(st["emitRaw"]), st["expect"], True, core["Event"], p)
    elif "take" in st:
        ok, why = F.residueMatch(st["take"], s.takeResidue())
        _check(p, "take", ok, why)
    elif "sample" in st:
        smp = s.sampleHere()
        ok, why = F.residueMatch(st["sample"]["residue"], smp["residue"])
        _check(p, "sample", ok, why)
        got = _field(smp, "gateCount")
        _check(p, "sample", _strict(got, st["sample"]["gateCount"]),
               "gateCount: expected " + _str(st["sample"]["gateCount"]) + ", got " + _str(got))


# ── one fixture ─────────────────────────────────────────────────────

def run(fixture, core=None):
    """run(fixture, core=None) -> {"name", "passed", "total", "problems"}."""
    full = dict(CORE)
    if core is not None:
        full.update(core)
    core = full
    isMap = isinstance(fixture, collections.abc.Mapping)
    name = fixture.get("name") if isMap else None
    result = {"name": name if type(name) is str else "?", "passed": 0, "total": 1, "problems": []}
    shape = F.problems(fixture)
    steps = fixture.get("steps") if isMap else None
    if type(steps) in (list, tuple) and len(steps) > 0:
        result["total"] = len(steps)
    if shape:
        _addAll(result["problems"], "shape", shape)
        return result
    s = core["Stream"]()
    batches = []
    s.attach(lambda b: batches.append(b))
    for i, g in enumerate(fixture["gates"]):
        try:
            s.register(_gateOf(core, g))
        except Exception as err:
            result["problems"].append("gates[" + str(i + 1) + "]: refused (" + _str(err) + ")")
    if result["problems"]:
        return result
    for i, st in enumerate(steps):
        p = []
        try:
            _step(core, s, batches, st, p)
        except Exception as err:
            p.append("raised " + _show(err))
        if not p:
            result["passed"] += 1
        _addAll(result["problems"], "steps[" + str(i + 1) + "]", p)
    return result


# ── command line ────────────────────────────────────────────────────

def _byteKey(name):
    return os.fsencode(name)


def main(argv=None):
    """The command: -> the exit status (0 only when every step of every file passes)."""
    args = sys.argv[1:] if argv is None else list(argv)
    dir = args[0] if args else DEFAULT_DIR
    try:
        names = os.listdir(dir)
    except OSError:
        names = []
    files = sorted((n for n in names if n.endswith(".json")), key=_byteKey)
    passed, total, clean = 0, 0, len(files) > 0
    for name in files:
        try:
            with open(os.path.join(dir, name), "rb") as fh:
                raw = fh.read()
        except OSError:
            raw = b""
        try:
            text = raw.decode("utf-8")
            fixture, why = data.decode(text)
        except UnicodeDecodeError as err:
            fixture, why = None, "not UTF-8 (" + _str(err) + ")"
        if why is not None:
            r = {"name": "?", "passed": 0, "total": 1, "problems": ["decode: " + _str(why)]}
        else:
            r = run(fixture)
        passed += r["passed"]
        total += r["total"]
        good = r["passed"] == r["total"] and not r["problems"]
        clean = clean and good
        print(("ok    " if good else "FAIL  ") + name + "  " + str(r["passed"]) + "/" + str(r["total"]) +
              "  " + _str(r["name"]))
        for s in r["problems"]:
            print("        " + s)
    print("fixtures: " + str(len(files)) + " files, " + str(passed) + "/" + str(total) + " steps passed")
    sys.stdout.flush()
    return 0 if clean else 1


if __name__ == "__main__":
    sys.exit(main())
