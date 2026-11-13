// fixtures: the language-agnostic suite (fixtures/*.json) passes through
// the JavaScript runner (js/code/fixtures/run.mjs), every file is a
// well-formed, portable fixture (its shape checked by js-fixture-format's
// problems()), and the runner fails a wrong expectation. Port of
// lua/sisoTests/fixtures.test.lua.
//   node js/sisoTests/fixtures.test.mjs        (from the project root)
//
// Kept case by case. Turned into JavaScript's counterpart
// (js-fixture-format SUBSTRATE, js-kit-api SUBSTRATE):
//   - "every file is portable": Lua's data.null sentinel is JSON null,
//     which is null here; Lua's marked empty array is an empty Array.
//     Lua's math.type float check becomes "a number that is not an
//     integer": JS decodes 1.0 as 1, so only a non-integral float can be
//     caught (the fixtures hold integers only).
//   - "the command line runs the suite and reports it": `lua5.4
//     code/fixtures/run.lua` from lua/ becomes `node
//     js/code/fixtures/run.mjs` from the project root.
//   - "a gate built from { throw } raises exactly its message": the gate
//     throws the string itself, so the rejection's cause is that string
//     (===).
// Added for the JavaScript command line: an explicit directory argument,
// and a directory that holds no fixture (exit 1, as Lua's runner does on
// an empty `ls`).
// No case is left out.
import * as H from "../code/harness.mjs";
import * as F from "../code/contracts/fixture_format.mjs";
import { data } from "../code/kit/data.mjs";
import { run } from "../code/fixtures/run.mjs";
import { readdirSync, readFileSync } from "node:fs";
import { spawnSync } from "node:child_process";
import { dirname, join, resolve } from "node:path";
import { fileURLToPath } from "node:url";

const joined = (list) => list.join("; ");
const has = (list, needle) => list.some((s) => s.includes(needle));

// ── the suite on disk ───────────────────────────────────────────

const HERE = dirname(fileURLToPath(import.meta.url));
const ROOT = resolve(HERE, "..", "..");
const DIR = join(ROOT, "fixtures");
const RUNNER = join(ROOT, "js", "code", "fixtures", "run.mjs");

const byteCompare = (a, b) => Buffer.compare(Buffer.from(a, "utf8"), Buffer.from(b, "utf8"));
const files = (() => {
  try {
    return readdirSync(DIR).filter((n) => n.endsWith(".json")).sort(byteCompare);
  } catch {
    return [];
  }
})();

const decoded = new Map();
function load(name) {
  if (!decoded.has(name)) {
    const [v, why] = data.decode(readFileSync(join(DIR, name), "utf8"));
    decoded.set(name, why === null ? v : { decodeError: String(why) });
  }
  return decoded.get(name);
}

H.test("the suite exists", () => {
  H.assert(files.length >= 15, `fixtures/ holds ${files.length} files`);
});

H.test("every file decodes and is a well-formed fixture", () => {
  const bad = [];
  for (const name of files) {
    const f = load(name);
    if (f !== null && typeof f === "object" && f.decodeError !== undefined) bad.push(`${name}: ${f.decodeError}`);
    else for (const s of F.problems(f)) bad.push(`${name}: ${s}`);
  }
  H.assert(bad.length === 0, joined(bad));
});

// nothing in a file may depend on one instance: integers only, no null,
// no empty arrays
H.test("every file is portable", () => {
  const bad = [];
  const walk = (v, where) => {
    if (v === data.null) bad.push(`${where}: null`);
    else if (typeof v === "number" && !Number.isInteger(v)) bad.push(`${where}: float`);
    else if (v !== null && typeof v === "object") {
      if (data.isArray(v) && v.length === 0) bad.push(`${where}: empty array`);
      for (const k of Object.keys(v)) walk(v[k], `${where}.${k}`);
    }
  };
  for (const name of files) walk(load(name), name);
  H.assert(bad.length === 0, joined(bad));
});

H.test("the suite covers each rule and concession the core pins", () => {
  const covered = new Set();
  for (const name of files) {
    const f = load(name);
    for (const c of (Array.isArray(f.covers) ? f.covers : [])) covered.add(c);
  }
  const missing = ["Rule 1", "Rule 3", "Rule 4", "K2", "K3", "K4", "K5", "K6", "The outside"].filter((i) => !covered.has(i));
  H.assert(missing.length === 0, `not covered: ${joined(missing)}`);
});

H.test("every fixture passes through the runner", () => {
  const bad = [];
  for (const name of files) {
    const r = run(load(name));
    if (r.passed !== r.total || r.problems.length > 0) {
      bad.push(`${name} ${r.passed}/${r.total}: ${joined(r.problems)}`);
    }
  }
  H.assert(bad.length === 0, joined(bad));
});

const FINAL = /^fixtures: (\d+) files, (\d+)\/(\d+) steps passed$/;
function runCli(args) {
  const r = spawnSync(process.execPath, [RUNNER, ...args], { cwd: ROOT, encoding: "utf8" });
  const lines = `${r.stdout}${r.stderr}`.split("\n").filter((l) => l !== "");
  return { status: r.status, lines, last: lines.at(-1) };
}

H.test("the command line runs the suite and reports it", () => {
  const { status, last, lines } = runCli([]);
  H.assert(status === 0, `the runner exited non-zero: ${String(last)}`);
  const m = FINAL.exec(String(last));
  H.assert(m !== null, `final line: ${String(last)}`);
  H.assertEqual(Number(m[1]), files.length, "files");
  H.assertEqual(m[2], m[3], "steps passed");
  H.assertEqual(lines.filter((l) => l.startsWith("ok    ")).length, files.length, "one ok line per file");
});

H.test("the command line takes a directory", () => {
  const given = runCli([DIR]);
  const dflt = runCli([]);
  H.assertEqual(given.status, 0, "status");
  H.assertEqual(given.last, dflt.last, "the default directory is the project's fixtures/");
  const none = runCli([join(ROOT, "js", "code", "fixtures")]);
  H.assertEqual(none.status, 1, "a directory with no fixture fails");
  H.assertEqual(none.last, "fixtures: 0 files, 0/0 steps passed", "no fixture");
});

// ── the runner fails a wrong expectation ────────────────────────

// a passing fixture, and copies of it with one expectation wrong
function base() {
  return {
    format: 1,
    name: "inline",
    covers: ["K1"],
    gates: [
      { signature: "a", does: { emitOne: { type: "b", data: { v: 1 } } } },
      { signature: "b", does: { emit: [{ type: "c" }, { type: "d" }] } },
      { signature: "d", does: { throw: "d failed" } },
      { signature: "loop", does: { emitOne: { type: "loop" } } },
      { signature: "x", does: { forward: "y" } },
    ],
    steps: [
      { emit: { type: "x", data: { n: 2 } }, expect: { settled: true,
        trace: [{ type: "x", claimed: true, then: [{ type: "y", claimed: false }] }] } },
      { emit: { type: "a" }, expect: { rejected: { code: "SISO_GATE_THREW", eventType: "d", cause: "d failed",
        at: ["a", "b", "d"] } } },
      { emit: { type: "loop" }, expect: { rejected: { code: "SISO_SELF_LOOP", eventType: "loop", at: ["loop"] } } },
      { register: { signature: "x", does: { nothing: true } }, refused: true },
      { sample: { residue: [{ type: "y", data: { n: 2 } }], gateCount: 5 } },
      { take: [{ type: "y", data: { n: 2 } }] },
    ],
  };
}

// stepIndex is 1-based, as the runner names steps
function failsAt(label, mutate, stepIndex, needle) {
  H.test(`the runner fails ${label}`, () => {
    const f = base();
    mutate(f);
    const r = run(f);
    H.assert(r.passed === r.total - 1, `${label}: ${r.passed}/${r.total} passed, expected one failure: ${joined(r.problems)}`);
    H.assert(has(r.problems, `steps[${stepIndex}]`), `${label}: the failure is not at step ${stepIndex}: ${joined(r.problems)}`);
    H.assert(has(r.problems, needle), `${label}: no '${needle}' in ${joined(r.problems)}`);
  });
}

H.test("the inline fixture passes as written", () => {
  const r = run(base());
  H.assert(r.passed === r.total && r.problems.length === 0, `${r.passed}/${r.total}: ${joined(r.problems)}`);
});

failsAt("a wrong residue in a take", (f) => { f.steps[5].take[0].data.n = 3; }, 6, "residue");
failsAt("an extra copy in a sample", (f) => {
  f.steps[4].sample.residue[1] = { type: "y", data: { n: 2 } };
}, 5, "residue");
failsAt("a wrong gate count", (f) => { f.steps[4].sample.gateCount = 4; }, 5, "gateCount");
failsAt("a wrong trace", (f) => { f.steps[0].expect.trace[0].then[0].claimed = true; }, 1, "trace");
failsAt("a wrong code", (f) => { f.steps[2].expect.rejected.code = "SISO_CYCLE"; }, 3, "code");
failsAt('a wrong "at"', (f) => { f.steps[1].expect.rejected.at = ["a", "b"]; }, 2, "at a>b");
failsAt('an "at" that reaches no delivery', (f) => { f.steps[1].expect.rejected.at = ["a", "c", "d"]; }, 2, "0 deliveries");
failsAt("a wrong cause", (f) => { f.steps[1].expect.rejected.cause = "other"; }, 2, "cause");
failsAt("a wrong event type", (f) => { f.steps[2].expect.rejected.eventType = "a"; }, 3, "eventType");
failsAt("a settled expectation on a rejection", (f) => { f.steps[1].expect = "settled"; }, 2, "expected settled");
failsAt("a rejection expected of a settled entry", (f) => {
  f.steps[0].expect = { rejected: { code: "SISO_NOT_EVENT" } };
}, 1, "it settled");
failsAt("a refused registration expected to succeed", (f) => { f.steps[3].refused = false; }, 4, "refused");
failsAt("an accepted registration expected to be refused", (f) => {
  f.steps[3].register.signature = "fresh";
  f.steps[4].sample.gateCount = 6;
}, 4, "expected refused");

H.test("the runner fails a malformed fixture", () => {
  const f = base();
  f.steps[1].expect.rejected.code = "NOPE";
  const r = run(f);
  H.assertEqual(r.passed, 0, "passed");
  H.assert(has(r.problems, "shape"), joined(r.problems));
});

H.test("a gate built from { throw } throws exactly its message", () => {
  const f = base();
  f.steps = [{ emit: { type: "d" }, expect: { rejected: { code: "SISO_GATE_THREW", cause: "d failed", at: ["d"] } } }];
  const r = run(f);
  H.assert(r.passed === 1, joined(r.problems));
});

H.finish("fixtures");
