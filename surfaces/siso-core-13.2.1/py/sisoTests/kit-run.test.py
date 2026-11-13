# kit-run -- py/code/siso/kit/run.py, the suite runner (py-kit-api command
# line): it finds every *.test.py at any depth, never enters a fixtures
# directory, runs each suite in its own process (sys.executable -B),
# totals them and exits 0 only when all pass. Port of the run-related
# cases of lua/sisoTests/kit-helper.test.lua (the suite half of kit.run),
# on the fixture suites under py/sisoTests/kit-fixtures/run/ (which the
# runner never enters when it runs py/sisoTests).
#   python3 -B py/sisoTests/kit-run.test.py      (from any directory)
# Expected values: the final-line format is py-kit-api's; the counts are
# those of the fixture suites, counted by hand (pass/: a 2, B 1,
# fixtures.test.py 1, sub/deep 1; failing/: exits 1/1, fails 1/2, ok 1/1,
# silent none).
#
# Ported as Python's counterpart:
#   - "fixtures directories are never entered": the same underFixtures
#     cases, and suiteFiles on the fixture directory and on py/sisoTests.
#   - "totals every suite, runs gate tests twice, skips fixtures, exits 0"
#     -> totals every suite, each in its own process, skips fixtures,
#     exits 0; no gate group and no digest line.
#   - "a failing suite, or one without a result line, fails the run": the
#     same, plus a suite that prints a passing line and exits non-zero,
#     and a missing tests directory.
#   - Lua's runner starts each suite with the interpreter running it
#     (arg[-1]); here sys.executable with -B, so no suite writes
#     __pycache__ (checked on the fixture tree after the runs).
# Cannot apply (py-kit-api SUBSTRATE: kit.helper and kit.digest are not
# ported, so there is no gate group and no digest):
#   - "run: a gate test is a file that requires kit.helper and calls its
#     done()" -- there are no gate tests to tell apart.
#   - "run: a gate test whose digests disagree fails the run" -- no second
#     process, no digest line.
#   - The helper, canon, digest and summary cases of kit-helper.test.lua
#     are not runner cases (their modules are not ported), nor is its
#     kit-api module check with sha256 and helper (kit-data runs the check
#     on siso.kit.data).
# Lua's R.result skipped helper "shape:" lines; there are none here, so
# result() takes the last "<suite>: P/T passed" line as it stands.
import sys
sys.dont_write_bytecode = True  # no __pycache__ in the project tree

import contextlib  # noqa: E402
import io  # noqa: E402
import os  # noqa: E402
import subprocess  # noqa: E402

HERE = os.path.dirname(os.path.abspath(__file__))
sys.path.insert(0, os.path.normpath(os.path.join(HERE, "..", "code")))

from siso import harness as H  # noqa: E402
from siso.kit import run as R  # noqa: E402

test, assert_, assertEqual = H.test, H.assert_, H.assertEqual

# the project root, as the working directory the runner is started from
ROOT = os.path.normpath(os.path.join(HERE, "..", "..")) + os.sep
RUN = os.path.join(ROOT, "py", "code", "siso", "kit", "run.py")
FX = "py/sisoTests/kit-fixtures/run"


def runCmd(args, cwd=ROOT):
    """run.py as a command, from `cwd` (the project root by default)."""
    r = subprocess.run([sys.executable, "-B", RUN] + list(args), cwd=cwd, stdout=subprocess.PIPE,
                       stderr=subprocess.PIPE, stdin=subprocess.DEVNULL)
    return r.stdout.decode("utf-8", "replace") + r.stderr.decode("utf-8", "replace"), r.returncode


def lines(out):
    return [l for l in out.split("\n") if l != ""]


def lastLine(out):
    ls = lines(out)
    return ls[-1] if ls else None


def pycaches(top):
    found = []
    for d, dirs, _ in os.walk(top):
        found.extend(os.path.join(d, x) for x in dirs if x == "__pycache__")
    return found


# ── finding suites ──────────────────────────────────────────────────

def t_fixturesNeverEntered():
    assert_(R.underFixtures("kit-fixtures/run/pass/a.test.py"))
    assert_(R.underFixtures("substrate/fixtures/x.test.py"))
    assert_(R.underFixtures("a/b_fixtures/x.test.py"))
    assert_(not R.underFixtures("fixtures.test.py"), "a file named fixtures")
    assert_(not R.underFixtures("substrate/core.test.py"))
    assert_(not R.underFixtures("fixturesx/a.test.py"), "only the exact name or a -/_ suffix")
    assert_(R.isFixtureDir("fixtures") and R.isFixtureDir("kit-fixtures") and R.isFixtureDir("my_fixtures"))
    assert_(not R.isFixtureDir("fixture") and not R.isFixtureDir("kitfixtures"))
    files = R.suiteFiles(ROOT + FX + "/pass")
    assertEqual(",".join(files), "B.test.py,a.test.py,fixtures.test.py,sub/deep.test.py",
                "byte-wise, any depth, *.test.py only")


test("run: fixtures directories are never entered", t_fixturesNeverEntered)


def t_ownTreeSkipsFixtures():
    files = R.suiteFiles(ROOT + "py/sisoTests")
    assert_("kit-run.test.py" in files and "kit-data.test.py" in files, ",".join(files))
    assert_(not any("kit-fixtures" in f for f in files), "a fixture suite is listed: " + ",".join(files))
    assert_(all(f.endswith(".test.py") for f in files), "only suites")
    assertEqual(files, sorted(files, key=R.pathKey), "byte-wise order")


test("run: on py/sisoTests the kit's fixtures are never entered", t_ownTreeSkipsFixtures)


def t_byteOrder():
    key = R.pathKey
    assert_(key("B") < key("a") and key("a") < key("b"))
    assert_(key("a/b") > key("a.b"), "'/' (0x2f) after '.' (0x2e)")
    assert_(key("｡") < key("\U0001F600"), "code point (UTF-8) order, not UTF-16")


test("run: paths sort by their bytes", t_byteOrder)


# ── result lines ────────────────────────────────────────────────────

def t_result():
    assertEqual(R.result("x\nplain: 2/3 passed, 1 FAILED\n")[1], 2)
    assertEqual(R.result("x\nplain: 2/3 passed, 1 FAILED\n")[2], 3)
    assertEqual(R.result("a: 1/1 passed\nb: 4/5 passed\n")[0], "b: 4/5 passed", "the last one")
    assertEqual(R.result("no result here\n"), (None, 0, 0))
    assertEqual(R.result("")[1], 0)


test("run: result takes the last result line and its counts", t_result)


# ── the command ─────────────────────────────────────────────────────

def t_passingRun():
    out, status = runCmd([FX + "/pass"])
    assertEqual(status, 0, "exit status: " + out)
    ls = lines(out)
    for f in ("B.test.py", "a.test.py", "fixtures.test.py", "sub/deep.test.py"):
        assert_(any(l.startswith("  ok   " + f + " ") for l in ls), f + " not ok: " + out)
    assert_("never" not in out, "a fixtures directory or a non-suite was entered: " + out)
    assert_("digest" not in out, "no digest line")
    assertEqual(lastLine(out), "run: 4 suites, 5/5 passed")


test("run: totals every suite, each in its own process, skips fixtures, exits 0", t_passingRun)


def t_trailingSlash():
    out, status = runCmd([FX + "/pass/", "ignored"])
    assertEqual(status, 0, out)
    assertEqual(lastLine(out), "run: 4 suites, 5/5 passed")


test("run: a trailing slash and further arguments change nothing", t_trailingSlash)


def t_failingRun():
    out, status = runCmd([FX + "/failing"])
    assertEqual(status, 1, "a failing run exits 1: " + out)
    ls = lines(out)
    assert_(any(l.startswith("  ok   ok.test.py ") for l in ls), out)
    assert_(any(l.startswith("  FAIL fails.test.py ") and l.endswith("fails: 1/2 passed, 1 FAILED") for l in ls), out)
    assert_(any(l.startswith("  FAIL silent.test.py ") and l.endswith("(no result line)") for l in ls), out)
    assert_("no summary here" in out, "a suite without a result line has its output shown")
    assert_(any(l.startswith("  FAIL exits.test.py ") and l.endswith("exits: 1/1 passed") for l in ls), out)
    assert_("exited with code 2" in out, out)
    assertEqual(lastLine(out), "run: 4 suites, 3/4 passed, 3 FAILED (exits.test.py, fails.test.py, silent.test.py)")


test("run: a failing suite, one without a result line, or a non-zero exit fails the run", t_failingRun)


def t_missingDir():
    out, status = runCmd([FX + "/no-such-dir"])
    assertEqual(status, 1, out)
    assertEqual(lastLine(out), "run: no tests directory " + FX + "/no-such-dir")
    _, fileStatus = runCmd([FX + "/pass/a.test.py"])
    assertEqual(fileStatus, 1, "a file is not a tests directory")


test("run: a missing tests directory fails", t_missingDir)


def t_noSuites():
    out, status = runCmd([FX + "/none"])
    assertEqual(status, 0, out)
    assertEqual(lastLine(out), "run: 0 suites, 0/0 passed")


test("run: a directory without suites runs none and passes", t_noSuites)


def t_defaultDir():
    out, status = runCmd([], ROOT + FX + "/root")
    assertEqual(status, 0, out)
    assert_("  ok   one.test.py " in out, out)
    assertEqual(lastLine(out), "run: 1 suites, 1/1 passed")


test("run: the tests directory defaults to py/sisoTests under the current directory", t_defaultDir)


def t_module():
    got = []
    r = R.run(ROOT + FX + "/failing", got.append)
    assertEqual(r["suites"], 4)
    assertEqual(r["passed"], 3)
    assertEqual(r["total"], 4)
    assertEqual(",".join(r["failed"]), "exits.test.py,fails.test.py,silent.test.py")
    assertEqual(got[-1], "run: 4 suites, 3/4 passed, 3 FAILED (exits.test.py, fails.test.py, silent.test.py)")
    printed = io.StringIO()
    with contextlib.redirect_stdout(printed):
        status = R.main([ROOT + FX + "/no-such-dir"])
    assertEqual(status, 1, "main on a missing directory")
    assertEqual(printed.getvalue(), "run: no tests directory " + ROOT + FX + "/no-such-dir\n")


test("run: as a module, run() reports through emit and returns the totals", t_module)


def t_noBytecode():
    # every run above started its suites with sys.executable -B
    found = pycaches(ROOT + "py")
    assertEqual(found, [], "__pycache__ written under py/")


test("run: suites run with -B write no __pycache__", t_noBytecode)

H.finish("kit-run")
