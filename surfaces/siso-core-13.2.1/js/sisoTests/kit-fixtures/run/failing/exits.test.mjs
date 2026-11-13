// kit-run fixture: a passing result line, then a non-zero exit.
import * as H from "../../../../code/harness.mjs";

H.test("passes", () => H.assert(true));
H.report("exits");
process.exit(2);
