# kit-run fixture: a passing suite; "B" sorts before "a" byte-wise.
import sys
sys.dont_write_bytecode = True  # no __pycache__ in the project tree

import os  # noqa: E402

sys.path.insert(0, os.path.normpath(os.path.join(os.path.dirname(os.path.abspath(__file__)), "..", "..", "..", "..", "code")))

from siso import harness as H  # noqa: E402

H.test("passes", lambda: H.assert_(True))
H.finish("B")
