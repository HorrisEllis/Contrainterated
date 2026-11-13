/*
fixtures runner — the JavaScript runner of the language-agnostic suite
(contract js-fixture-format, js/code/contracts/fixture_format.mjs). Port
of lua/code/fixtures/run.lua.

  node js/code/fixtures/run.mjs [dir]     (from the project root, or
                                           from anywhere)
    runs every <dir>/*.json (default: the project's fixtures/, resolved
    from this script's own directory, the suite every instance shares),
    sorted by name (byte-wise), decoding each with kit/data.mjs, and
    prints one line per fixture, then the final line
      "fixtures: F files, P/T steps passed"
    exit 0 only when every step of every file passes (and there is at
    least one file).

  import { run } from "./fixtures/run.mjs";
  run(fixture, core?) → { name, passed, total, problems: [ … ] }
    one decoded fixture (or one written inline as JS objects), run on a
    fresh `new core.Stream()` with one observer attached. `core` is
    { Event, Gate, Stream } (default: js/code/core/); a test passes
    another to run the same fixture against a stream it controls, or to
    see what the runner hands Event and Gate. Events are built with
    core.Event and gates with core.Gate.

Matching never happens here: shape is fixture_format.problems, residue
is residueMatch, a trace is traceMatch, "at" is recordAt, every batch is
observer_seam.batchProblems, every rejection core_api's
rejectionProblems, and a batch's rejection is judged against the one
thrown by observer_seam.rejectionCopyProblem (the observer's own frozen
plain copy, core 13), never by identity.

Fixture data (event data and "raw" values) is copied fresh for each
call through kit/data.mjs's API (kit-api): an Array is copied as an
Array (data.isArray / data.array), data.null (null) is kept as itself,
and every other object is a fresh plain object whose own keys are the
fixture's ("__proto__" included, defined as an own property, so no
prototype is ever set). No gate shares an object with the fixture or
with another call (js-fixture-format SUBSTRATE). "throw" throws the
string itself; "nothing" returns undefined.
*/

import { readdirSync, readFileSync, realpathSync } from "node:fs";
import { dirname, join, resolve } from "node:path";
import { fileURLToPath } from "node:url";
import * as realCore from "../core/index.mjs";
import * as F from "../contracts/fixture_format.mjs";
import { batchProblems, rejectionCopyProblem } from "../contracts/observer_seam.mjs";
import { rejectionProblems } from "../contracts/core_api.mjs";
import { data } from "../kit/data.mjs";

const hasOwn = (o, k) => Object.prototype.hasOwnProperty.call(o, k);
const isObject = (v) => v !== null && typeof v === "object";

function str(v) {
  try {
    return String(v);
  } catch {
    return Object.prototype.toString.call(v);
  }
}

// ── gates built from test-only behaviours ───────────────────────

// fixture data copied through kit/data.mjs's API: an Array stays an
// Array (data.array), data.null is kept as itself, every other object is
// a fresh plain object (own keys defined, never assigned)
function copy(v) {
  if (v === data.null || !isObject(v)) return v;
  if (data.isArray(v)) {
    const t = [];
    for (let i = 0; i < v.length; i += 1) t.push(copy(v[i]));
    return data.array(t);
  }
  const t = {};
  for (const k of Object.keys(v)) {
    Object.defineProperty(t, k, { value: copy(v[k]), enumerable: true, writable: true, configurable: true });
  }
  return t;
}

function outOf(core, o) {
  if (o.type === undefined && hasOwn(o, "raw")) return copy(o.raw);
  return core.Event(o.type, copy(o.data));
}

function transformOf(core, does) {
  if (does.emit !== undefined) return () => does.emit.map((o) => outOf(core, o));
  if (does.emitOne !== undefined) return () => outOf(core, does.emitOne);
  if (does.forward !== undefined) return (e) => core.Event(does.forward, e.data);
  if (does.nothing !== undefined) return () => undefined;
  if (does.throw !== undefined) {
    return () => {
      throw does.throw; // the string itself: the rejection's cause
    };
  }
  return () => copy(does.raw);
}

const gateOf = (core, g) => core.Gate(g.signature, transformOf(core, g.does));

// ── steps ───────────────────────────────────────────────────────

function addAll(into, label, list) {
  for (const s of list) into.push(`${label}: ${s}`);
}

function check(into, label, [ok, why]) {
  if (!ok) into.push(`${label}: ${str(why)}`);
}

function show(err) {
  if (isObject(err) && err.code !== undefined) return `${str(err.code)} (${str(err.message)})`;
  return str(err);
}

// an entry through the door, against its expectation
function entry(s, batches, value, expect, raw, p) {
  const before = s.sampleHere();
  batches.length = 0;
  let ok = true;
  let err;
  try {
    s.emit(value);
  } catch (e) {
    ok = false;
    err = e;
  }
  if (batches.length !== 1) {
    p.push(`the observer was shown ${batches.length} batches, expected 1`);
    return;
  }
  const b = batches[0];
  addAll(p, "batch", batchProblems(b));
  const x = expect;
  if (x === "settled" || (isObject(x) && x.settled !== undefined)) {
    if (!ok) {
      p.push(`expected settled, got rejected ${show(err)}`);
      return;
    }
    check(p, "batch", [b.outcome === "settled", `outcome ${str(b.outcome)}`]);
  } else {
    if (ok) {
      p.push(`expected rejected ${str(x.rejected.code)}, it settled`);
      return;
    }
    addAll(p, "rejection", rejectionProblems(err, raw ? { noDelivery: true } : undefined));
    if (!isObject(err)) return;
    check(p, "batch", [b.outcome === "rejected", `outcome ${str(b.outcome)}`]);
    const why = rejectionCopyProblem(b.rejection, err);
    check(p, "batch", [why === null, why]);
    const want = x.rejected;
    for (const k of ["code", "eventType", "depth", "cause"]) {
      if (want[k] !== undefined && err[k] !== want[k]) {
        p.push(`${k}: expected ${str(want[k])}, got ${str(err[k])}`);
      }
    }
    if (want.at !== undefined) {
      const [rec, whyAt] = F.recordAt(b.records, want.at);
      const path = want.at.join(">");
      if (rec === null) p.push(`at ${path}: ${whyAt}`);
      else if (err.eventId !== rec.eventId || err.causeId !== rec.causeId) {
        p.push(`at ${path}: the rejection names delivery ${str(err.eventId)}<${str(err.causeId)}, ` +
          `the path reaches ${str(rec.eventId)}<${str(rec.causeId)}`);
      }
    }
    // a rejected propagation commits nothing (K5)
    const after = s.sampleHere();
    check(p, "rejection committed", F.residueMatch(before.residue, after.residue));
  }
  if (isObject(x) && x.trace !== undefined) check(p, "trace", F.traceMatch(x.trace, b.records));
}

function step(core, s, batches, st, p) {
  if (st.register !== undefined) {
    let ok = true;
    let err;
    try {
      s.register(gateOf(core, st.register));
    } catch (e) {
      ok = false;
      err = e;
    }
    if (st.refused && ok) p.push(`register ${str(st.register.signature)}: expected refused, it was accepted`);
    else if (!st.refused && !ok) p.push(`register ${str(st.register.signature)}: refused (${show(err)})`);
  } else if (st.emit !== undefined) {
    entry(s, batches, core.Event(st.emit.type, copy(st.emit.data)), st.expect, false, p);
  } else if (hasOwn(st, "emitRaw")) {
    entry(s, batches, copy(st.emitRaw), st.expect, true, p);
  } else if (st.take !== undefined) {
    check(p, "take", F.residueMatch(st.take, s.takeResidue()));
  } else if (st.sample !== undefined) {
    const smp = s.sampleHere();
    check(p, "sample", F.residueMatch(st.sample.residue, smp.residue));
    check(p, "sample", [smp.gateCount === st.sample.gateCount,
      `gateCount: expected ${str(st.sample.gateCount)}, got ${str(smp.gateCount)}`]);
  }
}

// ── one fixture ─────────────────────────────────────────────────

export function run(fixture, core = realCore) {
  const result = {
    name: isObject(fixture) && fixture.name !== undefined ? fixture.name : "?",
    passed: 0,
    total: 1,
    problems: [],
  };
  const shape = F.problems(fixture);
  if (isObject(fixture) && Array.isArray(fixture.steps) && fixture.steps.length > 0) {
    result.total = fixture.steps.length;
  }
  if (shape.length > 0) {
    addAll(result.problems, "shape", shape);
    return result;
  }
  const s = new core.Stream();
  const batches = [];
  s.attach((b) => { batches.push(b); });
  fixture.gates.forEach((g, i) => {
    try {
      s.register(gateOf(core, g));
    } catch (err) {
      result.problems.push(`gates[${i + 1}]: refused (${show(err)})`);
    }
  });
  if (result.problems.length > 0) return result;
  fixture.steps.forEach((st, i) => {
    const p = [];
    try {
      step(core, s, batches, st, p);
    } catch (err) {
      p.push(`raised ${show(err)}`);
    }
    if (p.length === 0) result.passed += 1;
    addAll(result.problems, `steps[${i + 1}]`, p);
  });
  return result;
}

export default run;

// ── command line ────────────────────────────────────────────────

const HERE = dirname(fileURLToPath(import.meta.url));
// the project's fixtures/: js/code/fixtures/ → the project root
export const DEFAULT_DIR = resolve(HERE, "..", "..", "..", "fixtures");

const byteCompare = (a, b) => Buffer.compare(Buffer.from(a, "utf8"), Buffer.from(b, "utf8"));

function isMain() {
  if (!process.argv[1]) return false;
  try {
    return realpathSync(process.argv[1]) === realpathSync(fileURLToPath(import.meta.url));
  } catch {
    return false;
  }
}

function main() {
  const dir = process.argv[2] === undefined ? DEFAULT_DIR : process.argv[2];
  let files = [];
  try {
    files = readdirSync(dir).filter((name) => name.endsWith(".json"));
  } catch {
    files = [];
  }
  files.sort(byteCompare);

  let passed = 0;
  let total = 0;
  let clean = files.length > 0;
  for (const name of files) {
    let text = "";
    try {
      text = readFileSync(join(dir, name), "utf8");
    } catch {
      text = "";
    }
    const [fixture, why] = data.decode(text);
    const r = why !== null
      ? { name: "?", passed: 0, total: 1, problems: [`decode: ${str(why)}`] }
      : run(fixture);
    passed += r.passed;
    total += r.total;
    const good = r.passed === r.total && r.problems.length === 0;
    clean = clean && good;
    console.log(`${good ? "ok    " : "FAIL  "}${name}  ${r.passed}/${r.total}  ${str(r.name)}`);
    for (const s of r.problems) console.log(`        ${s}`);
  }
  console.log(`fixtures: ${files.length} files, ${passed}/${total} steps passed`);
  process.exitCode = clean ? 0 : 1;
}

if (isMain()) main();
