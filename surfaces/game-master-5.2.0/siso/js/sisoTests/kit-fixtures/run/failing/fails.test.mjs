// kit-run fixture: a suite with one failing test.
import * as H from "../../../../code/harness.mjs";

H.test("passes", () => H.assert(true));
H.test("fails", () => H.assertEqual(1, 2, "on purpose"));
H.finish("fails");
