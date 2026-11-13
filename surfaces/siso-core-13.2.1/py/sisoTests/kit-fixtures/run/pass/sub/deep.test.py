# kit-run fixture: a passing suite one directory down.
import sys
sys.dont_write_bytecode = True  # no __pycache__ in the project tree

import os  # noqa: E402

sys.path.insert(0, os.path.normpath(os.path.join(os.path.dirname(os.path.abspath(__file__)), "..", "..", "..", "..", "..", "code")))

from siso import harness as H  # noqa: E402

import builtins  # noqa: E402


def ownProcess():
    H.assertEqual(getattr(builtins, "kitRunFixtureSuite", None), None, "another suite ran in this process")
    builtins.kitRunFixtureSuite = "deep"


H.test("this suite has its own process", ownProcess)
H.finish("deep")
