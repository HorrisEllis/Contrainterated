// fixtures-rejection-copy: the runner (js/code/fixtures/run.mjs) judges
// each batch's rejection with js-observer-seam's rejectionCopyProblem,
// not by identity (core 13, THE REJECTION). Port of
// lua/sisoTests/fixtures-rejection-copy.test.lua.
//   node js/sisoTests/fixtures-rejection-copy.test.mjs  (from the project root)
//
// It must hold whatever the core in the workspace shows its observers, so
// the fixtures run on a test-local wrapper over the real Stream that
// decides what its observers are shown: it collects the real stream's
// batches, lets emit return or throw, then hands each wrapped observer the
// batch with a rejection built from the one thrown (a frozen plain copy,
// the thrown object itself, a copy whose message differs, or a copy left
// writable), and rethrows the same object. The copy it shows is plain, as
// THE REJECTION requires: every object in it has prototype
// Object.prototype (Array.prototype for an array) and is frozen.
//
// Turned into JavaScript's counterpart (js-observer-seam SUBSTRATE):
//   - Lua's read-only proxy (__newindex raising, __metatable = false)
//     becomes Object.freeze on a plain object; "getmetatable answers
//     false" becomes "the prototype is Object.prototype"; "assignment
//     raises" becomes "assignment throws a TypeError" (strict code).
//   - Lua's "readonly:" sentinels (data.null) pass through as themselves:
//     there are none here (JSON null is null, a primitive), so the copy
//     keeps primitives as they are.
//   - The copy's fields are the thrown rejection's own enumerable fields;
//     an object `cause` is copied at every depth through its own
//     string-keyed properties, enumerable or not (an Error's message and
//     stack), as THE REJECTION says. The shared fixtures throw strings,
//     so their causes are primitives.
//   - Needles follow the JavaScript contract's wording: Lua's "very table
//     raised" is "very object raised"; "differs from the one raised at
//     'message'" and "accepts assignment" are the same.
// No case is left out.
import * as H from "../code/harness.mjs";
import { data } from "../code/kit/data.mjs";
import { Event, Gate, Stream } from "../code/core/index.mjs";
import { run } from "../code/fixtures/run.mjs";
import { readdirSync, readFileSync } from "node:fs";
import { dirname, join, resolve } from "node:path";
import { fileURLToPath } from "node:url";

const joined = (list) => list.join("; ");

// ── what an observer may be shown ───────────────────────────────

function define(o, k, value) {
  Object.defineProperty(o, k, { value, enumerable: true, writable: true, configurable: true });
}

// a value's own string-keyed properties at every depth, as plain objects
// (arrays as Arrays); frozen unless `writable`
function deep(v, writable, memo) {
  if (v === null || typeof v !== "object") return v;
  if (memo.has(v)) return memo.get(v);
  const out = Array.isArray(v) ? [] : {};
  memo.set(v, out);
  for (const k of Object.getOwnPropertyNames(v)) {
    if (Array.isArray(v) && k === "length") continue;
    define(out, k, deep(v[k], writable, memo));
  }
  return writable ? out : Object.freeze(out);
}

// a plain copy (observer-seam THE REJECTION): the thrown rejection's own
// enumerable fields; `writable` leaves out the freezing and nothing else
function plainCopy(raised, writable = false) {
  const memo = new Map();
  const out = {};
  for (const k of Object.keys(raised)) define(out, k, deep(raised[k], writable, memo));
  return writable ? out : Object.freeze(out);
}

const shows = {
  copy: (raised) => plainCopy(raised),
  itself: (raised) => raised,
  changedMessage: (raised) => {
    const c = plainCopy(raised, true);
    c.message = `${String(c.message)} (changed)`;
    return Object.freeze(c);
  },
  writable: (raised) => plainCopy(raised, true),
};

// a Stream class over the real one whose observers are shown
// show(thrown) as the rejection; `seen` collects every rejection shown
function wrapped(show, seen) {
  return class Wrapped {
    #real = new Stream();
    #pending = [];
    #observers = [];

    constructor() {
      this.#real.attach((b) => { this.#pending.push(b); });
    }

    register(g) { return this.#real.register(g); }

    takeResidue() { return this.#real.takeResidue(); }

    sampleHere() { return this.#real.sampleHere(); }

    attach(f) { this.#observers.push(f); }

    detach(f) {
      const i = this.#observers.indexOf(f);
      if (i < 0) throw new Error("not attached");
      this.#observers.splice(i, 1);
    }

    emit(e) {
      this.#pending.length = 0;
      let ok = true;
      let err;
      try {
        this.#real.emit(e);
      } catch (x) {
        ok = false;
        err = x;
      }
      for (const b of this.#pending) {
        for (const f of this.#observers) {
          const shown = { outcome: b.outcome, records: b.records };
          if (b.outcome === "rejected") {
            shown.rejection = show(err);
            if (seen) seen.push({ shown: shown.rejection, raised: err });
          }
          f(shown);
        }
      }
      if (!ok) throw err;
    }
  };
}

// ── the suite on disk ───────────────────────────────────────────

const DIR = join(resolve(dirname(fileURLToPath(import.meta.url)), "..", ".."), "fixtures");
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
    if (why !== null) throw new Error(`${name}: ${why}`);
    decoded.set(name, v);
  }
  return decoded.get(name);
}

// the 1-based indices of a fixture's steps that expect a rejection
function rejectedSteps(f) {
  const list = [];
  f.steps.forEach((st, i) => {
    if (st.expect !== null && typeof st.expect === "object" && st.expect.rejected !== undefined) list.push(i + 1);
  });
  return list;
}

const totalRejected = files.reduce((n, name) => n + rejectedSteps(load(name)).length, 0);

// every rejected step fails with `needle`, every other step passes
function failsEachRejection(show, needle) {
  const bad = [];
  for (const name of files) {
    const f = load(name);
    const rej = rejectedSteps(f);
    const r = run(f, { Event, Gate, Stream: wrapped(show) });
    if (r.passed !== r.total - rej.length) {
      bad.push(`${name}: ${r.passed}/${r.total} passed, expected ${r.total - rej.length}`);
    }
    for (const i of rej) {
      const found = r.problems.some((s) => s.startsWith(`steps[${i}]`) && s.includes(needle));
      if (!found) bad.push(`${name} step ${i}: no '${needle}' in ${joined(r.problems)}`);
    }
    for (const s of r.problems) {
      if (!s.includes(needle)) bad.push(`${name}: unrelated problem ${s}`);
    }
  }
  return bad;
}

H.test("the suite has rejections to judge", () => {
  H.assert(files.length >= 15, `fixtures/ holds ${files.length} files`);
  H.assert(totalRejected > 0, "no fixture step expects a rejection");
});

H.test("accepted: every fixture passes when observers are shown a frozen plain copy", () => {
  const seen = [];
  const bad = [];
  for (const name of files) {
    const r = run(load(name), { Event, Gate, Stream: wrapped(shows.copy, seen) });
    if (r.passed !== r.total || r.problems.length > 0) {
      bad.push(`${name} ${r.passed}/${r.total}: ${joined(r.problems)}`);
    }
  }
  H.assert(bad.length === 0, joined(bad));
  // the runner was really shown copies, one per rejected step
  H.assertEqual(seen.length, totalRejected, "rejections shown");
  for (const x of seen) {
    H.assert(x.shown !== x.raised, "the wrapper showed the thrown object");
    H.assertThrows(() => { x.shown.code = "X"; }, "", "the wrapper's copy accepts assignment");
    H.assert(Object.getPrototypeOf(x.shown) === Object.prototype, "the wrapper's copy has a prototype other than Object.prototype");
  }
});

H.test("rejected: a batch carrying the thrown object itself fails each rejected step", () => {
  const bad = failsEachRejection(shows.itself, "very object raised");
  H.assert(bad.length === 0, joined(bad));
});

H.test("rejected: a copy whose message differs fails each rejected step", () => {
  const bad = failsEachRejection(shows.changedMessage, "differs from the one raised at 'message'");
  H.assert(bad.length === 0, joined(bad));
});

H.test("rejected: a copy that accepts assignment fails each rejected step", () => {
  const bad = failsEachRejection(shows.writable, "accepts assignment");
  H.assert(bad.length === 0, joined(bad));
});

H.test("an inline fixture: the copy is accepted, the thrown object refused", () => {
  const f = {
    format: 1, name: "inline", covers: ["K5"],
    gates: [{ signature: "a", does: { emitOne: { type: "b" } } }, { signature: "b", does: { throw: "b failed" } }],
    steps: [{ emit: { type: "a" }, expect: { rejected: { code: "SISO_GATE_THREW", cause: "b failed", at: ["a", "b"] } } }],
  };
  let r = run(f, { Event, Gate, Stream: wrapped(shows.copy) });
  H.assert(r.passed === 1 && r.problems.length === 0, joined(r.problems));
  r = run(f, { Event, Gate, Stream: wrapped(shows.itself) });
  H.assert(r.passed === 0, "the thrown object itself was accepted");
});

H.finish("fixtures-rejection-copy");
