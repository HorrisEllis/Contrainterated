// kit-run — js/code/kit/run.mjs, the suite runner (kit-api command line):
// it finds every *.test.mjs at any depth, never enters a fixtures
// directory, runs each suite in its own process, totals them and exits 0
// only when all pass. Port of the run-related cases of
// lua/sisoTests/kit-helper.test.lua (the suite half of kit.run), on the
// fixture suites under js/sisoTests/kit-fixtures/run/ (which the runner
// never enters when it runs js/sisoTests).
//   node js/sisoTests/kit-run.test.mjs         (from the project root)
// Expected values: the final-line format is kit-api's; the counts are
// those of the fixture suites, counted by hand (pass/: a 2, B 1,
// fixtures.test.mjs 1, sub/deep 1; failing/: exits 1/1, fails 1/2, ok 1/1,
// silent none).
//
// Ported as JavaScript's counterpart:
//   - "fixtures directories are never entered": the same underFixtures
//     cases, and suiteFiles on the fixture directory.
//   - "totals every suite, runs gate tests twice, skips fixtures, exits 0"
//     → totals every suite, each in its own process, skips fixtures,
//     exits 0; no gate group and no digest line.
//   - "a failing suite, or one without a result line, fails the run": the
//     same, plus a suite that prints a passing line and exits non-zero.
// Cannot apply (kit-api SUBSTRATE: kit.helper and kit.digest are not
// ported, so there is no gate group and no digest):
//   - "run: a gate test is a file that requires kit.helper and calls its
//     done()" — there are no gate tests to tell apart.
//   - "run: a gate test whose digests disagree fails the run" — no second
//     process, no digest line.
//   - The helper, canon, digest and summary cases of kit-helper.test.lua
//     are not runner cases (their modules are not ported).
// Lua's R.result skipped helper "shape:" lines; there are none here, so
// result() takes the last "<suite>: P/T passed" line as it stands.
import * as H from "../code/harness.mjs";
import * as R from "../code/kit/run.mjs";
import { spawnSync } from "node:child_process";
import { fileURLToPath } from "node:url";
import { dirname, resolve, sep } from "node:path";

const { test, assert, assertEqual } = H;

// the project root, as the working directory the runner is started from
// (a directory, not a module this suite depends on)
const ROOT = resolve(dirname(fileURLToPath(import.meta.url)), "..", "..") + sep;
const RUN = fileURLToPath(new URL("../code/kit/run.mjs", import.meta.url));
const FX = "js/sisoTests/kit-fixtures/run";

// run.mjs as a command, from `cwd` (the project root by default)
function runCmd(args, cwd = ROOT) {
  const r = spawnSync(process.execPath, [RUN, ...args], { cwd, encoding: "utf8" });
  return { out: `${r.stdout}${r.stderr}`, status: r.status };
}

const lines = (out) => out.split("\n").filter((l) => l !== "");
const lastLine = (out) => lines(out).at(-1);

// ── finding suites ────────────────────────────

test("run: fixtures directories are never entered", () => {
  assert(R.underFixtures("kit-fixtures/run/pass/a.test.mjs"));
  assert(R.underFixtures("substrate/fixtures/x.test.mjs"));
  assert(R.underFixtures("a/b_fixtures/x.test.mjs"));
  assert(!R.underFixtures("fixtures.test.mjs"), "a file named fixtures");
  assert(!R.underFixtures("substrate/core.test.mjs"));
  assert(!R.underFixtures("fixturesx/a.test.mjs"), "only the exact name or a -/_ suffix");
  assert(R.isFixtureDir("fixtures") && R.isFixtureDir("kit-fixtures") && R.isFixtureDir("my_fixtures"));
  assert(!R.isFixtureDir("fixture") && !R.isFixtureDir("kitfixtures"));
  const files = R.suiteFiles(`${ROOT}${FX}/pass`);
  assertEqual(files.join(","), "B.test.mjs,a.test.mjs,fixtures.test.mjs,sub/deep.test.mjs", "byte-wise, any depth, *.test.mjs only");
});

test("run: on js/sisoTests the kit's fixtures are never entered", () => {
  const files = R.suiteFiles(`${ROOT}js/sisoTests`);
  assert(files.includes("kit-run.test.mjs") && files.includes("kit-data.test.mjs"), files.join(","));
  assert(!files.some((f) => f.includes("kit-fixtures")), `a fixture suite is listed: ${files.join(",")}`);
  assert(!files.some((f) => !f.endsWith(".test.mjs")), "only suites");
  const sorted = [...files].sort(R.byteCompare);
  assertEqual(files.join(","), sorted.join(","), "byte-wise order");
});

test("run: paths sort by UTF-8 bytes", () => {
  assert(R.byteCompare("B", "a") < 0 && R.byteCompare("a", "b") < 0);
  assert(R.byteCompare("a/b", "a.b") > 0, "'/' (0x2f) after '.' (0x2e)");
  assert(R.byteCompare("｡", "\u{1F600}") < 0, "code point order, not UTF-16");
});

// ── result lines ──────────────────────────────

test("run: result takes the last result line and its counts", () => {
  assertEqual(R.result("x\nplain: 2/3 passed, 1 FAILED\n")[1], 2);
  assertEqual(R.result("x\nplain: 2/3 passed, 1 FAILED\n")[2], 3);
  assertEqual(R.result("a: 1/1 passed\nb: 4/5 passed\n")[0], "b: 4/5 passed", "the last one");
  assertEqual(R.result("no result here\n")[0], null);
  assertEqual(R.result("")[1], 0);
});

// ── the command ───────────────────────────────

test("run: totals every suite, each in its own process, skips fixtures, exits 0", () => {
  const { out, status } = runCmd([`${FX}/pass`]);
  assertEqual(status, 0, `exit status: ${out}`);
  const ls = lines(out);
  for (const f of ["B.test.mjs", "a.test.mjs", "fixtures.test.mjs", "sub/deep.test.mjs"]) {
    assert(ls.some((l) => l.startsWith(`  ok   ${f} `)), `${f} not ok: ${out}`);
  }
  assert(!out.includes("never"), `a fixtures directory or a non-suite was entered: ${out}`);
  assert(!out.includes("digest"), "no digest line");
  assertEqual(lastLine(out), "run: 4 suites, 5/5 passed");
});

test("run: a trailing slash and further arguments change nothing", () => {
  const { out, status } = runCmd([`${FX}/pass/`, "ignored"]);
  assertEqual(status, 0, out);
  assertEqual(lastLine(out), "run: 4 suites, 5/5 passed");
});

test("run: a failing suite, one without a result line, or a non-zero exit fails the run", () => {
  const { out, status } = runCmd([`${FX}/failing`]);
  assertEqual(status, 1, `a failing run exits 1: ${out}`);
  const ls = lines(out);
  assert(ls.some((l) => l.startsWith("  ok   ok.test.mjs ")), out);
  assert(ls.some((l) => l.startsWith("  FAIL fails.test.mjs ") && l.endsWith("fails: 1/2 passed, 1 FAILED")), out);
  assert(ls.some((l) => l.startsWith("  FAIL silent.test.mjs ") && l.endsWith("(no result line)")), out);
  assert(out.includes("no summary here"), "a suite without a result line has its output shown");
  assert(ls.some((l) => l.startsWith("  FAIL exits.test.mjs ") && l.endsWith("exits: 1/1 passed")), out);
  assert(out.includes("exited with code 2"), out);
  assertEqual(lastLine(out), "run: 4 suites, 3/4 passed, 3 FAILED (exits.test.mjs, fails.test.mjs, silent.test.mjs)");
});

test("run: a missing tests directory fails", () => {
  const { out, status } = runCmd([`${FX}/no-such-dir`]);
  assertEqual(status, 1, out);
  assertEqual(lastLine(out), `run: no tests directory ${FX}/no-such-dir`);
  const file = runCmd([`${FX}/pass/a.test.mjs`]);
  assertEqual(file.status, 1, "a file is not a tests directory");
});

test("run: a directory without suites runs none and passes", () => {
  const { out, status } = runCmd([`${FX}/none`]);
  assertEqual(status, 0, out);
  assertEqual(lastLine(out), "run: 0 suites, 0/0 passed");
});

test("run: the tests directory defaults to js/sisoTests under the current directory", () => {
  const { out, status } = runCmd([], `${ROOT}${FX}/root`);
  assertEqual(status, 0, out);
  assert(out.includes("  ok   one.test.mjs "), out);
  assertEqual(lastLine(out), "run: 1 suites, 1/1 passed");
});

test("run: as a module, run() reports through emit and returns the totals", () => {
  const got = [];
  const r = R.run(`${ROOT}${FX}/failing`, (l) => got.push(l));
  assertEqual(r.suites, 4);
  assertEqual(r.passed, 3);
  assertEqual(r.total, 4);
  assertEqual(r.failed.join(","), "exits.test.mjs,fails.test.mjs,silent.test.mjs");
  assertEqual(got.at(-1), "run: 4 suites, 3/4 passed, 3 FAILED (exits.test.mjs, fails.test.mjs, silent.test.mjs)");
});

H.finish("kit-run");
