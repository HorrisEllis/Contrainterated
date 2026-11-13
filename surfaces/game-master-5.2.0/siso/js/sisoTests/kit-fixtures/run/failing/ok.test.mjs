// kit-run fixture: a passing suite among failing ones.
import * as H from "../../../../code/harness.mjs";

H.test("passes", () => H.assert(true));
H.finish("ok");
