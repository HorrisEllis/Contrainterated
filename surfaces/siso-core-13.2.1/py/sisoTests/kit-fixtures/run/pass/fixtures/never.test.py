# kit-run fixture: under a fixtures directory, so never entered.
import sys
sys.dont_write_bytecode = True  # no __pycache__ in the project tree

import os  # noqa: E402

sys.path.insert(0, os.path.normpath(os.path.join(os.path.dirname(os.path.abspath(__file__)), "..", "..", "..", "..", "..", "code")))

from siso import harness as H  # noqa: E402

print("never: a fixtures directory was entered")
H.test("never runs", lambda: H.fail("never"))
H.finish("never")
