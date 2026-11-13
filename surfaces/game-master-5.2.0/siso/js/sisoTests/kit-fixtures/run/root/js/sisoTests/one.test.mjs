// kit-run fixture: the default tests directory (js/sisoTests) of a
// project rooted at kit-fixtures/run/root.
import * as H from "../../../../../../code/harness.mjs";

H.test("found by default", () => H.assert(true));
H.finish("one");
