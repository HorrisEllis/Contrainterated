// kit-run fixture: a passing suite (2 tests); never run by the main run.
import * as H from "../../../../code/harness.mjs";

H.test("this suite has its own process", () => {
  H.assertEqual(globalThis.kitRunFixtureSuite, undefined, "another suite ran in this process");
  globalThis.kitRunFixtureSuite = "a";
});
H.test("arithmetic", () => H.assertEqual(1 + 1, 2));
H.finish("a");
