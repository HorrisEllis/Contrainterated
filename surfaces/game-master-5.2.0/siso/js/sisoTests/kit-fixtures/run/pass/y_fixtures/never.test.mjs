// kit-run fixture: under a fixtures directory, so never entered.
import * as H from "../../../../../code/harness.mjs";

console.log("never: a fixtures directory was entered");
H.test("never runs", () => H.fail("never"));
H.finish("never");
