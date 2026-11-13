// kit-run fixture: a passing suite one directory down.
import * as H from "../../../../../code/harness.mjs";

H.test("this suite has its own process", () => {
  H.assertEqual(globalThis.kitRunFixtureSuite, undefined, "another suite ran in this process");
  globalThis.kitRunFixtureSuite = "deep";
});
H.finish("deep");
