// stream-freeze.test.mjs — read-only events (js-core-api READ-ONLY).
// Port of lua/sisoTests/stream-freeze.test.lua. Runs the core-api
// read-only and doors checks and the observer-seam check on the real core,
// then pins what they leave open, then measures what freezing costs
// (printed, never asserted). The gates below are fixtures for the stream,
// not project gates.
//
// Adapted to the substrate (js-core-api SUBSTRATE), case by case:
//   - The refusal: Lua's "read-only: …" error becomes the language's own
//     TypeError (Object.freeze, strict code).
//   - "reads: indexing, #, ipairs and pairs": length, indexing, for…of,
//     Object.keys, for…in and spread. Lua's "pairs hands out the frozen
//     table as its state" becomes JS's own iteration state: the iterator
//     a frozen array hands out is a fresh object per call, its prototype
//     the realm's %ArrayIteratorPrototype%, holding nothing shared.
//   - "kit-style marks survive": data marks vanish (an array is an
//     Array); getmetatable becomes the prototype (an event's is
//     Object.prototype, an array's Array.prototype, a null prototype is
//     kept).
//   - "a __readonly sentinel keeps its identity": sentinels vanish; JSON
//     null is the primitive null, pinned through a gate, nested, in an
//     array and as the data itself.
//   - "a frozen list a gate returns is judged as a list": the named-key
//     clause cannot be reached through data in JS, since an array with a
//     named key is not plain data: Event refuses it at construction
//     (pinned instead).
//   - "R3 concession: rawset is not refused": no rawset in JS; the
//     substrate's attempts (Reflect.set, Reflect.defineProperty) are
//     refused on a sampled event and reach nothing.
//   - The baseline arm and the cost measurement replace core/freeze.mjs
//     for a second instance of the event and stream modules. Lua swaps
//     package.loaded; JS uses the module loader's own hooks
//     (node:module registerHooks, a test-only resolve/load pair keyed by a
//     `?variant=` query, never touching the real modules). On a Node
//     without registerHooks the two cases print that they were skipped.
// No case is left out.
import * as H from "../code/harness.mjs";
import * as nodeModule from "node:module";
import * as API from "../code/contracts/core_api.mjs";
import * as SEAM from "../code/contracts/observer_seam.mjs";
import { Event, Gate, Stream } from "../code/core/index.mjs";
import { freeze, isFrozen } from "../code/core/freeze.mjs";

const core = { Event, Gate, Stream };
const { test, assert, assertEqual } = H;

const joined = (list) => list.join("; ");
const noProblems = (list, label) => assert(list.length === 0, `${label ? `${label}: ` : ""}${joined(list)}`);
const refuses = (label, fn) => {
  let err;
  try {
    fn();
  } catch (e) {
    err = e;
  }
  assert(err !== undefined, `${label} was accepted`);
  assert(err instanceof TypeError, `${label}: threw ${String(err)}, not the read-only refusal`);
};
const keysOf = (t) => Object.keys(t).sort().join(",");
const tryWrite = (fn) => {
  try {
    fn();
    return true;
  } catch {
    return false;
  }
};
const emitCatch = (s, e) => {
  try {
    s.emit(e);
    return undefined;
  } catch (err) {
    return err;
  }
};

// ── a second instance of the core with core/freeze.mjs replaced ──

const CORE = new URL("../code/core/", import.meta.url).href;
const SCHEME = "siso-variant:";
const variantSources = {
  // no freezing and no copies: the baseline arm
  plain: `
    class Base { constructor(o) { return o; } }
    class Brand extends Base { #event = true; static is(v) { return v !== null && typeof v === "object" && #event in v; } }
    export const isEvent = (v) => Brand.is(v);
    export const isFrozen = () => false;
    export const freeze = (v) => v;
    export const copy = (v) => v;
    export const plain = (v) => v;
    export const newEvent = (type, data) => { const e = { type, data: data === undefined ? {} : data }; new Brand(e); return e; };
  `,
};
let hooked = false;
function variant(name) {
  if (!hooked) {
    if (typeof nodeModule.registerHooks !== "function") return undefined;
    nodeModule.registerHooks({
      resolve(specifier, context, next) {
        if (specifier.startsWith(SCHEME)) return { url: specifier, shortCircuit: true };
        const m = /[?&]variant=([\w-]+)$/.exec(context.parentURL || "");
        if (m && specifier.startsWith("./")) {
          if (specifier === "./freeze.mjs") return { url: `${SCHEME}${m[1]}`, shortCircuit: true };
          const r = next(specifier, context);
          return { ...r, url: `${r.url}?variant=${m[1]}`, shortCircuit: true };
        }
        return next(specifier, context);
      },
      load(url, context, next) {
        if (url.startsWith(SCHEME)) {
          return { format: "module", source: variantSources[url.slice(SCHEME.length)], shortCircuit: true };
        }
        return next(url, context);
      },
    });
    hooked = true;
  }
  return name;
}
const variantCore = async (name) => {
  if (variant(name) === undefined) return undefined;
  const { Event: E } = await import(`${CORE}event.mjs?variant=${name}`);
  const { Stream: S } = await import(`${CORE}stream.mjs?variant=${name}`);
  return { Event: E, Stream: S };
};
const plainArm = await variantCore("plain");

// ── the contracts on the real core ─────────────

test("core-api read-only holds on the core", () => {
  noProblems(API.check.readonly(core));
});

test("core-api doors still hold on the core", () => {
  noProblems(API.check.doors(core));
});

test("observer-seam still holds on the core", () => {
  noProblems(SEAM.check.seam(core));
});

// ── what a gate receives ───────────────────────

test("a gate cannot write its input, at any depth; the attempt rejects as a throw", () => {
  const s = new Stream();
  const seen = {};
  s.register(Gate("fx_probe", (e) => {
    seen.type = tryWrite(() => { e.type = "z"; });
    seen.data = tryWrite(() => { e.data = {}; });
    seen.field = tryWrite(() => { e.data.v = 2; });
    seen.new = tryWrite(() => { e.data.added = 1; });
    seen.nested = tryWrite(() => { e.data.list[0] = 0; });
    seen.deep = tryWrite(() => { e.data.m.inner.x = 0; });
    seen.after = `${e.type}:${e.data.v}:${e.data.list[0]}:${e.data.m.inner.x}`;
    seen.added = e.data.added;
  }));
  s.emit(Event("fx_probe", { v: 1, list: [1, 2], m: { inner: { x: 5 } } }));
  for (const k of ["type", "data", "field", "new", "nested", "deep"]) assertEqual(seen[k], false, `write to ${k} refused`);
  assertEqual(seen.after, "fx_probe:1:1:5", "the input reads back unchanged");
  assertEqual(seen.added, undefined);

  const t = new Stream();
  t.register(Gate("fx_writer", (e) => { e.data.v = 2; }));
  const r = emitCatch(t, Event("fx_writer", { v: 1 }));
  assert(r !== undefined, "a writing gate settled");
  noProblems(API.rejectionProblems(r, { code: "SISO_GATE_THREW", eventId: 1, eventType: "fx_writer" }));
  assert(r.cause instanceof TypeError, `the cause is the refusal: ${String(r.cause)}`);
});

test("reads: indexing, #, ipairs and pairs; the same field twice is the same value", () => {
  const s = new Stream();
  let got;
  s.register(Gate("fx_read", (e) => {
    const d = e.data;
    let sum = 0;
    for (const v of d.list) sum += v;
    let inKeys = 0;
    for (const k in d) if (Object.prototype.hasOwnProperty.call(d, k)) inKeys += 1;
    const it1 = d.list[Symbol.iterator]();
    const it2 = d.list[Symbol.iterator]();
    got = {
      len: d.list.length, second: d.list[1], sum, keys: keysOf(d), eventKeys: keysOf(e), inKeys,
      spread: [...d.list].join(","), same: d.m === d.m && e.data === e.data, missing: d.nope,
      freshIterator: it1 !== it2 && Object.getPrototypeOf(it1) === Object.getPrototypeOf([][Symbol.iterator]()),
      iteratorOwn: Reflect.ownKeys(it1).length,
    };
  }));
  s.emit(Event("fx_read", { list: [3, 4, 5], m: { k: 1 }, s: "x" }));
  assertEqual(got.len, 3);
  assertEqual(got.second, 4);
  assertEqual(got.sum, 12);
  assertEqual(got.keys, "list,m,s");
  assertEqual(got.eventKeys, "data,type");
  assertEqual(got.inKeys, 3);
  assertEqual(got.spread, "3,4,5");
  assert(got.same, "two reads of one object field are ===");
  assertEqual(got.missing, undefined);
  assert(got.freshIterator, "iterating hands out a fresh iterator with the realm's prototype, nothing shared");
  assertEqual(got.iteratorOwn, 0, "the iterator holds no own property");
});

// ── what leaves through the doors ──────────────

test("a sample's and a take's events refuse writes; the stream is unchanged", () => {
  const s = new Stream();
  s.register(Gate("fx_a", (e) => Event("fx_out", { v: e.data.v, list: [1] })));
  s.emit(Event("fx_a", { v: 1 }));
  const r = s.sampleHere().residue[0];
  refuses("a sampled event's type", () => { r.type = "z"; });
  refuses("a sampled event's data", () => { r.data = {}; });
  refuses("a sampled event's field", () => { r.data.v = 9; });
  refuses("a sampled event's nested list", () => { r.data.list[0] = 9; });
  assertEqual(s.sampleHere().residue[0].data.v, 1);
  const t = s.takeResidue()[0];
  // 12.0.1: OWNERSHIP — a sample is the receiver's own copy, so compare values
  assert(t.type === r.type && t.data.v === r.data.v && t.data.list[0] === r.data.list[0] &&
    t.data.list.length === r.data.list.length, "the take hands out the same frozen event the sample showed");
  refuses("a taken event's field", () => { t.data.v = 5; });
  refuses("a taken event's new key", () => { t.data.w = 5; });
  assertEqual(t.data.v, 1);
});

test("an observer's records' data refuse writes, settled and rejected", () => {
  const s = new Stream();
  s.register(Gate("fx_root", () => Event("fx_mid", { k: [2] })));
  s.register(Gate("fx_mid", () => Event("fx_mid")));
  const batches = [];
  s.attach((b) => { batches.push(b); });
  emitCatch(s, Event("fx_root", { k: [1] }));
  s.emit(Event("fx_other", { k: [3] }));
  assertEqual(batches.length, 2);
  assertEqual(batches[0].outcome, "rejected");
  assertEqual(batches[1].outcome, "settled");
  batches.forEach((b, bi) => {
    b.records.forEach((rec, ri) => {
      const where = `batch ${bi + 1} record ${ri + 1}`;
      refuses(`${where} data`, () => { rec.data.k = 0; });
      refuses(`${where} nested data`, () => { rec.data.k[0] = 0; });
    });
  });
  // the batch and record objects are the observer's own and stay writable
  batches[1].records[0].eventType = "relabelled";
  assertEqual(batches[1].records[0].eventType, "relabelled");
});

// ── marks and sentinels ────────────────────────

test("kit-style marks survive: a frozen table answers its original's metatable", () => {
  // JS: no marks; an array is an Array, prototypes are intrinsics or null
  const s = new Stream();
  let inGate;
  s.register(Gate("fx_mark", (e) => {
    inGate = Array.isArray(e.data.list) && Object.getPrototypeOf(e.data.list) === Array.prototype;
    return Event("fx_out", { list: [1, 2], plain: {}, bare: Object.create(null) });
  }));
  s.emit(Event("fx_mark", { list: [] }));
  assert(inGate, "a gate sees its input's array as an Array");
  const r = s.sampleHere().residue[0];
  assert(Array.isArray(r.data.list) && Object.getPrototypeOf(r.data.list) === Array.prototype, "an output's array is an Array");
  assert(Event.is(r), "Event.is recognises a frozen event");
  assertEqual(Object.getPrototypeOf(r), Object.getPrototypeOf(Event("x")), "a frozen event has the event prototype");
  assertEqual(Object.getPrototypeOf(r), Object.prototype, "which is the intrinsic Object.prototype");
  assertEqual(Object.getPrototypeOf(r.data.plain), Object.prototype);
  assertEqual(Object.getPrototypeOf(r.data), Object.prototype);
  assertEqual(Object.getPrototypeOf(r.data.bare), null, "a null prototype is kept through a sample");
});

test("a __readonly sentinel keeps its identity, nested or as the data itself", () => {
  // JS: no sentinel; JSON null is the primitive null
  const s = new Stream();
  let inGate;
  s.register(Gate("fx_null", (e) => {
    inGate = e.data.n;
    return [Event("fx_out", { n: null, list: [null] }), Event("fx_bare", null)];
  }));
  s.emit(Event("fx_null", { n: null }));
  assertEqual(inGate, null, "a gate reads null");
  const byType = {};
  for (const e of s.takeResidue()) byType[e.type] = e;
  assertEqual(byType.fx_out.data.n, null, "nested null");
  assertEqual(byType.fx_out.data.list[0], null, "null in an array");
  assertEqual(byType.fx_bare.data, null, "null as data");
});

// ── never wrapped twice ────────────────────────

test("an event passed on unchanged is not double-wrapped", () => {
  // residue taken and entered again: entry takes the stream's own copy,
  // one frozen layer, not a wrapper of it
  const s = new Stream();
  s.emit(Event("fx_kept", { v: 1 }));
  const taken = s.takeResidue()[0];
  assert(isFrozen(taken), "taken residue is frozen");
  s.emit(taken);
  const again = s.takeResidue()[0];
  assert(again !== taken, "re-entered residue is the stream's own copy");
  assert(isFrozen(again) && Event.is(again), "the copy is a frozen event");
  assertEqual(`${again.type}:${again.data.v}`, "fx_kept:1");
  assert(freeze(again) === again && freeze(again.data) === again.data, "the copy is frozen once: freezing it again gives it back");

  // data passed on by a gate, and an event carried in data and returned
  const t = new Stream();
  let given;
  let carried;
  t.register(Gate("fx_pass", (e) => { given = e.data; return Event("fx_next", e.data); }));
  t.register(Gate("fx_unwrap", (e) => { carried = e.data.inner; return e.data.inner; }));
  t.emit(Event("fx_pass", { v: 1, list: [1] }));
  const out = t.takeResidue()[0];
  assert(out.data === given, "data passed on is the same frozen object");
  t.emit(Event("fx_unwrap", { inner: Event("fx_inner", { w: 2 }) }));
  const inner = t.takeResidue()[0];
  assert(inner === carried, "an event returned from data is the same frozen event");
  assertEqual(inner.data.w, 2);
  assert(freeze(inner) === inner, "freeze of a frozen object is that object");
});

// ── what freezing pins beyond the checks ───────

test("a frozen list a gate returns is judged as a list: carried, or rejected", () => {
  const s = new Stream();
  s.register(Gate("fx_fan", (e) => e.data.out));
  s.emit(Event("fx_fan", { out: [Event("fx_x"), Event("fx_y")] }));
  assertEqual(s.takeResidue().map((e) => e.type).sort().join(","), "fx_x,fx_y", "both members carried, nothing dropped");
  let r = emitCatch(s, Event("fx_fan", { out: [Event("fx_x"), { type: "fake" }] }));
  assert(r !== undefined, "a frozen list with a non-Event member settled");
  noProblems(API.rejectionProblems(r, { code: "SISO_NOT_EVENT", eventId: 1, eventType: "fx_fan" }));
  r = emitCatch(s, Event("fx_fan", { out: [Event("fx_fan")] }));
  assert(r !== undefined, "a frozen list with the gate's own type settled");
  assertEqual(r.code, "SISO_SELF_LOOP");
  // JS: an array with a named key is not plain data, so no event carries one
  let refused;
  try {
    Event("fx_fan", { out: Object.assign([Event("fx_x")], { named: 1 }) });
  } catch (e) {
    refused = e;
  }
  assert(refused instanceof TypeError && refused.message.includes("not plain data"), "Event refuses an array with a named key");
});

test("freezing copies: the emitter's original stays its own and is never annotated", () => {
  // core 13: Event freezes what it builds, so the event itself refuses
  // writes before it enters; the object passed to Event stays the caller's.
  const s = new Stream();
  const original = { v: 1, list: [1] };
  const entered = Event("fx_kept", original);
  refuses("writing a built event's type", () => { entered.type = "changed"; });
  s.emit(entered);
  original.v = 2;
  original.list[0] = 9;
  const r = s.sampleHere().residue[0];
  assertEqual(r.type, "fx_kept");
  assertEqual(r.data.v, 1);
  assertEqual(r.data.list[0], 1);
  assertEqual(keysOf(original), "list,v", "the original carries nothing new");
  assert(!isFrozen(original) && !Object.isFrozen(original), "the caller's object is not frozen");
  assert(isFrozen(entered), "the event is frozen from construction");
});

test("a table reached twice is one frozen table; a cycle terminates (not plain data)", () => {
  const shared = [1];
  const cyclic = { name: "c" };
  cyclic.self = cyclic;
  const s = new Stream();
  s.emit(Event("fx_shared", { a: shared, b: shared }));
  s.emit(Event("fx_cycle", cyclic));
  const byType = {};
  for (const e of s.takeResidue()) byType[e.type] = e;
  assert(byType.fx_shared.data.a === byType.fx_shared.data.b, "a shared object freezes once");
  const c = byType.fx_cycle.data;
  assert(c.self === c, "a cycle freezes to the same cycle");
  assertEqual(c.self.self.name, "c");
  refuses("a write into a frozen cycle", () => { c.self.name = "x"; });
});

test("R3 concession: rawset is not refused (pinned so a change to it is seen)", () => {
  // JS: no rawset; the substrate's own attempts are refused
  const s = new Stream();
  s.emit(Event("fx_kept", { v: 1 }));
  const r = s.sampleHere().residue[0];
  assertEqual(Reflect.set(r.data, "v", 2), false, "Reflect.set is refused");
  assertEqual(Reflect.defineProperty(r.data, "v", { value: 2 }), false, "Reflect.defineProperty is refused");
  assertEqual(r.data.v, 1);
  assertEqual(s.sampleHere().residue[0].data.v, 1);
});

// ── cost (printed, never asserted) ─────────────

const best = (runs, fn) => {
  let min = Infinity;
  for (let i = 0; i < runs; i += 1) {
    const t0 = performance.now();
    fn();
    const dt = (performance.now() - t0) / 1000;
    if (dt < min) min = dt;
  }
  return min;
};

test("the baseline arm freezes nothing, so the comparison measures freezing", () => {
  if (plainArm === undefined) {
    console.log("  (skipped: this Node has no module.registerHooks)");
    return;
  }
  const { Event: PlainEvent, Stream: PlainStream } = plainArm;
  const e = PlainEvent("fx_plain", { v: 1 });
  assert(!isFrozen(e) && !isFrozen(e.data) && !Object.isFrozen(e.data), "the baseline Event does not freeze");
  e.data.v = 2;
  assertEqual(e.data.v, 2, "the baseline event is writable");
  assert(PlainEvent.is(e), "the baseline Event still recognises its events");
  const s = new PlainStream();
  s.emit(e);
  assert(!isFrozen(s.takeResidue()[0]), "the baseline stream does not freeze");
  assert(isFrozen(Event("fx_real")), "the real Event still freezes afterwards");
  assert(PlainStream !== Stream && PlainEvent !== Event, "the baseline is a second instance, the real modules untouched");
});

test("cost of freezing (printed)", () => {
  if (plainArm === undefined) {
    console.log("  (skipped: this Node has no module.registerHooks)");
    return;
  }
  const real = { Event, Stream };
  const lines = [];
  const N = 20000;
  const chain = (arm) => () => {
    const s = new arm.Stream();
    for (let i = 0; i < N; i += 1) {
      s.register(Gate(`step_${i}`, () => (i < N - 1 ? arm.Event(`step_${i + 1}`, {}) : null)));
    }
    s.emit(arm.Event("step_0", {}));
  };
  const W = 10000;
  const HOPS = 10;
  const wide = {};
  for (let i = 1; i <= W; i += 1) wide[`k${i}`] = i;
  const wideChain = (arm, rebuild) => () => {
    const s = new arm.Stream();
    for (let i = 1; i <= HOPS; i += 1) {
      s.register(Gate(`w_${i}`, (e) => {
        const d = rebuild ? { ...e.data } : e.data;
        return arm.Event(`w_${i + 1}`, d);
      }));
    }
    s.emit(arm.Event("w_1", wide));
  };
  const row = (label, a, b) => {
    const ratio = a >= 0.001 ? `  (x${(b / a).toFixed(2)})` : "";
    lines.push(`  ${label.padEnd(58)} without ${a.toFixed(4)}s  with ${b.toFixed(4)}s${ratio}`);
  };
  row("20,000-gate chain (register + emit)", best(3, chain(plainArm)), best(3, chain(real)));
  row("10,000-entry data, 10-gate chain, data passed on", best(3, wideChain(plainArm, false)), best(3, wideChain(real, false)));
  row("10,000-entry data, 10-gate chain, each gate rebuilds data", best(3, wideChain(plainArm, true)), best(3, wideChain(real, true)));
  console.log("freeze cost (performance.now, best of 3):");
  for (const l of lines) console.log(l);
});

H.finish("stream-freeze");
