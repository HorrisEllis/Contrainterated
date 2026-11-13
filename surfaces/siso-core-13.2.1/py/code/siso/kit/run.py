"""siso.kit.run -- every suite in the tests directory, with totals (py-kit-api).

Port of the suite half of lua/code/kit/run.lua (generalised from jaa
2.14.4 sisoTests/run.lua). There is no gate group and no digest line
(py-kit-api SUBSTRATE: kit.helper and kit.digest are not ported).

  python3 -B py/code/siso/kit/run.py [tests-dir]
                          (run from the project root; tests-dir defaults
                          to py/sisoTests; further arguments are ignored)

  from siso.kit import run as R      the same file, as a module

Each suite runs in its own process: this Python (sys.executable) with -B,
so no __pycache__ is written, and the suite's path, from the current
directory. A suite is any `*.test.py` file under the tests directory, at
any depth, except under a fixtures directory: a directory named
`fixtures` or ending in `-fixtures` / `_fixtures` (`kit-fixtures/`,
`substrate/fixtures/`) is never entered. Symbolic links are not followed.
Suites run in byte-wise path order (the bytes of each path as the file
system names it); their order carries no meaning.

A suite passes when it exits 0 and its last "<suite>: P/T passed" line
(on its standard output) has P = T and no FAILED. Each suite gets one
line; one without a result line has its output (standard output, then
standard error) shown, and one that printed a passing line but exited
non-zero says how it exited. Final line:
  run: S suites, P/T passed            (", F FAILED (files...)" otherwise)
Exit 0 only when every suite passes; a missing tests directory prints
"run: no tests directory <dir>" and exits 1.

This module imports only the standard library (os, re, subprocess, sys),
and nothing of the core: a runner stands outside what it runs.
"""

import os
import re
import subprocess
import sys

__all__ = ["DEFAULT_DIR", "SUFFIX", "isFixtureDir", "underFixtures", "pathKey", "suiteFiles",
           "result", "run", "main"]

DEFAULT_DIR = "py/sisoTests"
SUFFIX = ".test.py"

_FIXTURES = re.compile(r"[-_]fixtures\Z")
_RESULT = re.compile(r"^.*?: (\d+)/(\d+) passed")


def isFixtureDir(name):
    """Whether a path component names a fixtures directory."""
    return name == "fixtures" or _FIXTURES.search(name) is not None


def underFixtures(rel):
    """Whether a path relative to the tests directory is under a fixtures
    directory (its last component, the file, is not judged)."""
    parts = [p for p in rel.split("/") if p != ""]
    return any(isFixtureDir(p) for p in parts[:-1])


def pathKey(path):
    """The sort key of a path: its bytes, as the file system names it."""
    return os.fsencode(path)


def suiteFiles(dir):
    """Every suite file under dir, relative to it ("/"-separated), sorted
    byte-wise. Fixtures directories are never entered."""
    files = []
    pending = [(dir, "")]
    while pending:
        absDir, rel = pending.pop()
        try:
            with os.scandir(absDir) as it:
                entries = list(it)
        except OSError:
            continue
        for e in entries:
            r = e.name if rel == "" else rel + "/" + e.name
            try:
                if e.is_dir(follow_symlinks=False):
                    if not isFixtureDir(e.name):
                        pending.append((e.path, r))
                elif e.is_file(follow_symlinks=False) and e.name.endswith(SUFFIX):
                    files.append(r)
            except OSError:
                continue
    files.sort(key=pathKey)
    return files


def result(out):
    """The last result line of a suite's output and its counts:
    -> (line, passed, total), or (None, 0, 0) when there is none."""
    last = None
    for line in str(out).split("\n"):
        if _RESULT.match(line):
            last = line
    if last is None:
        return None, 0, 0
    m = re.search(r": (\d+)/(\d+) passed", last)
    return last, int(m.group(1)), int(m.group(2))


def _text(b):
    return (b or b"").decode("utf-8", "replace")


def run(dir, emit=None):
    """Runs every suite under dir and prints the report through emit (one
    line per call; print by default). -> {"suites", "passed", "total",
    "failed"}, `failed` listing the failing files."""
    if emit is None:
        def emit(line):
            print(line, flush=True)
    base = re.sub(r"/+\Z", "", str(dir)) + "/"
    files = suiteFiles(base)
    passed = total = 0
    failed = []
    for file in files:
        error = None
        try:
            r = subprocess.run([sys.executable, "-B", base + file], stdout=subprocess.PIPE,
                               stderr=subprocess.PIPE, stdin=subprocess.DEVNULL)
            stdout, stderr, status = _text(r.stdout), _text(r.stderr), r.returncode
        except OSError as err:
            stdout, stderr, status, error = "", "", None, err
        last, p, t = result(stdout)
        good = status == 0 and last is not None and p == t and "FAILED" not in last
        passed += p
        total += t
        emit("  " + ("ok" if good else "FAIL").ljust(4) + " " + file.ljust(45) + " " +
             ("(no result line)" if last is None else last))
        if not good:
            failed.append(file)
            if last is None:
                shown = (stdout + stderr + ("" if error is None else str(error))).rstrip("\n")
                if shown != "":
                    emit(shown)
            elif status != 0 and p == t and "FAILED" not in last:
                how = ("could not start" if status is None else
                       "signal " + str(-status) if status < 0 else "code " + str(status))
                emit("       exited with " + how)
    emit("")
    if not failed:
        emit("run: " + str(len(files)) + " suites, " + str(passed) + "/" + str(total) + " passed")
    else:
        emit("run: " + str(len(files)) + " suites, " + str(passed) + "/" + str(total) + " passed, " +
             str(len(failed)) + " FAILED (" + ", ".join(failed) + ")")
    return {"suites": len(files), "passed": passed, "total": total, "failed": failed}


def main(argv=None):
    """The command: -> the exit status (0 only when every suite passes)."""
    args = sys.argv[1:] if argv is None else list(argv)
    dir = args[0] if args else DEFAULT_DIR
    if not os.path.isdir(dir):
        print("run: no tests directory " + dir, flush=True)
        return 1
    return 0 if not run(dir)["failed"] else 1


if __name__ == "__main__":
    sys.exit(main())
