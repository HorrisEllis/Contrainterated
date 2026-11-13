// kit-run fixture: a passing suite; "B" sorts before "a" byte-wise.
import * as H from "../../../../code/harness.mjs";

H.test("passes", () => H.assert(true));
H.finish("B");
