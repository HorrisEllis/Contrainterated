# fixtures.test.py -- the language-agnostic suite (fixtures/*.json) passes
# through the Python runner (py/code/siso/fixtures/run.py), every file is
# a well-formed, portable fixture, and the runner fails a wrong
# expectation. Port of lua/sisoTests/fixtures.test.lua, case by case.
#   python3 -B py/sisoTests/fixtures.test.py   (from the project root, or anywhere)
#
# Turned into Python's own counterpart (py-fixture-format SUBSTRATE):
#   - "every file is portable": Lua flags data.null and an empty marked
#     array; here JSON null is None and an array a list, so the walk flags
#     None, an empty list and a float (siso.kit.data decodes "1.0" as a
#     float, as Lua's math.type tells it apart).
#   - "the command line runs the suite": `cd lua && lua5.4
#     code/fixtures/run.lua` becomes `python3 -B
#     py/code/siso/fixtures/run.py` from the project root, with this
#     process's Python (sys.executable).
#   - The inline fixture is written as Python dicts and lists; step labels
#     stay 1-based ("steps[2]"), as the contract's and the runner's are.
# No case is inapplicable.
import sys

sys.dont_write_bytecode = True

import os  # noqa: E402
import re  # noqa: E402
import subprocess  # noqa: E402

HERE = os.path.dirname(os.path.abspath(__file__))
ROOT = os.path.normpath(os.path.join(HERE, "..", ".."))
sys.path.insert(0, os.path.join(ROOT, "py", "code"))

from siso import harness as H  # noqa: E402
from siso.contracts import fixture_format as F  # noqa: E402
from siso.fixtures.run import run  # noqa: E402
from siso.kit import data  # noqa: E402

test, assert_, assertEqual = H.test, H.assert_, H.assertEqual


def joined(lst):
    return "; ".join(lst)


def has(lst, needle):
    return any(needle in s for s in lst)


# ── the suite on disk ───────────────────────────────────────────────

DIR = os.path.join(ROOT, "fixtures")


def _files():
    try:
        names = os.listdir(DIR)
    except OSError:
        names = []
    return sorted((n for n in names if n.endswith(".json")), key=os.fsencode)


files = _files()
_decoded = {}


def load(name):
    """A file decoded by the kit, or {"decodeError": why}."""
    if name not in _decoded:
        with open(os.path.join(DIR, name), "rb") as fh:
            text = fh.read().decode("utf-8")
        v, why = data.decode(text)
        _decoded[name] = {"decodeError": str(why)} if why is not None else v
    return _decoded[name]


def _suiteExists():
    assert_(len(files) >= 15, "fixtures/ holds " + str(len(files)) + " files")


test("the suite exists", _suiteExists)


def _wellFormed():
    bad = []
    for name in files:
        f = load(name)
        if isinstance(f, dict) and "decodeError" in f:
            bad.append(name + ": " + f["decodeError"])
        else:
            for s in F.problems(f):
                bad.append(name + ": " + s)
    assert_(len(bad) == 0, joined(bad))


test("every file decodes and is a well-formed fixture", _wellFormed)


# nothing in a file may depend on Python: integers only, no null, no empty arrays
def _portable():
    bad = []

    def walk(v, where):
        stack = [(v, where)]
        while stack:
            x, at = stack.pop()
            if x is data.null:
                bad.append(at + ": null")
            elif type(x) is float:
                bad.append(at + ": float")
            elif data.isArray(x):
                if len(x) == 0:
                    bad.append(at + ": empty array")
                for i, y in enumerate(x):
                    stack.append((y, at + "." + str(i + 1)))
            elif isinstance(x, dict):
                for k, y in x.items():
                    stack.append((y, at + "." + str(k)))

    for name in files:
        walk(load(name), name)
    assert_(len(bad) == 0, joined(bad))


test("every file is portable", _portable)


def _covers():
    covered = set()
    for name in files:
        f = load(name)
        for c in (f.get("covers") or []) if isinstance(f, dict) else []:
            covered.add(c)
    missing = [i for i in ("Rule 1", "Rule 3", "Rule 4", "K2", "K3", "K4", "K5", "K6", "The outside")
               if i not in covered]
    assert_(len(missing) == 0, "not covered: " + joined(missing))


test("the suite covers each rule and concession the core pins", _covers)


def _everyPasses():
    bad = []
    for name in files:
        r = run(load(name))
        if r["passed"] != r["total"] or r["problems"]:
            bad.append(name + " " + str(r["passed"]) + "/" + str(r["total"]) + ": " + joined(r["problems"]))
    assert_(len(bad) == 0, joined(bad))


test("every fixture passes through the runner", _everyPasses)


def _commandLine():
    proc = subprocess.run([sys.executable, "-B", os.path.join("py", "code", "siso", "fixtures", "run.py")],
                          cwd=ROOT, stdout=subprocess.PIPE, stderr=subprocess.STDOUT, stdin=subprocess.DEVNULL)
    lines = proc.stdout.decode("utf-8", "replace").splitlines()
    last = lines[-1] if lines else None
    assert_(proc.returncode == 0, "the runner exited non-zero: " + str(last))
    m = re.match(r"^fixtures: (\d+) files, (\d+)/(\d+) steps passed$", str(last))
    assert_(m is not None, "final line: " + str(last))
    assertEqual(int(m.group(1)), len(files), "files")
    assertEqual(m.group(2), m.group(3), "steps passed")


test("the command line runs the suite and reports it", _commandLine)


# ── the runner fails a wrong expectation ────────────────────────────

# a passing fixture, and copies of it with one expectation wrong
def base():
    return {
        "format": 1,
        "name": "inline",
        "covers": ["K1"],
        "gates": [
            {"signature": "a", "does": {"emitOne": {"type": "b", "data": {"v": 1}}}},
            {"signature": "b", "does": {"emit": [{"type": "c"}, {"type": "d"}]}},
            {"signature": "d", "does": {"throw": "d failed"}},
            {"signature": "loop", "does": {"emitOne": {"type": "loop"}}},
            {"signature": "x", "does": {"forward": "y"}},
        ],
        "steps": [
            {"emit": {"type": "x", "data": {"n": 2}}, "expect": {
                "settled": True,
                "trace": [{"type": "x", "claimed": True, "then": [{"type": "y", "claimed": False}]}]}},
            {"emit": {"type": "a"}, "expect": {"rejected": {
                "code": "SISO_GATE_THREW", "eventType": "d", "cause": "d failed", "at": ["a", "b", "d"]}}},
            {"emit": {"type": "loop"}, "expect": {"rejected": {
                "code": "SISO_SELF_LOOP", "eventType": "loop", "at": ["loop"]}}},
            {"register": {"signature": "x", "does": {"nothing": True}}, "refused": True},
            {"sample": {"residue": [{"type": "y", "data": {"n": 2}}], "gateCount": 5}},
            {"take": [{"type": "y", "data": {"n": 2}}]},
        ],
    }


def failsAt(label, mutate, stepIndex, needle):
    def case():
        f = base()
        mutate(f)
        r = run(f)
        assert_(r["passed"] == r["total"] - 1, label + ": " + str(r["passed"]) + "/" + str(r["total"]) +
                " passed, expected one failure: " + joined(r["problems"]))
        assert_(has(r["problems"], "steps[" + str(stepIndex) + "]"),
                label + ": the failure is not at step " + str(stepIndex) + ": " + joined(r["problems"]))
        assert_(has(r["problems"], needle), label + ": no '" + needle + "' in " + joined(r["problems"]))

    test("the runner fails " + label, case)


def _inlinePasses():
    r = run(base())
    assert_(r["passed"] == r["total"] and not r["problems"],
            str(r["passed"]) + "/" + str(r["total"]) + ": " + joined(r["problems"]))


test("the inline fixture passes as written", _inlinePasses)


def _set(path, value):
    """A mutation: f[path...] = value."""
    def mutate(f):
        t = f
        for k in path[:-1]:
            t = t[k]
        t[path[-1]] = value
    return mutate


def _extraCopy(f):
    f["steps"][4]["sample"]["residue"].append({"type": "y", "data": {"n": 2}})


def _settledOnRejection(f):
    f["steps"][1]["expect"] = "settled"


def _freshRegistration(f):
    f["steps"][3]["register"]["signature"] = "fresh"
    f["steps"][4]["sample"]["gateCount"] = 6


failsAt("a wrong residue in a take", _set(["steps", 5, "take", 0, "data", "n"], 3), 6, "residue")
failsAt("an extra copy in a sample", _extraCopy, 5, "residue")
failsAt("a wrong gate count", _set(["steps", 4, "sample", "gateCount"], 4), 5, "gateCount")
failsAt("a wrong trace", _set(["steps", 0, "expect", "trace", 0, "then", 0, "claimed"], True), 1, "trace")
failsAt("a wrong code", _set(["steps", 2, "expect", "rejected", "code"], "SISO_CYCLE"), 3, "code")
failsAt('a wrong "at"', _set(["steps", 1, "expect", "rejected", "at"], ["a", "b"]), 2, "at a>b")
failsAt('an "at" that reaches no delivery', _set(["steps", 1, "expect", "rejected", "at"], ["a", "c", "d"]), 2,
        "0 deliveries")
failsAt("a wrong cause", _set(["steps", 1, "expect", "rejected", "cause"], "other"), 2, "cause")
failsAt("a wrong event type", _set(["steps", 2, "expect", "rejected", "eventType"], "a"), 3, "eventType")
failsAt("a settled expectation on a rejection", _settledOnRejection, 2, "expected settled")
failsAt("a rejection expected of a settled entry",
        _set(["steps", 0, "expect"], {"rejected": {"code": "SISO_NOT_EVENT"}}), 1, "it settled")
failsAt("a refused registration expected to succeed", _set(["steps", 3, "refused"], False), 4, "refused")
failsAt("an accepted registration expected to be refused", _freshRegistration, 4, "expected refused")


def _malformed():
    f = base()
    f["steps"][1]["expect"]["rejected"]["code"] = "NOPE"
    r = run(f)
    assertEqual(r["passed"], 0, "passed")
    assert_(has(r["problems"], "shape"), joined(r["problems"]))


test("the runner fails a malformed fixture", _malformed)


def _throwMessage():
    f = base()
    f["steps"] = [{"emit": {"type": "d"}, "expect": {"rejected": {
        "code": "SISO_GATE_THREW", "cause": "d failed", "at": ["d"]}}}]
    r = run(f)
    assert_(r["passed"] == 1, joined(r["problems"]))


test("a gate built from { throw } raises exactly its message", _throwMessage)

H.finish("fixtures")
