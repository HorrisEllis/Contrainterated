// stream-stateless.test.mjs — the doors carry no state (js-core-api
// STATELESS THROUGH THE EVENT, OWNERSHIP; js-observer-seam THE
// REJECTION). Port of lua/sisoTests/stream-stateless.test.lua.
// Nothing reachable from an event, a sample, a take or an observer's
// batch may be an object the core holds or shares. Runs the contracts'
// checks on the real core, then walks everything a holder can reach
// (own properties, accessor functions included, and prototypes, short of
// the realm's intrinsics, which are writable globals: SUBSTRATE) and pins
// that it is fresh, frozen and shared with no other stream or
// propagation. The gates below are fixtures for the stream, not project
// gates.
//   node js/sisoTests/stream-stateless.test.mjs
//
// Adapted to the substrate (js-core-api SUBSTRATE), case by case:
//   - Lua's reach (getmetatable, the raw `next` surface, `pairs` and its
//     state) becomes own properties, accessor functions and prototypes.
//   - "events answer \"siso.event\", plain data false, rejections
//     \"siso.rejection\"": getmetatable answers vanish. An event's
//     prototype is Object.prototype and Event.is checks a private brand;
//     a rejection is recognised by Rejection.is (a brand) and its
//     prototype chain short of the intrinsics is frozen.
//   - Lua's rawset probes become the substrate's own attempts:
//     Reflect.set (refused) and a private-field stamp (accepted on a
//     frozen object in V8), the only per-object slot JS code can write on
//     a frozen object.
//   - "pairs hands out the frozen table itself as its state": JS's
//     iteration state is the iterator and entry arrays, fresh per call;
//     the case pins that they hold nothing the core holds.
//   - "a captured key is a function that holds no field; the view it
//     reaches answers false": cannot apply as written: core/freeze.mjs has
//     no key and no views (a brand is a private name no other code can
//     name). JavaScript's own attempt at the same violation is kept
//     instead: a Proxy handed to Event as data, to Event.is and to the
//     entry door is asked for nothing but the data's own string keys, so
//     no brand reaches it, and the event holds a fresh copy, not the
//     Proxy.
//   - "nothing written through a captured key reaches …": the write past
//     the refusal is a private-field stamp; counted the same way.
//   - "a \"readonly:\" sentinel keeps its identity through every door; the
//     core 12 form is not handed to observers": sentinels vanish (JSON
//     null is the primitive null), pinned through every door; the core 12
//     clause becomes a caller's own frozen object in a thrown cause, which
//     an observer's copy never carries.
// No case is left out.
import * as H from "../code/harness.mjs";
import * as API from "../code/contracts/core_api.mjs";
import * as SEAM from "../code/contracts/observer_seam.mjs";
import { Event, Gate, Stream, Rejection } from "../code/core/index.mjs";

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

// reach(roots) → the set of objects reachable from roots by own
// properties (accessor functions included) and prototypes, short of the
// realm's intrinsics.
const reach = (roots) => {
  const seen = new Set();
  const stack = [...roots];
  while (stack.length > 0) {
    const v = stack.pop();
    if (!isObject(v) || seen.has(v) || INTRINSICS.has(v)) continue;
    seen.add(v);
    stack.push(Object.getPrototypeOf(v));
    for (const k of Reflect.ownKeys(v)) {
      const d = Object.getOwnPropertyDescriptor(v, k);
      if ("value" in d) stack.push(d.value);
      else stack.push(d.get, d.set);
    }
  }
  return seen;
};
const overlap = (a, b) => {
  let n = 0;
  for (const t of a) if (b.has(t)) n += 1;
  return n;
};

// a private-field counter: the substrate's one writable slot on a
// frozen object (this suite's own; never stamped on an intrinsic)
class Base { constructor(o) { return o; } }
class Counter extends Base {
  #n = 0;
  static bump(o) {
    try {
      if (!(#n in o)) new Counter(o); // eslint-disable-line no-new
      o.#n += 1;
      return o.#n;
    } catch {
      return 0;
    }
  }
  static read(o) { return isObject(o) && #n in o ? o.#n : 0; }
}

/*
scenario() → everything that left one fresh stream through its doors in
a settled and a rejected propagation: the events its gates were given,
two samples, the take, and an observer's batches (records' data and the
rejection copy). Each call builds its own stream, gates and entries.
*/
const scenario = () => {
  const out = [];
  const keep = (v) => { out.push(v); };
  const s = new Stream();
  s.register(Gate("fx_root", (e) => {
    keep(e);
    return [Event("fx_mid", { list: [e.data.v, 2], m: { k: "a" }, n: null }), Event("fx_leaf", e.data)];
  }));
  s.register(Gate("fx_mid", (e) => { keep(e); return Event("fx_out", { from: e.data }); }));
  s.register(Gate("fx_bad", (e) => {
    keep(e);
    throw Object.assign(["the gate's own"], { nested: [1], n: null });
  }));
  const batches = [];
  s.attach((b) => { batches.push(b); });
  s.emit(Event("fx_root", { v: 1, deep: { a: { b: [1] } } }));
  emitCatch(s, Event("fx_bad", { w: [1] }));
  keep(s.sampleHere());
  keep(s.sampleHere());
  keep(s.takeResidue());
  for (const b of batches) keep(b);
  return [out, batches];
};

// ── the contracts on the real core ─────────────

test("core-api read-only, ownership and doors hold on the core", () => {
  noProblems(API.check.readonly(core), "readonly");
  noProblems(API.check.ownership(core), "ownership");
  noProblems(API.check.doors(core), "doors");
});

test("observer-seam seam and ownership hold on the core", () => {
  noProblems(SEAM.check.seam(core), "seam");
  noProblems(SEAM.check.ownership(core), "ownership");
});

// ── identities answer with strings ─────────────

test("events answer \"siso.event\", plain data false, rejections \"siso.rejection\"", () => {
  // JS: prototypes are intrinsics; identities are private brands
  const e = Event("t", { list: [1], m: {} });
  assertEqual(Object.getPrototypeOf(e), Object.prototype);
  assertEqual(Object.getPrototypeOf(e.data), Object.prototype);
  assertEqual(Object.getPrototypeOf(e.data.list), Array.prototype);
  assertEqual(Object.getPrototypeOf(Event("u").data), Object.prototype, "default data");
  assert(Event.is(e) && !Event.is({ type: "t", data: {} }), "Event.is checks the brand");
  const s = new Stream();
  s.register(Gate("fx_boom", () => { throw new Error("boom"); }));
  const r = emitCatch(s, Event("fx_boom"));
  assert(Rejection.is(r), "Rejection.is checks the brand");
  assert(!Rejection.is(Object.create(Object.getPrototypeOf(r))), "an object made from a rejection's prototype is not one");
  assertEqual(Object.getPrototypeOf(r), Rejection.prototype);
  assertEqual(String(r), r.message, "String of the thrown rejection is still its message");
  let wrote = true;
  try {
    Object.getPrototypeOf(r).x = 1;
  } catch {
    wrote = false;
  }
  assert(!wrote, "nothing writable is a rejection's prototype");
  assertEqual(Object.getPrototypeOf(r).x, undefined);
});

// ── nothing reachable is shared ────────────────

test("two streams share no table through anything that left their doors", () => {
  const a = reach(scenario()[0]);
  const b = reach(scenario()[0]);
  assert(a.size > 20 && b.size > 20, `the walk reached the outputs (${a.size}, ${b.size})`);
  assertEqual(overlap(a, b), 0, "objects reachable from both streams");
});

test("two events, and two propagations of one stream, share no table", () => {
  assertEqual(overlap(reach([Event("t", { a: [1] })]), reach([Event("t", { a: [1] })])), 0, "two events");
  const s = new Stream();
  const given = [];
  s.register(Gate("fx_g", (e) => { given.push(e); return Event("fx_out", { got: e.data }); }));
  const entry = Event("fx_g", { v: [1] });
  s.emit(entry);
  const first = [given[0], s.takeResidue()];
  s.emit(entry);
  const second = [given[1], s.takeResidue()];
  assertEqual(overlap(reach(first), reach(second)), 0, "one entry entered twice");
  assertEqual(overlap(reach([entry]), reach(first)), 0, "the entering code's event and the stream's");
});

test("plain data reaches only immutable values: every reachable table refuses assignment and hands out no table", () => {
  const [out, batches] = scenario();
  const own = new Set(out); // the receiver's own containers: lists and batch objects
  for (const b of batches) {
    own.add(b.records);
    for (const r of b.records) own.add(r);
  }
  for (const v of out) if (isObject(v) && Array.isArray(v.residue)) own.add(v.residue);
  let checked = 0;
  for (const t of reach(out)) {
    if (own.has(t)) continue;
    checked += 1;
    const proto = Object.getPrototypeOf(t);
    assert(proto === null || proto === Object.prototype || (proto === Array.prototype && Array.isArray(t)),
      "a reachable object has a prototype that is not an intrinsic or null");
    assert(typeof t !== "function", "a reachable value is a function");
    let accepted = true;
    try {
      t.__probe = 1;
    } catch {
      accepted = false;
    }
    assert(!accepted, "a reachable object accepts assignment");
  }
  assert(checked > 20, `the walk checked the frozen objects (${checked})`);
});

test("a gate writing (rawset included) into everything it can reach counts nothing across propagations or streams", () => {
  const run = (s) => {
    s.emit(Event("fx_count", { a: { b: [1] }, l: [1, 2] }));
    return s.takeResidue()[0].data.n;
  };
  const counting = () => {
    const s = new Stream();
    s.register(Gate("fx_count", (e) => {
      let most = 0;
      for (const t of reach([e])) {
        Reflect.set(t, "__probe", 1);
        const n = Counter.bump(t);
        if (n > most) most = n;
      }
      return Event("fx_counted", { n: most });
    }));
    return s;
  };
  const s = counting();
  assertEqual(run(s), 1, "first propagation");
  assertEqual(run(s), 1, "a second propagation of the same stream");
  assertEqual(run(counting()), 1, "another stream");
});

// ── iteration state; a brand cannot be captured ──

test("pairs hands out the frozen table itself as its state; the step reads its own proxy", () => {
  // JS: iterators and entry arrays are fresh per call and hold nothing
  // the core holds
  const e = Event("t", { a: 1, l: [{ k: 1 }] });
  const i1 = e.data.l[Symbol.iterator]();
  const i2 = e.data.l[Symbol.iterator]();
  assert(i1 !== i2, "each iteration has its own iterator");
  assertEqual(Reflect.ownKeys(i1).length, 0, "an iterator holds no own property");
  assert(INTRINSICS.has(Object.getPrototypeOf(i1)), "its prototype is the realm's");
  const step = i1.next();
  assert(step.value === e.data.l[0], "the step reads the frozen member itself");
  assert(step !== i2.next(), "each step result is fresh");
  const en1 = Object.entries(e.data);
  const en2 = Object.entries(e.data);
  assert(en1 !== en2 && en1[0] !== en2[0], "entries are fresh arrays");
  const handed = reach([en1, i1]);
  assert(!handed.has(e) && !handed.has(e.data), "iterating the data hands out neither the event nor the data object");
  for (const t of handed) {
    if (t === en1 || en1.includes(t) || t === i1) continue; // the iterator's and the entries' own containers
    assert(Object.isFrozen(t) && reach([e]).has(t), "iteration hands out only the frozen values themselves");
  }
});

test("a captured key is a function that holds no field; the view it reaches answers false", () => {
  // JS: no key to capture. A Proxy handed to the core is asked for its own
  // string keys only (as data) or for nothing at all (Event.is, entry).
  const log = [];
  const target = { v: 1, list: [1] };
  const spy = new Proxy(target, {
    get(t, k, r) { log.push(["get", k]); return Reflect.get(t, k, r); },
    getOwnPropertyDescriptor(t, k) { log.push(["desc", k]); return Reflect.getOwnPropertyDescriptor(t, k); },
    ownKeys(t) { log.push(["ownKeys"]); return Reflect.ownKeys(t); },
    has(t, k) { log.push(["has", k]); return Reflect.has(t, k); },
    getPrototypeOf(t) { log.push(["getPrototypeOf"]); return Reflect.getPrototypeOf(t); },
    set() { log.push(["set"]); return false; },
    defineProperty() { log.push(["defineProperty"]); return false; },
  });
  assertEqual(Event.is(spy), false, "a Proxy is not an event");
  const s = new Stream();
  const r = emitCatch(s, spy);
  assertEqual(r.code, "SISO_NOT_EVENT", "a Proxy is refused at the entry");
  assertEqual(log.length, 0, `Event.is and the entry door asked the Proxy nothing (${JSON.stringify(log)})`);
  const e = Event("t", spy);
  assert(e.data !== spy && e.data.v === 1 && e.data.list !== target.list, "the event holds a fresh copy, not the Proxy");
  const asked = log.filter(([, k]) => k !== undefined).map(([, k]) => k);
  assert(asked.every((k) => typeof k === "string" && Object.prototype.hasOwnProperty.call(target, k)),
    `freezing asked only the data's own string keys (${asked.map(String).join(",")})`);
  assert(!log.some(([op]) => op === "set" || op === "defineProperty"), "nothing was written to the Proxy");
  // 13.1.0: each own property is read once, through its descriptor (the
  // value judged is the value copied); no getter-style read at all
  const descs = log.filter(([op]) => op === "desc").map(([, k]) => k);
  assertEqual(descs.sort().join(","), "list,v", "each own key read once, through its descriptor");
  assertEqual(log.filter(([op]) => op === "get").length, 0, "nothing was read through [[Get]]");
});

test("nothing written through a captured key reaches another propagation, stream, sample or observer", () => {
  // JS: the write past the refusal is a private-field stamp
  const counting = () => {
    const s = new Stream();
    s.register(Gate("fx_count", (e) => {
      let most = 0;
      for (const t of reach([e])) {
        const n = Counter.bump(t);
        if (n > most) most = n;
      }
      return Event("fx_counted", { n: most });
    }));
    return s;
  };
  const run = (s) => {
    s.emit(Event("fx_count", { a: { b: [1] }, l: [1, 2] }));
    return s.takeResidue()[0].data.n;
  };
  const s = counting();
  assertEqual(run(s), 1, "first propagation");
  assertEqual(run(s), 1, "a second propagation of the same stream");
  assertEqual(run(counting()), 1, "another stream");

  // the outside: stamps and write attempts on a sample's and a record's data
  const t = new Stream();
  const batches = [];
  t.attach((b) => { batches.push(b); });
  t.attach((b) => { batches.push(b); });
  t.emit(Event("fx_kept", { v: 1 }));
  const sampled = t.sampleHere().residue[0];
  Reflect.set(sampled.data, "v", 9);
  Counter.bump(sampled.data);
  Reflect.set(batches[0].records[0].data, "v", 8);
  Counter.bump(batches[0].records[0].data);
  const next = t.sampleHere().residue[0];
  assertEqual(next.data.v, 1, "a write on a sample reached the stream");
  assertEqual(Counter.read(next.data), 0, "a stamp on a sample reached the next sample");
  assertEqual(batches[1].records[0].data.v, 1, "a write on one observer's record reached another");
  assertEqual(Counter.read(batches[1].records[0].data), 0, "a stamp on one observer's record reached another");
  const taken = t.takeResidue()[0];
  assertEqual(taken.data.v, 1, "a write reached the residue taken");
  assertEqual(Counter.read(taken.data), 0, "a stamp reached the residue taken");
});

// ── immutable sentinels ────────────────────────

test("a \"readonly:\" sentinel keeps its identity through every door; the core 12 form is not handed to observers", () => {
  // JS: no sentinel; JSON null is the primitive null through every door
  const s = new Stream();
  let inGate;
  s.register(Gate("fx_null", (e) => { inGate = e.data.n; return Event("fx_out", { n: e.data.n, l: [null] }); }));
  s.register(Gate("fx_throw", (e) => { throw { n: e.data.n }; })); // eslint-disable-line no-throw-literal
  const batches = [];
  s.attach((b) => { batches.push(b); });
  s.emit(Event("fx_null", { n: null }));
  const raised = emitCatch(s, Event("fx_throw", { n: null }));
  assertEqual(inGate, null, "a gate is given null, through the entry copy");
  const sampled = s.sampleHere().residue[0];
  assert(sampled.data.n === null && sampled.data.l[0] === null, "a sample keeps it");
  assertEqual(batches[0].records[1].data.n, null, "an observer's record keeps it");
  assertEqual(batches[1].rejection.cause.n, null, "an observer's rejection keeps it");
  assertEqual(raised.cause.n, null, "the host's rejection keeps it");
  const taken = s.takeResidue()[0];
  assertEqual(taken.data.n, null, "a take keeps it");
  assertEqual(Event("fx_bare", null).data, null, "as the data itself");

  // the core 12 clause: a caller's own object in a thrown cause — frozen
  // or not — is never handed to an observer (its copy is the observer's)
  const old = Object.freeze({ k: 1 });
  const t = new Stream();
  t.register(Gate("fx_old", () => { throw { n: old }; })); // eslint-disable-line no-throw-literal
  let seen;
  t.attach((b) => { seen = b.rejection; });
  const r2 = emitCatch(t, Event("fx_old"));
  assert(r2.cause.n === old, "the host's rejection carries what the gate threw");
  assert(seen.cause.n !== old, "the observer's copy does not carry the caller's object");
  assertEqual(Object.getPrototypeOf(seen.cause.n), Object.prototype);
  const why = SEAM.rejectionCopyProblem(seen, r2);
  assert(why === null, String(why));
});

// ── the rejection an observer is shown ─────────

test("an observer's rejection is plain at every depth: own fields only, no metatable, nothing shared", () => {
  class Err {
    constructor() {
      this.msg = "m";
      this.nested = [1, [2]];
    }
    describe() { return "d"; }
    toString() { return "E"; }
  }
  Err.prototype.kind = "io";
  const cause = new Err();
  const s = new Stream();
  s.register(Gate("fx_err", () => { throw cause; }));
  const copies = [];
  s.attach((b) => { copies.push(b.rejection); });
  s.attach((b) => { copies.push(b.rejection); });
  const raised = emitCatch(s, Event("fx_err"));
  assertEqual(copies.length, 2);
  copies.forEach((c, i) => {
    const why = SEAM.rejectionCopyProblem(c, raised);
    assert(why === null, `copy ${i + 1}: ${why}`);
    assertEqual(c.cause.msg, "m", `copy ${i + 1}: the cause's own field`);
    assertEqual(c.cause.kind, undefined, `copy ${i + 1}: an inherited field is not carried`);
    assertEqual(c.cause.describe, undefined, `copy ${i + 1}: a method is not carried`);
    assertEqual(c.cause.nested[1][0], 2);
    assertEqual(c.message, raised.message, `copy ${i + 1}: the message carries the rendering`);
    for (const t of reach([c])) {
      const proto = Object.getPrototypeOf(t);
      assert(proto === Object.prototype || (proto === Array.prototype && Array.isArray(t)),
        `copy ${i + 1}: an object in it has a prototype of its own`);
    }
    assertEqual(overlap(reach([c]), reach([raised])), 0, `copy ${i + 1} shares an object with the thrown rejection`);
  });
  assertEqual(overlap(reach([copies[0]]), reach([copies[1]])), 0, "the two observers' copies share an object");
  assertEqual(String(cause), "E", "the gate's error object is untouched");
  assertEqual(cause.kind, "io");
});

H.finish("stream-stateless");
