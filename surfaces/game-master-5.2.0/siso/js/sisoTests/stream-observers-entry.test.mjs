// stream-observers-entry.test.mjs — every observer's batch is built
// before any observer is called (js-observer-seam ISOLATION); an entry
// the stream cannot copy is refused as SISO_NOT_EVENT, never thrown raw
// (js-core-api: nothing else is thrown); an observer's plain rejection
// copy keeps arrays as Arrays (THE REJECTION). Port of
// lua/sisoTests/stream-observers-entry.test.lua. The gates below are
// fixtures for the stream, not project gates.
//   node js/sisoTests/stream-observers-entry.test.mjs
//
// Adapted to the substrate (js-core-api / js-observer-seam SUBSTRATE),
// case by case:
//   - Lua's rawset probes become the substrate's own attempts
//     (Reflect.set, Reflect.defineProperty, a private-field stamp).
//   - "a batch that cannot be built": Lua's cause whose __pairs raises is
//     a Proxy whose ownKeys trap throws.
//   - "an entry Event accepts but the stream cannot copy": Lua's depths
//     (58,000 to 62,000, where Lua's recursive copy overflows) become the
//     contract's JS depths, 1,000 to 100,000. The JS copies are iterative,
//     so on this substrate there is no window; the case pins that any
//     refusal is a rejection and prints when none occurs, as the Lua case
//     does.
//   - "an observer's rejection keeps string marks": marks and kit.data
//     vanish in JS (an array is an Array, JSON null is null); the case
//     pins that arrays in a cause copy to Arrays, null stays null and the
//     canonical rendering (JSON.stringify standing in for kit.data.encode,
//     which this boundary does not import) is unchanged.
//   - "hands out no table through getmetatable": every object in the copy
//     has prototype Object.prototype, Array.prototype (an Array) or null.
// No case is left out.
import * as H from "../code/harness.mjs";
import * as API from "../code/contracts/core_api.mjs";
import * as SEAM from "../code/contracts/observer_seam.mjs";
import { Event, Gate, Stream } from "../code/core/index.mjs";

const core = { Event, Gate, Stream };
const { test, assert, assertEqual } = H;

const noProblems = (list, label) => assert(list.length === 0, `${label ? `${label}: ` : ""}${list.join("; ")}`);
const isObject = (v) => v !== null && (typeof v === "object" || typeof v === "function");
const emitCatch = (s, e) => {
  try {
    s.emit(e);
    return undefined;
  } catch (err) {
    return err;
  }
};
class Base { constructor(o) { return o; } }
class Stamp extends Base {
  #mark = true;
  static put(o) {
    try {
      if (!(#mark in o)) new Stamp(o); // eslint-disable-line no-new
    } catch {
      // refused
    }
  }
  static on(o) { return isObject(o) && #mark in o; }
}
const attempt = (o, k, v) => {
  Reflect.set(o, k, v);
  Reflect.defineProperty(o, k, { value: v });
  Stamp.put(o);
};

const deepObject = (depth) => {
  const t = {};
  let cur = t;
  for (let i = 0; i < depth; i += 1) {
    cur.x = {};
    cur = cur.x;
  }
  return t;
};

// ── the contracts on the real core ──────────────

test("core-api: ownership holds on the core (entry copy, deep entries)", () => {
  noProblems(API.check.ownership(core));
});

test("observer-seam: ownership and seam hold on the core", () => {
  noProblems(SEAM.check.ownership(core), "ownership");
  noProblems(SEAM.check.seam(core), "seam");
});

// ── batches built before any observer is called ──

test("an observer that takes the residue and writes to it reaches no other observer (both orders)", () => {
  for (const takerFirst of [true, false]) {
    const s = new Stream();
    s.register(Gate("a", (e) => Event("b", { v: e.data.v, list: [1] })));
    let seen;
    const taker = () => {
      for (const e of s.takeResidue()) {
        attempt(e.data, "v", "TAKER WROTE");
        attempt(e.data.list, 0, "TAKER WROTE");
      }
    };
    const reader = (b) => { seen = b; };
    if (takerFirst) { s.attach(taker); s.attach(reader); } else { s.attach(reader); s.attach(taker); }
    s.emit(Event("a", { v: 1 }));
    const label = takerFirst ? "taker first" : "reader first";
    assertEqual(seen.records[0].data.v, 1, `${label}: entry record`);
    assertEqual(seen.records[1].data.v, 1, `${label}: settled record`);
    assertEqual(seen.records[1].data.list[0], 1, `${label}: nested data`);
    assert(!Stamp.on(seen.records[1].data) && !Stamp.on(seen.records[1].data.list), `${label}: a stamp reached the record`);
  }
});

test("an observer that raises still leaves every other observer its batch", () => {
  const s = new Stream();
  const got = [];
  s.attach(() => { throw new Error("first observer fails"); });
  s.attach((b) => { got.push(b.outcome); });
  s.emit(Event("x"));
  assertEqual(got.join(","), "settled");
});

test("a batch that cannot be built fails its observer alone: the rejection is raised all the same", () => {
  const s = new Stream();
  // a cause whose own keys cannot be read: the plain copy cannot be built
  s.register(Gate("a", () => { throw new Proxy({}, { ownKeys() { throw new Error("cannot be read"); } }); }));
  let calls = 0;
  s.attach(() => { calls += 1; });
  const r = emitCatch(s, Event("a"));
  assert(r !== undefined, "the gate's throw did not reject");
  assertEqual(r.code, "SISO_GATE_THREW");
  assertEqual(calls, 0, "the observer whose batch could not be built was called");
});

// ── an entry too deep to copy ───────────────────

test("an entry Event accepts but the stream cannot copy is SISO_NOT_EVENT, no delivery, nothing committed", () => {
  let found = false;
  for (const depth of [1000, 10000, 50000, 100000]) {
    let built;
    try {
      built = Event("deep", deepObject(depth));
    } catch {
      continue;
    }
    const s = new Stream();
    s.emit(Event("earlier"));
    let seen;
    s.attach((b) => { seen = b; });
    const r = emitCatch(s, built);
    if (r !== undefined) {
      assert(isObject(r), `${depth}: emit threw ${String(r).slice(0, 80)}, not a rejection`);
      noProblems(API.rejectionProblems(r, { code: "SISO_NOT_EVENT", noDelivery: true }), `${depth}`);
      assertEqual(s.sampleHere().residue.length, 1, `${depth}: a refused entry committed residue`);
      assert(seen && seen.outcome === "rejected" && seen.records.length === 0, `${depth}: the observer's batch`);
      found = true;
    } else {
      assertEqual(seen.outcome, "settled", `${depth}: the observer's batch`);
      assertEqual(s.takeResidue().length, 2, `${depth}: the deep entry settled as residue`);
    }
  }
  // the JS copies are iterative: no depth Event accepts overflows them
  if (!found) console.log("  (no depth in the window overflowed the copy on this substrate)");
});

test("a shallow entry is unaffected: copied, settled, the stream usable after a refused deep one", () => {
  const s = new Stream();
  let deep;
  try {
    deep = Event("deep", deepObject(100000));
  } catch (e) {
    deep = e;
  }
  emitCatch(s, deep);
  s.emit(Event("fine", { v: 1 }));
  const r = s.takeResidue();
  assertEqual(r[r.length - 1].type, "fine");
  assertEqual(r[r.length - 1].data.v, 1);
});

// ── plain rejection copies keep string marks ────

test("an observer's rejection keeps string marks: an array in a cause stays an array", () => {
  const s = new Stream();
  s.register(Gate("a", () => { throw { rows: [], list: [1, 2], n: null }; })); // eslint-disable-line no-throw-literal
  let shown;
  s.attach((b) => { shown = b.rejection; });
  const raised = emitCatch(s, Event("a"));
  const why = SEAM.rejectionCopyProblem(shown, raised);
  assert(why === null, String(why));
  assert(Array.isArray(shown.cause.rows) && shown.cause.rows.length === 0, "an empty array in the cause is not an Array");
  assert(Array.isArray(shown.cause.list), "an array in the cause is not an Array");
  assertEqual(shown.cause.n, null, "null in the cause is not null");
  assertEqual(JSON.stringify(shown.cause), JSON.stringify(raised.cause));
  assertEqual(Object.getPrototypeOf(shown), Object.prototype, "the copy itself is a plain object");
});

test("an observer's rejection hands out no table through getmetatable, at any depth", () => {
  class Err { constructor(inner) { this.inner = inner; } }
  Err.prototype.kind = "io";
  const s = new Stream();
  s.register(Gate("a", () => { throw new Err(new Err(undefined)); }));
  let shown;
  s.attach((b) => { shown = b.rejection; });
  emitCatch(s, Event("a"));
  const seen = new Set();
  const walk = (v) => {
    if (v === null || typeof v !== "object" || seen.has(v)) return;
    seen.add(v);
    const proto = Object.getPrototypeOf(v);
    assert(proto === null || proto === Object.prototype || (proto === Array.prototype && Array.isArray(v)),
      "an object in the copy has a prototype of its own");
    for (const k of Reflect.ownKeys(v)) walk(v[k]);
  };
  walk(shown);
  assert(seen.size >= 3, "the walk reached the copy, its cause and the nested cause");
});

H.finish("stream-observers-entry");
