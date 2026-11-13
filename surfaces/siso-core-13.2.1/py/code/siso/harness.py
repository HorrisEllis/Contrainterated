"""harness -- the test harness every Python suite reports through.

Port of lua/code/harness.lua, itself the port of core 11.1
`tests/runner.js` (doc/log/legacy-js/tests/runner.js), unchanged in
behaviour. Shared platform: no phase writes it.

  H.test(name, fn)  H.assert_(c, msg)  H.assertEqual(a, e, label)
  H.assertClose(a, e, epsilon, label)  H.assertThrows(fn, substring, label)
  H.fail(msg)  H.show(v)  H.counts()  H.report(suite)  H.finish(suite)
  H.reset()

(the module is imported as H: siso.harness, with py/code/ on sys.path)

Final line: "{suite}: {passed}/{total} passed"
        or  "{suite}: {passed}/{total} passed, {failed} FAILED"
report() prints the failures before that line, so the summary is always
the LAST line of the output; finish() then exits with code 0 when every
test passed, else 1.

Assertion failures are raised as the harness's own AssertionFailure, so
the report shows the message alone; any other exception is reported with
its own message: an exception with a str `message` attribute (such as a
rejection) shows that message, any other as "<TypeName>: <str(exc)>"
(guarded: a __str__ that raises shows the type name alone).

Tests are synchronous, as in the reference: a test function that returns
a coroutine (an `async def`) is counted as failed, since its outcome
would arrive after the test was counted; the coroutine is closed.

SUBSTRATE (Python)
  - Lua's `assert` is a Python keyword: the assertion is `assert_`.
  - assertEqual is strict, as Lua's is: the same value and the same type,
    so 1 and 1.0 differ and True and 1 differ (Python's == says
    True == 1 == 1.0). Lists, tuples and dicts are compared element by
    element under the same rule, each with its own type (Lua compares
    tables by identity; Python containers are values, so the strict rule
    is applied inside them). Anything else is compared with ==.
  - Only exceptions are caught (`Exception`): a process-level exit
    (SystemExit, KeyboardInterrupt) raised by a test passes through, as
    Lua's os.exit leaves the runner.
  - show() renders with repr (Lua's %q for strings, %.17g for floats);
    a float shows as Python writes it (1.0), so its type is visible.
  - Output that the terminal's encoding cannot carry (the ✗ and — marks)
    is written with backslash escapes instead of failing.
"""

import sys

_state = {"passed": 0, "failed": 0, "failures": []}


class AssertionFailure(Exception):
    """An assertion's failure: the report shows its message alone."""


def _failWith(message):
    raise AssertionFailure(message)


def _out(line=""):
    try:
        print(line)
    except UnicodeEncodeError:
        enc = getattr(sys.stdout, "encoding", None) or "ascii"
        print(line.encode(enc, "backslashreplace").decode(enc, "replace"))


def _str(v):
    try:
        s = str(v)
        if isinstance(s, str):
            return s
    except Exception:
        pass
    return "<" + type(v).__name__ + ">"


def _messageOf(err):
    if isinstance(err, AssertionFailure):
        return _str(err.args[0]) if err.args else "assertion failed"
    try:
        m = getattr(err, "message", None)
    except Exception:
        m = None
    if isinstance(m, str):
        return m
    return type(err).__name__ + ": " + _str(err)


def show(v):
    """A rendering for failure messages only; never for comparison."""
    try:
        s = repr(v)
        if isinstance(s, str):
            return s
    except Exception:
        pass
    return "<" + type(v).__name__ + ">"


def _strictEqual(a, b, depth=0):
    if depth > 200:
        return a is b
    if type(a) is not type(b):
        return False
    if isinstance(a, (list, tuple)):
        if len(a) != len(b):
            return False
        return all(_strictEqual(x, y, depth + 1) for x, y in zip(a, b))
    if isinstance(a, dict):
        if len(a) != len(b):
            return False
        for k, x in a.items():
            if k not in b or not _strictEqual(x, b[k], depth + 1):
                return False
        return True
    try:
        return bool(a == b)
    except Exception:
        return a is b


def test(name, fn):
    """Runs one test and counts it; a failure is printed at once."""
    try:
        r = fn()
        if type(r).__name__ == "coroutine" and hasattr(r, "close"):
            r.close()
            _failWith("the test returned a coroutine; tests are synchronous")
        _state["passed"] += 1
    except Exception as err:  # the test's own failure; exits pass through
        _state["failed"] += 1
        message = _messageOf(err)
        _state["failures"].append((name, message))
        _out("  ✗  " + name)
        _out("     " + message)


def assert_(condition, message=None):
    if not condition:
        _failWith("assertion failed" if message is None else _str(message))


def assertEqual(actual, expected, label=None):
    """Strict equality: same value and same type (1, 1.0 and True differ)."""
    if not _strictEqual(actual, expected):
        prefix = (label + ": ") if label else ""
        _failWith(prefix + "expected " + show(expected) + ", got " + show(actual))


def assertClose(actual, expected, epsilon=1e-10, label=None):
    try:
        close = abs(actual - expected) <= epsilon
    except Exception:
        close = False
    if not close:
        prefix = (label + ": ") if label else ""
        _failWith(prefix + "expected ≈" + show(expected) + ", got " + show(actual))


def assertThrows(fn, substring=None, label=None):
    prefix = (label + ": ") if label else ""
    try:
        fn()
    except Exception as err:
        message = _messageOf(err)
        if substring and substring not in message:
            _failWith(prefix + "expected error containing '" + substring + "', got '" + message + "'")
        return
    _failWith(prefix + "expected error, none thrown")


def fail(message):
    _failWith(message)


def counts():
    return _state["passed"], _state["failed"]


def report(suite):
    passed, failed = _state["passed"], _state["failed"]
    total = passed + failed
    _out()
    if failed == 0:
        _out(suite + ": " + str(passed) + "/" + str(total) + " passed")
    else:
        # the failures first, so the summary is always the last line
        for name, message in _state["failures"]:
            _out("  FAIL: " + name + " — " + message)
        _out(suite + ": " + str(passed) + "/" + str(total) + " passed, " + str(failed) + " FAILED")
    try:
        sys.stdout.flush()
    except Exception:
        pass
    return failed


def finish(suite):
    """report() and exit with the original runner's code (0 on pass)."""
    n = report(suite)
    sys.exit(0 if n == 0 else 1)


def reset():
    _state["passed"] = 0
    _state["failed"] = 0
    _state["failures"] = []
