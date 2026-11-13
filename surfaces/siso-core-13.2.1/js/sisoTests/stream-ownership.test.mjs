// stream-ownership.test.mjs — samples and observer records are their
// receiver's own frozen copies (js-core-api OWNERSHIP, js-observer-seam
// RECORD data). Port of lua/sisoTests/stream-ownership.test.lua.
// Runs the core-api ownership, doors and read-only checks and the
// observer-seam ownership and seam checks on the real core, then pins what
// they leave open, then measures what the copies cost (printed, never
// asserted). The gates below are fixtures for the stream, not project
// gates.
//
// Adapted to the substrate (js-core-api SUBSTRATE), case by case:
//   - Lua's `rawset` probes become the substrate's own attempts at a write
//     past the refusal: Reflect.set and Reflect.defineProperty (refused
//     by a frozen object) and a private-field stamp (accepted by a frozen
//     object in V8). "Reaches nothing" means: no value changed and no
//     stamp on any other owner's object.
//   - "shares no table" walks every object reachable by own properties
//     (accessor functions included) and prototypes, short of the realm's
//     intrinsics.
//   - "marks and __readonly sentinels go through copies": marks vanish
//     (an array is an Array, a null prototype is kept) and sentinels
//     vanish (JSON null is the primitive null); a copy of a copy shares
//     nothing with it.
//   - "the copy reads what a reader reads: a raw field a gate wrote past
//     the refusal (R3)": JS has no raw field past the refusal; the gate's
//     only write past it is a private-field stamp, which is no field, so a
//     copy reads the same fields and carries no stamp.
//   - Counting and replacing freeze.copy: Lua swaps package.loaded; JS
//     uses the module loader's own hooks (node:module registerHooks, a
//     test-only resolve/load pair keyed by a `?variant=` query) to load a
//     second instance of the stream whose core/freeze.mjs counts copy()
//     or replaces it by the identity. On a Node without registerHooks the
//     two cases print that they were skipped.
// No case is left out.
import * as H from "../code/harness.mjs";
import * as nodeModule from "node:module";
import * as API from "../code/contracts/core_api.mjs";
import * as SEAM from "../code/contracts/observer_seam.mjs";
import { Event, Gate, Stream } from "../code/core/index.mjs";
import { copy, isFrozen } from "../code/core/freeze.mjs";

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
const emitCatch = (s, e) => {
  try {
    s.emit(e);
    return undefined;
  } catch (err) {
    return err;
  }
};
const isObject = (v) => v !== null && (typeof v === "object" || typeof v === "function");

// The realm's intrinsics (read only, never written).
const INTRINSICS = (() => {
  const set = new Set();
  const add = (o) => {
    while (isObject(o) && !set.has(o)) {
      set.add(o);
      o = Object.getPrototypeOf(o);
    }
  };
  for (const name of Object.getOwnPropertyNames(globalThis)) {
    const d = Object.getOwnPropertyDescriptor(globalThis, name);
    if (!d || !("value" in d) || !isObject(d.value)) continue;
    add(d.value);
    if (typeof d.value === "function") {
      const p = Object.getOwnPropertyDescriptor(d.value, "prototype");
      if (p && "value" in p) add(p.value);
    }
  }
  add(Object.getPrototypeOf([][Symbol.iterator]()));
  return set;
})();

// every object reachable from v by own properties and prototypes, short
// of the intrinsics
const objectsOf = (v) => {
  const into = new Set();
  const stack = [v];
  while (stack.length > 0) {
    const x = stack.pop();
    if (!isObject(x) || into.has(x) || INTRINSICS.has(x)) continue;
    into.add(x);
    stack.push(Object.getPrototypeOf(x));
    for (const k of Reflect.ownKeys(x)) {
      const d = Object.getOwnPropertyDescriptor(x, k);
      if ("value" in d) stack.push(d.value);
      else stack.push(d.get, d.set);
    }
  }
  return into;
};
// the number of objects reachable from both a and b
const shared = (a, b) => {
  const sa = objectsOf(a);
  let n = 0;
  for (const t of objectsOf(b)) if (sa.has(t)) n += 1;
  return n;
};

// this suite's own private-field stamp: the substrate's attempt at rawset
class Base { constructor(o) { return o; } }
class Stamp extends Base {
  #mark = true;
  static put(o) {
    try {
      if (!(#mark in o)) new Stamp(o); // eslint-disable-line no-new
    } catch {
      // a substrate that refuses the stamp
    }
  }
  static on(o) { return isObject(o) && #mark in o; }
}
// the substrate's attempts at a write past the refusal
const attempt = (o, k, v) => {
  Reflect.set(o, k, v);
  Reflect.defineProperty(o, typeof k === "symbol" ? k : `${String(k)}Extra`, { value: v, enumerable: true });
  Stamp.put(o);
};
const stamped = (v) => [...objectsOf(v)].some((o) => Stamp.on(o));

// ── a second instance of the stream with copy() counted or replaced ──

const CORE = new URL("../code/core/", import.meta.url).href;
const FREEZE = `${CORE}freeze.mjs`;
const SCHEME = "siso-variant:";
const variantSources = {
  counted: `
    import * as real from ${JSON.stringify(FREEZE)};
    export const counter = { calls: 0 };
    export const copy = (v, memo) => { counter.calls += 1; return real.copy(v, memo); };
    export const { freeze, plain, isFrozen, isEvent, newEvent } = real;
  `,
  sharing: `
    import * as real from ${JSON.stringify(FREEZE)};
    export const copy = (v) => v;
    export const { freeze, plain, isFrozen, isEvent, newEvent } = real;
  `,
};
let hooked = false;
const hook = () => {
  if (hooked) return true;
  if (typeof nodeModule.registerHooks !== "function") return false;
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
  return true;
};
const streamWith = async (name) => {
  if (!hook()) return undefined;
  const { Stream: S } = await import(`${CORE}stream.mjs?variant=${name}`);
  const freezeVariant = await import(`${SCHEME}${name}`);
  return { Stream: S, freeze: freezeVariant };
};
const counted = await streamWith("counted");
const sharing = await streamWith("sharing");

// ── the contracts on the real core ─────────────

test("core-api ownership holds on the core", () => {
  noProblems(API.check.ownership(core));
});

test("core-api doors and read-only still hold on the core", () => {
  noProblems(API.check.doors(core), "doors");
  noProblems(API.check.readonly(core), "readonly");
});

test("observer-seam ownership and seam hold on the core", () => {
  noProblems(SEAM.check.ownership(core), "ownership");
  noProblems(SEAM.check.seam(core), "seam");
});

// ── samples ────────────────────────────────────

const deep = () => ({ v: 1, a: { b: { c: { d: { e: { leaf: 1, list: [1, 2, 3] } } } } } });

test("deep nesting: a rawset at any depth of a sample reaches nothing; no proxy is shared", () => {
  const s = new Stream();
  s.emit(Event("fx_deep", deep()));
  const a = s.sampleHere().residue[0];
  const e = a.data.a.b.c.d.e;
  attempt(e, "leaf", 9);
  attempt(e.list, 1, 9);
  attempt(e, "added", 1);
  attempt(a.data.a.b, "c", "gone");
  attempt(a, "data", {});
  const b = s.sampleHere().residue[0];
  const eb = b.data.a.b.c.d.e;
  assertEqual(eb.leaf, 1, "deep field");
  assertEqual(eb.list[1], 2, "deep list member");
  assertEqual(eb.added, undefined, "deep added key");
  assertEqual(eb.list.length, 3);
  assert(!stamped(b), "a stamp on a sample reached the next sample");
  const t = s.takeResidue()[0];
  assertEqual(t.data.a.b.c.d.e.leaf, 1, "the taken residue is untouched");
  assert(!stamped(t), "a stamp on a sample reached the residue");
  assertEqual(shared(b, t), 0, "a sample shares no object with the residue it copies");
  refuses("a deep write into a copy", () => { eb.list[0] = 0; });
});

test("copies read like the original: indexing, #, ipairs, pairs; the same field twice is the same value", () => {
  const s = new Stream();
  s.emit(Event("fx_read", { list: [3, 4, 5], m: { k: 1 }, s: "x" }));
  const r = s.sampleHere().residue[0];
  assert(Event.is(r), "Event.is recognises a copied event");
  assert(isFrozen(r) && isFrozen(r.data.m) && Object.isFrozen(r.data.m), "a copy is frozen");
  assert(r.data === r.data && r.data.m === r.data.m, "two reads give the same object");
  assertEqual(r.data.list.length, 3);
  let sum = 0;
  for (const v of r.data.list) sum += v;
  assertEqual(sum, 12);
  assertEqual(Object.keys(r.data).sort().join(","), "list,m,s");
  refuses("a copied event's type", () => { r.type = "z"; });
  refuses("a copied event's field", () => { r.data.s = "y"; });
  refuses("a copied event's new key", () => { r.data.w = 1; });
});

test("marks and __readonly sentinels go through copies; no metatable stays none", () => {
  // JS: arrays stay Arrays, null stays null, a null prototype is kept
  const bare = Object.create(null);
  bare.k = 1;
  const s = new Stream();
  s.register(Gate("fx_mark", () => [Event("fx_out", { list: [1, null], n: null, plain: {}, bare }), Event("fx_bare", null)]));
  s.emit(Event("fx_mark"));
  const byType = {};
  for (const e of s.sampleHere().residue) byType[e.type] = e;
  const d = byType.fx_out.data;
  assert(Array.isArray(d.list) && Object.getPrototypeOf(d.list) === Array.prototype, "an array survives a copy as an Array");
  assertEqual(Object.getPrototypeOf(byType.fx_out), Object.getPrototypeOf(Event("x")), "a copied event has the event prototype");
  assertEqual(Object.getPrototypeOf(d), Object.prototype, "copied plain data has the intrinsic prototype");
  assertEqual(Object.getPrototypeOf(d.plain), Object.prototype);
  assertEqual(Object.getPrototypeOf(d.bare), null, "a null prototype is kept");
  assertEqual(d.n, null, "nested null");
  assertEqual(d.list[1], null, "null in an array");
  assertEqual(byType.fx_bare.data, null, "null as data");
  // and through copy directly, a copy of a copy
  const again = copy(d);
  assert(Array.isArray(again.list) && Object.getPrototypeOf(again.bare) === null, "a copy of a copy keeps the shapes");
  assertEqual(again.n, null);
  assertEqual(shared(again, d), 0, "a copy of a copy shares nothing with it");
});

test("two samples are independent", () => {
  const s = new Stream();
  s.emit(Event("fx_x", { v: 1, n: [1] }));
  s.emit(Event("fx_y", { w: { k: "a" } }));
  const a = s.sampleHere().residue;
  const b = s.sampleHere().residue;
  assertEqual(a.length, 2);
  for (let i = 0; i < 2; i += 1) {
    assert(a[i] !== b[i], `event ${i + 1} is a separate copy in each sample`);
    assertEqual(shared(a[i], b[i]), 0, `event ${i + 1}: the samples share no object`);
  }
  for (const e of a) {
    attempt(e, "type", "z");
    for (const k of Object.keys(e.data)) attempt(e.data, k, "changed");
  }
  assertEqual(b.map((e) => e.type).sort().join(","), "fx_x,fx_y", "the other sample's events");
  assert(!b.some(stamped), "a stamp on one sample reached the other");
  for (const e of s.sampleHere().residue) {
    if (e.type === "fx_x") assertEqual(e.data.n[0], 1);
    else assertEqual(e.data.w.k, "a");
  }
});

test("sharing inside one sample is kept, never with the stream; a cycle terminates", () => {
  const s = new Stream();
  s.register(Gate("fx_fan", (e) => [Event("fx_l", e.data), Event("fx_r", e.data)]));
  s.emit(Event("fx_fan", { list: [1] }));
  const cyclic = { name: "c" };
  cyclic.self = cyclic;
  s.emit(Event("fx_cycle", cyclic));
  const byType = {};
  for (const e of s.sampleHere().residue) byType[e.type] = e;
  assert(byType.fx_l.data === byType.fx_r.data, "data shared in the residue is one copy in one sample");
  const c = byType.fx_cycle.data;
  assert(c.self === c, "a cycle copies to the same cycle");
  assertEqual(c.self.self.name, "c");
  const held = {};
  for (const e of s.takeResidue()) held[e.type] = e;
  assert(byType.fx_l.data !== held.fx_l.data, "the sample's data is not the stream's");
  assertEqual(shared(byType.fx_cycle, held.fx_cycle), 0);
});

test("take hands over the stream's own events; entering them again takes a fresh copy", () => {
  // 13.0.0 (core-api OWNERSHIP): entry copies, so the taker's handle never
  // reaches the residue, taken residue entered again included
  const s = new Stream();
  s.emit(Event("fx_kept", { v: 1, n: [1] }));
  const before = s.sampleHere().residue[0];
  const taken = s.takeResidue()[0];
  assertEqual(s.sampleHere().residue.length, 0, "the stream holds none of what it handed over");
  s.emit(taken);
  attempt(taken.data, "v", 7);
  attempt(taken.data.n, 0, 9);
  const again = s.takeResidue()[0];
  assert(again !== taken, "re-entered residue is the stream's own copy");
  assertEqual(shared(again, taken), 0, "the copy shares no object with the taker's event");
  assertEqual(again.data.v, 1, "an attempt on the taker's event did not reach the residue");
  assertEqual(again.data.n[0], 1);
  assert(!stamped(again), "a stamp on the taker's event reached the residue");
  assertEqual(before.data.v, 1);
});

test("the entering code's own event never reaches the residue, at any depth", () => {
  const held = Event("fx_held", deep());
  const s = new Stream();
  s.emit(held);
  attempt(held, "type", "z");
  attempt(held.data.a.b.c.d.e, "leaf", 9);
  attempt(held.data.a.b.c.d.e.list, 0, 9);
  const r = s.takeResidue()[0];
  assertEqual(r.type, "fx_held");
  assertEqual(r.data.a.b.c.d.e.leaf, 1);
  assertEqual(r.data.a.b.c.d.e.list[0], 1);
  assert(!stamped(r), "a stamp on the entering code's event reached the residue");
  assertEqual(shared(r, held), 0, "the residue shares no object with the entering code's event");
});

// ── observers ──────────────────────────────────

test("each observer's records carry its own copies; within one observer data passed on is one copy", () => {
  const s = new Stream();
  let given;
  s.register(Gate("fx_root", (e) => { given = e.data; return Event("fx_next", e.data); }));
  let A;
  let B;
  s.attach((b) => { A = b; });
  s.attach((b) => { B = b; });
  s.emit(Event("fx_root", { v: 1, n: [1] }));
  const held = s.sampleHere().residue[0];
  const taken = s.takeResidue()[0];
  assert(taken.data === given, "inside the propagation the data is shared, and the residue holds it");
  assert(A.records[0].data !== given, "a record is not the data the gate received");
  assertEqual(shared(A.records[0].data, given), 0, "a record shares no object with the stream's data");
  assertEqual(shared(A.records[0].data, B.records[0].data), 0, "two observers share no object");
  assert(A.records[0].data === A.records[1].data, "data passed on is one copy for one observer");
  assertEqual(A.records[1].data.n[0], 1);
  assertEqual(held.data.v, 1);
  refuses("an observer's record data", () => { A.records[0].data.v = 2; });
});

test("a rejected batch's records are copies too", () => {
  for (const writerFirst of [true, false]) {
    const s = new Stream();
    let given;
    s.register(Gate("fx_root", (e) => { given = e.data; return Event("fx_mid", e.data); }));
    s.register(Gate("fx_mid", () => { throw new Error("boom"); }));
    let seen;
    const writer = (b) => {
      for (const r of b.records) {
        attempt(r.data, "v", 99);
        attempt(r.data.n, 0, 9);
      }
    };
    const reader = (b) => { seen = b; };
    if (writerFirst) { s.attach(writer); s.attach(reader); } else { s.attach(reader); s.attach(writer); }
    const r = emitCatch(s, Event("fx_root", { v: 1, n: [1] }));
    assert(r !== undefined && r.code === "SISO_GATE_THREW", "the propagation is rejected");
    assertEqual(seen.outcome, "rejected");
    assertEqual(seen.records.length, 2);
    seen.records.forEach((rec, i) => {
      assertEqual(rec.data.v, 1, `record ${i + 1}: another observer's attempt did not reach it`);
      assertEqual(rec.data.n[0], 1);
      assertEqual(shared(rec.data, given), 0, `record ${i + 1} shares no object with the stream's data`);
    });
    assert(!stamped(given), "the stream's frozen data carries no stamp");
  }
});

test("the copy reads what a reader reads: a raw field a gate wrote past the refusal (R3)", () => {
  // JS: the gate's only write past the refusal is a private-field stamp,
  // which is no field: copies read the same fields and carry no stamp
  const s = new Stream();
  let d;
  s.register(Gate("fx_raw", (e) => {
    d = e.data;
    attempt(d, "v", 2);
    return Event("fx_out", d);
  }));
  let b;
  s.attach((batch) => { b = batch; });
  s.emit(Event("fx_raw", { v: 1 }));
  assert(Stamp.on(d), "inside the propagation the gate's stamp is on its input (gate-held, convention)");
  assertEqual(b.records[1].data.v, 1, "a record reads the field as it is");
  assert(!Stamp.on(b.records[1].data), "a record carries no stamp");
  const r = s.sampleHere().residue[0];
  assertEqual(r.data.v, 1, "a sample reads the field as it is");
  assert(!Stamp.on(r.data), "a sample carries no stamp");
  refuses("that field in a copy", () => { r.data.v = 3; });
});

// ── copying only when needed ───────────────────

test("no observer means no copying past the entry; one copy per record per observer, one per sampled event", () => {
  // 13.0.0: entry takes the stream's own copy, one copy per emit
  if (counted === undefined) {
    console.log("  (skipped: this Node has no module.registerHooks)");
    return;
  }
  const { Stream: Counted, freeze: { counter } } = counted;
  counter.calls = 0;
  const chain = () => {
    const s = new Counted();
    s.register(Gate("fx_a", () => [Event("fx_b", { k: 1 }), Event("fx_c")]));
    s.register(Gate("fx_b", () => Event("fx_d")));
    return s;
  };
  const s = chain();
  const r = emitCatch(s, Event("fx_a", { k: 0 }));
  assert(r === undefined, String(r));
  const t = chain();
  t.register(Gate("fx_d", () => { throw new Error("boom"); }));
  emitCatch(t, Event("fx_a"));
  assertEqual(counter.calls, 2, "no observer attached, settled or rejected: only each entry is copied");
  counter.calls = 0;
  emitCatch(t, "not an event");
  assertEqual(counter.calls, 0, "a refused entry copies nothing");
  const u = chain();
  u.attach(() => {});
  u.attach(() => {});
  u.emit(Event("fx_a"));
  assertEqual(counter.calls, 1 + 2 * 4, "the entry, then two observers, four records each");
  const v = chain();
  v.emit(Event("fx_a"));
  counter.calls = 0;
  const n = v.sampleHere().residue.length;
  assertEqual(n, 2);
  assertEqual(counter.calls, n, "a sample copies each residue event once");
  counter.calls = 0;
  v.takeResidue();
  assertEqual(counter.calls, 0, "a take copies nothing");
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

test("cost of the copies (printed)", () => {
  if (sharing === undefined) {
    console.log("  (skipped: this Node has no module.registerHooks)");
    return;
  }
  const Sharing = sharing.Stream;
  const lines = [];
  const row = (label, a, b) => {
    const ratio = a >= 0.0001 ? `  (x${(b / a).toFixed(2)})` : "";
    lines.push(`  ${label.padEnd(52)} sharing ${a.toFixed(4)}s  copies ${b.toFixed(4)}s${ratio}`);
  };
  // sampleHere on 2,000 residue events, each with 10-entry data
  const filled = (S) => {
    const s = new S();
    for (let i = 1; i <= 2000; i += 1) {
      const d = {};
      for (let j = 1; j <= 10; j += 1) d[`k${j}`] = i * 10 + j;
      s.emit(Event(`fx_r${i}`, d));
    }
    return s;
  };
  const sp = filled(Sharing);
  const sc = filled(Stream);
  row("sampleHere, 2,000 events x 10-entry data", best(3, () => sp.sampleHere()), best(3, () => sc.sampleHere()));
  // a 20,000-gate chain with one observer attached
  const N = 20000;
  const chain = (S) => () => {
    const s = new S();
    for (let i = 0; i < N; i += 1) s.register(Gate(`step_${i}`, () => (i < N - 1 ? Event(`step_${i + 1}`, {}) : null)));
    s.attach(() => {});
    s.emit(Event("step_0", {}));
  };
  row("20,000-gate chain, one observer (register + emit)", best(3, chain(Sharing)), best(3, chain(Stream)));
  console.log("ownership cost (performance.now, best of 3):");
  for (const l of lines) console.log(l);
});

H.finish("stream-ownership");
