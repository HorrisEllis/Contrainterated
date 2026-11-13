// kit-run fixture: a suite whose FILE is named fixtures (only directories
// named so are skipped).
import * as H from "../../../../code/harness.mjs";

H.test("passes", () => H.assert(true));
H.finish("fixtures-file");
